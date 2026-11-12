<?php

namespace Tests\Feature;

use App\Models\Holding;
use App\Models\PortalEntry;
use App\Models\User;
use Database\Seeders\PrototypeSeeder;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\Support\WorkflowFixtures;
use Tests\TestCase;

class PortalTest extends TestCase
{
    use RefreshDatabase, WorkflowFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(PrototypeSeeder::class);
        foreach (User::where('role', 'client')->get() as $client) {
            $this->approveClient($client);
            $agreement = $this->signedAgreement($client);
            Holding::where('client_id', $client->id)->update(['agreement_id' => $agreement->id, 'released_at' => now()]);
        }
    }

    private function actor(string $role): User
    {
        return User::where('email', match ($role) {
            'admin' => 'admin@fineworth.test', 'manager' => 'manager@fineworth.test', 'other' => 'james@fineworth.test', default => 'client@fineworth.test',
        })->firstOrFail();
    }

    public function test_role_screens_and_replaced_navigation(): void
    {
        foreach (['admin', 'manager', 'client'] as $role) {
            $this->actingAs($this->actor($role));
            foreach (['dashboard', 'holdings', 'requests', 'account', 'notifications', 'holding/'.Holding::where('reference', 'FW-C4188')->value('id')] as $path) {
                $this->get('/'.$path)->assertOk();
            }
            if ($role !== 'client') {
                $this->get('/clients')->assertOk();
                $this->get('/client/'.$this->actor('client')->id)->assertOk();
                $this->get('/clients/'.$this->actor('client')->id.'/onboarding')->assertOk();
            }
        }
        $this->actingAs($this->actor('admin'))->get('/activity')->assertOk();
        $this->get('/approvals')->assertRedirect('/activity');
        $this->get('/documents')->assertRedirect('/clients');
        $this->actingAs($this->actor('manager'))->get('/activity')->assertForbidden();
        $this->get('/deals')->assertForbidden();
        $this->get('/dashboard')->assertDontSee('Client onboarding')->assertDontSee('href="/documents"', false);
    }

    public function test_onboarding_gate_verification_and_admin_review(): void
    {
        $client = $this->actor('client');
        $client->clientProfile->update(['onboarding_status' => 'draft']);
        $client->forceFill(['email_verified_at' => null])->save();
        $this->actingAs($client->fresh())->get('/dashboard')->assertRedirect('/onboarding');
        $holding = Holding::where('client_id', $client->id)->first();
        $this->get('/holding/'.$holding->id)->assertRedirect('/onboarding');
        $this->get('/onboarding')->assertOk()->assertSee('Identity and address proof');
        Notification::fake();
        $this->post('/email/verification-notification')->assertRedirect();
        Notification::assertSentTo($client, VerifyEmail::class);
        $this->get(URL::temporarySignedRoute('verification.verify', now()->addMinutes(30), ['id' => $client->id, 'hash' => sha1($client->email)]))->assertRedirect('/onboarding');
        $this->post('/onboarding/'.$client->id.'/details', ['name' => 'Sophia Bennett', 'phone' => '12345', 'address' => 'Test address'])->assertRedirect();
        $this->post('/onboarding/'.$client->id.'/submit')->assertStatus(422);
        foreach (['identity', 'address'] as $purpose) {
            $this->post('/onboarding/'.$client->id.'/documents', ['purpose' => $purpose, 'label' => $purpose === 'identity' ? 'Passport' : 'Utility bill', 'file' => UploadedFile::fake()->create($purpose.'.pdf', 10, 'application/pdf')])->assertRedirect();
        }
        $this->post('/onboarding/'.$client->id.'/submit')->assertRedirect();
        $this->get('/deals')->assertRedirect('/onboarding');
        $this->post('/onboarding/'.$client->id.'/details', ['name' => 'Changed', 'phone' => '123', 'address' => 'Changed'])->assertStatus(409);
        $this->actingAs($this->actor('manager'))->post('/onboarding/'.$client->id.'/review', ['decision' => 'approved'])->assertForbidden();
        $this->actingAs($this->actor('admin'))->post('/onboarding/'.$client->id.'/review', ['decision' => 'returned'])->assertSessionHasErrors('reason');
        $this->post('/onboarding/'.$client->id.'/review', ['decision' => 'approved'])->assertRedirect();
        $this->actingAs($client->fresh())->get('/deals')->assertOk();
        $document = PortalEntry::where('client_id', $client->id)->whereHas('documentVersion', fn ($q) => $q->where('onboarding_purpose', 'identity'))->first();
        $this->assertSame('staff', $document->data['visibility']);
        $this->get('/documents/'.$document->id.'/download')->assertNotFound();
    }

    public function test_private_downloads_and_assignment_isolation(): void
    {
        $other = Holding::where('client_id', $this->actor('other')->id)->first();
        foreach (['client', 'manager'] as $role) {
            $this->actingAs($this->actor($role))->get('/holding/'.$other->id)->assertNotFound();
            $this->get('/client/'.$this->actor('other')->id)->assertStatus($role === 'client' ? 403 : 404);
        }
        $kyc = PortalEntry::where('kind', 'document')->whereField('category', 'KYC')->first();
        $this->actingAs($this->actor('client'))->get('/documents/'.$kyc->id.'/download')->assertNotFound();
        $this->actingAs($this->actor('manager'))->get('/documents/'.$kyc->id.'/download')->assertOk();
        $this->assertStringContainsString('no-store', $this->get('/dashboard')->headers->get('Cache-Control'));
    }

    public function test_only_admin_creates_holdings_and_confirmed_details_are_locked(): void
    {
        $payload = ['client_id' => $this->actor('client')->id, 'type' => 'wine', 'name' => 'New wine', 'reference' => 'NEW', 'producer' => 'Test', 'quantity' => 12, 'storage' => 'Test', 'acquired' => now()->toDateString(), 'bottle_ml' => 750];
        $this->actingAs($this->actor('manager'))->post('/holdings', $payload)->assertForbidden();
        $this->actingAs($this->actor('client'))->post('/holdings', $payload)->assertForbidden();
        $this->actingAs($this->actor('admin'))->post('/holdings', $payload)->assertRedirect();
        $new = Holding::where('reference', 'NEW')->firstOrFail();
        $this->assertNull($new->released_at);
        $this->actingAs($this->actor('client'))->get('/holding/'.$new->id)->assertNotFound();
        $this->actingAs($this->actor('manager'))->post('/onboarding/'.$this->actor('client')->id.'/details', ['name' => 'Changed', 'phone' => '1', 'address' => 'A'])->assertStatus(409);
        $this->post('/clients/'.$this->actor('client')->id.'/profile', ['name' => 'Changed', 'notes' => 'Reason'])->assertStatus(410);
    }

    public function test_package_review_return_resolve_restore_and_private_files(): void
    {
        $holding = Holding::where('reference', 'FW-C4188')->first();
        $this->actingAs($this->actor('client'))->post('/requests', ['holding_id' => $holding->id, 'type' => 'Regauge', 'notes' => 'Please regauge'])->assertRedirect();
        $entry = PortalEntry::where('kind', 'request')->latest('id')->first();
        $this->post('/requests/'.$entry->id, ['status' => 'in progress'])->assertForbidden();
        $this->actingAs($this->actor('manager'))->post('/requests/'.$entry->id, ['status' => 'completed'])->assertSessionHasErrors('status');
        $this->post('/requests/'.$entry->id, ['status' => 'in progress'])->assertRedirect();
        $payload = ['request_id' => $entry->id, 'title' => 'New regauge', 'notes' => 'New measured report', 'litres' => '190.5', 'files' => [UploadedFile::fake()->create('report.pdf', 10, 'application/pdf')]];
        $this->post('/holdings/'.$holding->id.'/updates', $payload)->assertRedirect();
        $update = DB::table('holding_updates')->where('request_id', $entry->id)->first();
        $file = DB::table('holding_update_files')->where('update_id', $update->id)->first();
        $this->assertSame('pending review', $entry->fresh()->status);
        $original = Holding::find($holding->id)->whisky->litres;
        $this->assertEquals(197.3, $original);
        $this->actingAs($this->actor('client'))->get('/requests/'.$entry->id)->assertOk()->assertDontSee('New measured report');
        $this->get('/update-files/'.$file->id)->assertNotFound();
        $this->actingAs($this->actor('admin'))->post('/updates/'.$update->id.'/review', ['decision' => 'returned'])->assertSessionHasErrors('reason');
        $this->post('/updates/'.$update->id.'/review', ['decision' => 'returned', 'reason' => 'Wrong report'])->assertRedirect();
        $this->assertSame('in progress', $entry->fresh()->status);
        $this->actingAs($this->actor('client'))->get('/requests/'.$entry->id)->assertOk()->assertDontSee('Wrong report');
        $this->actingAs($this->actor('manager'))->post('/holdings/'.$holding->id.'/updates', array_replace($payload, ['notes' => 'Correct report', 'files' => [UploadedFile::fake()->create('correct.pdf', 10, 'application/pdf')]]))->assertRedirect();
        $correct = DB::table('holding_updates')->where('request_id', $entry->id)->latest('id')->first();
        $this->actingAs($this->actor('admin'))->post('/updates/'.$correct->id.'/review', ['decision' => 'approved'])->assertRedirect();
        $this->assertSame('resolved', $entry->fresh()->status);
        $this->assertEquals(190.5, Holding::find($holding->id)->whisky->litres);
        $this->post('/updates/'.$correct->id.'/review', ['decision' => 'approved'])->assertStatus(409);
        $baseline = DB::table('holding_updates')->where('holding_id', $holding->id)->whereNull('request_id')->first();
        $this->post('/updates/'.$baseline->id.'/restore', ['reason' => 'Revert to earlier measurement'])->assertRedirect();
        $this->assertEquals($original, Holding::find($holding->id)->whisky->litres);
        $this->assertSame('resolved', $entry->fresh()->status);
        $this->assertDatabaseCount('holding_updates', 3);
        $this->assertDatabaseCount('holding_publications', 2);
        $this->actingAs($this->actor('client'))->get('/holding/'.$holding->id)->assertOk()->assertSee('Correct report')->assertDontSee('Wrong report');
    }

    public function test_cross_holding_packages_upload_validation_and_direct_admin_updates(): void
    {
        $holding = Holding::where('reference', 'FW-C4188')->first();
        $other = Holding::where('client_id', $this->actor('other')->id)->first();
        $payload = ['title' => 'Direct update', 'notes' => 'New photos', 'files' => [UploadedFile::fake()->create('image.php', 2, 'text/x-php')]];
        $this->actingAs($this->actor('admin'))->post('/holdings/'.$holding->id.'/updates', $payload)->assertSessionHasErrors('files.0');
        $payload['files'] = [UploadedFile::fake()->image('photo.png')];
        $this->actingAs($this->actor('manager'))->post('/holdings/'.$other->id.'/updates', $payload)->assertNotFound();
        $this->post('/holdings/'.$holding->id.'/updates', $payload)->assertForbidden();
        $service = PortalEntry::where('kind', 'request')->firstOrFail();
        $this->post('/holdings/'.$holding->id.'/updates', $payload + ['request_id' => $service->id])->assertStatus(409);
        $before = PortalEntry::where('kind', 'request')->count();
        $this->actingAs($this->actor('admin'))->post('/holdings/'.$holding->id.'/updates', $payload)->assertRedirect();
        $update = DB::table('holding_updates')->where('status', 'pending review')->first();
        $this->post('/updates/'.$update->id.'/review', ['decision' => 'approved'])->assertRedirect();
        $this->assertSame($before, PortalEntry::where('kind', 'request')->count());
        $image = DB::table('holding_update_files')->where('update_id', $update->id)->first();
        $this->actingAs($this->actor('client'))->get('/update-files/'.$image->id.'?view=1')->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->assertDatabaseHas('portal_notifications', ['user_id' => $this->actor('client')->id, 'message' => 'A holding update has been published.']);
    }

    public function test_wine_cannot_be_regauged_and_notifications_are_private(): void
    {
        $wine = Holding::where('client_id', $this->actor('client')->id)->where('type', 'wine')->first();
        $this->actingAs($this->actor('client'))->post('/requests', ['holding_id' => $wine->id, 'type' => 'Regauge', 'notes' => 'Wrong type'])->assertStatus(422);
        $id = DB::table('portal_notifications')->insertGetId(['user_id' => $this->actor('other')->id, 'message' => 'OTHER-CLIENT-ALERT', 'url' => '/holdings', 'created_at' => now()]);
        $this->get('/notifications')->assertDontSee('OTHER-CLIENT-ALERT');
        $this->post('/notifications/'.$id.'/read')->assertNotFound();
    }
}
