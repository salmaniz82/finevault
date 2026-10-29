<?php

namespace Tests\Feature;

use App\Models\PortalRecord;
use App\Models\User;
use App\Services\DocusignDemo;
use Database\Seeders\PrototypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SigningDemoTest extends TestCase
{
    use RefreshDatabase;

    private const ENVELOPE = 'a0000000-0000-0000-0000-000000000001';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Storage::fake('local');
        $this->seed(PrototypeSeeder::class);
        config(['docusign.integration_key' => 'test-key', 'docusign.user_id' => 'test-user', 'docusign.account_id' => 'test-account']);
        Cache::put('docusign-demo-token-'.hash('sha256', 'test-keytest-user'), 'fake-token', 3000);
        // A file is required for readiness; HTTP calls use the cached fake token.
        config(['docusign.private_key_path' => __FILE__]);
    }

    private function actor(string $role): User
    {
        return User::where('email', $role.'@fineworth.test')->firstOrFail();
    }

    private function configureEphemeralSigningKey(): string
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'config' => base_path('tests/Fixtures/openssl.cnf')]);
        $this->assertNotFalse($key);
        openssl_pkey_export($key, $private, null, ['config' => base_path('tests/Fixtures/openssl.cnf')]);
        Storage::disk('local')->put('test-signing.key', $private);
        config(['docusign.private_key_path' => Storage::disk('local')->path('test-signing.key')]);
        Cache::forget('docusign-demo-token-'.hash('sha256', 'test-keytest-user'));
        return openssl_pkey_get_details($key)['key'];
    }

    public function test_jwt_is_signed_correctly_and_authentication_checks_the_configured_account(): void
    {
        $public = $this->configureEphemeralSigningKey();
        Http::fake([
            '*/oauth/token' => Http::response(['access_token' => 'fresh-test-token', 'expires_in' => 3600]),
            '*/oauth/userinfo' => Http::response(['accounts' => [['account_id' => 'test-account']]]),
        ]);
        $this->artisan('docusign:check')->expectsOutputToContain('authentication and account access verified')->assertSuccessful();
        $decode = fn ($value) => base64_decode(strtr($value, '-_', '+/'));
        $requests = Http::recorded(fn ($request) => str_ends_with($request->url(), '/oauth/token'));
        [$header, $claims, $signature] = explode('.', $requests[0][0]['assertion']);
        $this->assertSame(1, openssl_verify($header.'.'.$claims, $decode($signature), $public, OPENSSL_ALGO_SHA256));
        $this->assertSame('RS256', json_decode($decode($header), true)['alg']);
        $payload = json_decode($decode($claims), true);
        $this->assertSame('test-key', $payload['iss']);
        $this->assertSame('test-user', $payload['sub']);
        $this->assertSame('account-d.docusign.com', $payload['aud']);
        $this->assertSame('signature impersonation', $payload['scope']);
        $this->assertSame(3600, $payload['exp'] - $payload['iat']);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/oauth/userinfo') && $request->hasHeader('Authorization', 'Bearer fresh-test-token'));
    }

    public function test_missing_consent_is_actionable_and_never_creates_an_envelope(): void
    {
        $this->configureEphemeralSigningKey();
        Http::fake(['*/oauth/token' => Http::response(['error' => 'consent_required'], 400)]);
        $this->actingAs($this->actor('admin'))->followingRedirects()->post('/signing-demo/sign')->assertOk()->assertSee('Use Connect DocuSign');
        Http::assertSentCount(1);
    }

    public function test_connectivity_check_reports_network_failure_without_leaking_request_details(): void
    {
        Http::fake(['*/oauth/userinfo' => Http::failedConnection('Sensitive request details must remain private')]);
        $this->artisan('docusign:check')->expectsOutputToContain('Cannot reach DocuSign over HTTPS')->assertFailed();
    }

    public function test_demo_is_admin_only_on_every_endpoint(): void
    {
        $this->get('/signing-demo')->assertRedirect('/login');
        foreach (['manager', 'client'] as $role) {
            $this->actingAs($this->actor($role));
            foreach (['', '/sample', '/consent', '/return', '/signed'] as $path) {
                $this->get('/signing-demo'.$path)->assertForbidden();
            }
            foreach (['connect', 'sign', 'refresh', 'reset'] as $action) {
                $this->post('/signing-demo/'.$action)->assertForbidden();
            }
        }
        Http::assertNothingSent();
    }

    public function test_admin_can_preview_and_sign_without_creating_client_or_records(): void
    {
        Http::fake([
            '*/envelopes' => Http::response(['envelopeId' => self::ENVELOPE]),
            '*/envelopes/'.self::ENVELOPE => Http::response(['status' => 'sent']),
            '*/views/recipient' => Http::response(['url' => 'https://demo.docusign.net/signing/test']),
        ]);
        $users = User::count(); $records = PortalRecord::count();
        $this->actingAs($this->actor('admin'));
        $this->get('/signing-demo')->assertOk()->assertSee('Alexander Morgan')->assertSee('View sample PDF')->assertDontSee('<object', false)->assertDontSee('Document preview');
        $this->get('/signing-demo/sample')->assertOk()->assertHeader('Content-Type', 'application/pdf')->assertHeader('X-Frame-Options', 'DENY');
        $this->post('/signing-demo/sign')->assertRedirect('https://demo.docusign.net/signing/test');
        $this->post('/signing-demo/sign')->assertRedirect('https://demo.docusign.net/signing/test');
        $this->assertSame($users, User::count()); $this->assertSame($records, PortalRecord::count());
        $this->assertCount(1, Http::recorded(fn ($r) => str_ends_with($r->url(), '/envelopes')));
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/envelopes') && $r['recipients']['signers'][0]['clientUserId'] === DocusignDemo::CLIENT_ID && str_starts_with(base64_decode($r['documents'][0]['documentBase64']), '%PDF-'));
    }

    public function test_return_event_cannot_forge_completion_and_download_requires_verified_status(): void
    {
        $this->actingAs($admin = $this->actor('admin'))->withSession(['docusign_demo' => ['owner' => $admin->id, 'envelope' => self::ENVELOPE, 'return_state' => 'known-state']]);
        Http::fake([
            '*/envelopes/'.self::ENVELOPE => Http::sequence()->push(['status' => 'sent'])->push(['status' => 'sent'])->push(['status' => 'completed'])->push(['status' => 'completed']),
            '*/documents/combined' => Http::response('%PDF-signed', 200, ['Content-Type' => 'application/pdf']),
        ]);
        $this->get('/signing-demo/return?state=wrong&event=signing_complete')->assertForbidden();
        $this->get('/signing-demo/return?state=known-state&event=signing_complete')->assertRedirect('/signing-demo')->assertSessionHas('docusign_demo.status', 'sent');
        $this->get('/signing-demo/signed')->assertRedirect('/signing-demo')->assertSessionHasErrors('docusign');
        $this->post('/signing-demo/refresh')->assertSessionHas('docusign_demo.status', 'completed');
        $this->get('/signing-demo/signed')->assertOk()->assertContent('%PDF-signed');
    }

    public function test_errors_are_safe_and_consent_requires_matching_state(): void
    {
        $this->actingAs($this->actor('admin'));
        Http::fake(['*' => Http::response(['secret' => 'DO-NOT-DISPLAY'], 400)]);
        $this->post('/signing-demo/sign')->assertRedirect('/signing-demo')->assertSessionHasErrors('docusign');
        $this->get('/signing-demo')->assertDontSee('DO-NOT-DISPLAY');
        $this->get('/signing-demo/consent?state=forged')->assertForbidden();
        $response = $this->post('/signing-demo/connect');
        $this->assertStringStartsWith('https://account-d.docusign.com/oauth/auth?', $response->headers->get('Location'));
    }

    public function test_onboarding_reviews_are_private_version_specific_and_repeatable(): void
    {
        $client = $this->actor('client');
        $doc = PortalRecord::create(['client_id' => $client->id, 'kind' => 'document', 'data' => ['title' => 'Welcome and next steps', 'category' => 'Onboarding', 'status' => 'approved', 'visibility' => 'client', 'document_date' => '2026-10-29']]);
        $this->actingAs($client)->get('/dashboard')->assertOk()->assertSee('Welcome and next steps')->assertSee('Record review');
        $this->post('/onboarding/'.$doc->id.'/acknowledge', ['acknowledged' => 1])->assertSessionHasNoErrors();
        $this->post('/onboarding/'.$doc->id.'/acknowledge', ['acknowledged' => 1])->assertSessionHasNoErrors();
        $this->assertSame(1, PortalRecord::where('kind', 'onboarding_ack')->count());
        $this->get('/dashboard')->assertSee('Review recorded');
        $this->actingAs($this->actor('james'))->post('/onboarding/'.$doc->id.'/acknowledge', ['acknowledged' => 1])->assertNotFound();
        $this->actingAs($this->actor('manager'))->post('/onboarding/'.$doc->id.'/acknowledge', ['acknowledged' => 1])->assertForbidden();
        $doc->update(['data' => array_merge($doc->data, ['visibility' => 'staff'])]);
        $this->actingAs($client)->post('/onboarding/'.$doc->id.'/acknowledge', ['acknowledged' => 1])->assertNotFound();
    }
}
