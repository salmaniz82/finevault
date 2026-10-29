<?php

namespace Tests\Feature;

use App\Models\PortalRecord;
use App\Models\User;
use App\Services\HoldingMeasurements;
use Database\Seeders\PrototypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PortalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(PrototypeSeeder::class);
    }

    private function actor(string $role): User
    {
        return User::where('email', match ($role) {
            'admin' => 'admin@fineworth.test','manager' => 'manager@fineworth.test','other' => 'james@fineworth.test',default => 'client@fineworth.test'
        })->firstOrFail();
    }

    public function test_all_role_screens_render(): void
    {
        $this->get('/login')->assertOk()->assertSee('Welcome back.');
        foreach (['admin', 'manager', 'client'] as $role) {
            $this->actingAs($this->actor($role));
            foreach (['dashboard', 'holdings', 'documents', 'requests', 'account', 'holding/1'] as $path) {
                $this->get('/'.$path)->assertOk();
            }
            if ($role !== 'client') {
                foreach (['clients', 'client/4'] as $path) {
                    $this->get('/'.$path)->assertOk();
                }
            }
            if ($role === 'admin') {
                foreach (['managers', 'approvals'] as $path) {
                    $this->get('/'.$path)->assertOk();
                }
            }
        }
    }

    public function test_missing_record_ids_fail_as_not_found(): void
    {
        $this->actingAs($this->actor('admin'))->get('/holding')->assertNotFound();
        $this->get('/client')->assertNotFound();
    }

    public function test_failed_form_preserves_values_and_private_pages_are_not_cached(): void
    {
        $this->actingAs($this->actor('manager'))->from('/holdings')->post('/holdings', ['_form' => 'holding-modal', 'name' => 'A holding in progress', 'client_id' => $this->actor('client')->id])->assertRedirect('/holdings')->assertSessionHasErrors('type')->assertSessionHasInput('name', 'A holding in progress');
        $this->get('/holdings')->assertOk()->assertSee('holding-modal')->assertSee('A holding in progress')->assertHeader('X-Frame-Options', 'DENY')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('no-store', $this->get('/dashboard')->headers->get('Cache-Control'));
    }

    public function test_document_validation_rejects_executable_and_oversized_uploads(): void
    {
        $payload = ['client_id' => $this->actor('client')->id, 'title' => 'Invalid upload', 'category' => 'Storage', 'visibility' => 'client', 'document_date' => now()->toDateString(), 'file' => UploadedFile::fake()->create('payload.php', 20, 'text/x-php')];
        $this->actingAs($this->actor('manager'))->post('/documents', $payload)->assertSessionHasErrors('file');
        $payload['file'] = UploadedFile::fake()->create('large.pdf', 10241, 'application/pdf');
        $this->post('/documents', $payload)->assertSessionHasErrors('file');
    }

    public function test_login_requires_credentials_and_logout_ends_session(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
        $this->post('/login', ['email' => 'client@fineworth.test', 'password' => 'wrong'])->assertSessionHasErrors('email');
        $this->post('/login', ['email' => 'client@fineworth.test', 'password' => 'FineworthDemo!27'])->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($this->actor('client'));
        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_clients_and_managers_cannot_access_other_clients(): void
    {
        $otherHolding = PortalRecord::where('client_id', $this->actor('other')->id)->where('kind', 'holding')->first();
        foreach (['client', 'manager'] as $role) {
            $this->actingAs($this->actor($role));
            $this->get('/holding/'.$otherHolding->id)->assertNotFound();
            $this->get('/dashboard')->assertDontSee('Highland Park 2018')->assertDontSee('James Whitmore');
            $this->get('/approvals')->assertForbidden();
            $this->post('/accounts', ['name' => 'Attacker'])->assertForbidden();
        }
        $this->actingAs($this->actor('manager'))->get('/client/'.$this->actor('other')->id)->assertNotFound();
    }

    public function test_private_documents_are_hidden_and_downloads_authorized(): void
    {
        $pending = PortalRecord::where('kind', 'document')->where('data->status', 'pending')->first();
        $kyc = PortalRecord::where('kind', 'document')->where('data->category', 'KYC')->first();
        $approved = PortalRecord::where('kind', 'document')->where('data->category', 'Ownership')->first();
        $this->actingAs($this->actor('client'))->get('/documents')->assertDontSee('Annual regauge report')->assertDontSee('Identity verification record');
        foreach ([$pending, $kyc] as $doc) {
            $this->get('/documents/'.$doc->id.'/download')->assertNotFound();
        }
        $this->get('/documents/'.$approved->id.'/download')->assertOk()->assertDownload();
        $this->actingAs($this->actor('other'))->get('/documents/'.$approved->id.'/download')->assertNotFound();
    }

    public function test_document_upload_approval_replacement_and_immutable_history(): void
    {
        $client = $this->actor('client');
        $payload = ['client_id' => $client->id, 'holding_id' => 1, 'title' => 'Fresh report', 'category' => 'Regauge', 'visibility' => 'client', 'document_date' => now()->toDateString(), 'notes' => 'Measured at the warehouse.', 'file' => UploadedFile::fake()->create('report.pdf', 20, 'application/pdf')];
        $this->actingAs($this->actor('manager'))->post('/documents', $payload)->assertRedirect()->assertSessionHasNoErrors();
        $doc = PortalRecord::latest('id')->first();
        $this->assertSame('pending', $doc->data['status']);
        Storage::disk('local')->assertExists($doc->data['path']);
        $this->actingAs($client)->get('/documents/'.$doc->id.'/download')->assertNotFound();
        $this->actingAs($this->actor('manager'))->post('/approvals/'.$doc->id, ['decision' => 'approved'])->assertForbidden();
        $this->actingAs($this->actor('admin'))->post('/approvals/'.$doc->id, ['decision' => 'approved'])->assertRedirect();
        $this->actingAs($client)->get('/documents')->assertSee('Fresh report');
        $this->get('/documents/'.$doc->id.'/download')->assertOk();
        $payload['replaces_id'] = $doc->id;
        $payload['title'] = 'Updated report';
        $payload['file'] = UploadedFile::fake()->create('updated.pdf', 20, 'application/pdf');
        $this->actingAs($this->actor('manager'))->post('/documents', $payload)->assertSessionHasNoErrors();
        $next = PortalRecord::latest('id')->first();
        $this->assertSame($doc->data['series'], $next->data['series']);
        $this->actingAs($this->actor('admin'))->post('/approvals/'.$next->id, ['decision' => 'approved'])->assertRedirect();
        $this->post('/approvals/'.$doc->id, ['decision' => 'rejected', 'reason' => 'Cannot undo approval'])->assertStatus(409);
        $this->actingAs($client)->get('/documents')->assertSee('Fresh report')->assertSee('Updated report');
    }

    public function test_upload_cannot_cross_link_clients_or_holdings(): void
    {
        $payload = ['client_id' => $this->actor('other')->id, 'title' => 'Wrong owner', 'category' => 'Ownership', 'visibility' => 'client', 'document_date' => now()->toDateString(), 'file' => UploadedFile::fake()->create('report.pdf', 20, 'application/pdf')];
        $this->actingAs($this->actor('manager'))->post('/documents', $payload)->assertNotFound();
        $payload['client_id'] = $this->actor('client')->id;
        $payload['holding_id'] = 7;
        $this->actingAs($this->actor('admin'))->post('/documents', $payload)->assertUnprocessable();
    }

    public function test_kyc_is_staff_only_even_when_client_visibility_requested(): void
    {
        $this->actingAs($this->actor('manager'))->post('/documents', ['client_id' => $this->actor('client')->id, 'title' => 'Secure identity', 'category' => 'KYC', 'visibility' => 'client', 'document_date' => now()->toDateString(), 'file' => UploadedFile::fake()->create('identity.pdf', 20, 'application/pdf')])->assertSessionHasNoErrors();
        $doc = PortalRecord::latest('id')->first();
        $this->assertSame('staff', $doc->data['visibility']);
        $this->actingAs($this->actor('admin'))->post('/approvals/'.$doc->id, ['decision' => 'approved']);
        $this->actingAs($this->actor('client'))->get('/documents/'.$doc->id.'/download')->assertNotFound();
    }

    public function test_request_flow_and_asset_service_validation(): void
    {
        $this->actingAs($this->actor('client'))->post('/requests', ['holding_id' => 1, 'type' => 'Regauge', 'notes' => 'Please measure the cask.'])->assertSessionHasNoErrors();
        $request = PortalRecord::latest('id')->first();
        $this->post('/requests/'.$request->id, ['status' => 'completed'])->assertForbidden();
        $this->actingAs($this->actor('manager'))->post('/requests/'.$request->id, ['status' => 'completed', 'staff_note' => 'Report received.'])->assertSessionHasNoErrors();
        $this->actingAs($this->actor('client'))->get('/requests')->assertSee('Report received.')->assertSee('completed');
        $this->post('/requests', ['holding_id' => 3, 'type' => 'Regauge', 'notes' => 'Wine cannot be regauged.'])->assertUnprocessable();
        $this->post('/requests', ['holding_id' => 7, 'type' => 'Evaluation', 'notes' => 'Not my holding.'])->assertNotFound();
    }

    public function test_admin_creates_accounts_and_assignment_controls_manager_access(): void
    {
        $manager = $this->actor('manager');
        $other = $this->actor('other');
        $this->actingAs($this->actor('admin'))->post('/accounts', ['name' => 'New Client', 'email' => 'new@fineworth.test', 'password' => 'A-strong-password!', 'role' => 'client', 'manager_ids' => [$manager->id]])->assertSessionHasNoErrors();
        $new = User::where('email', 'new@fineworth.test')->firstOrFail();
        $this->assertSame([$manager->id], $new->manager_ids);
        $this->post('/clients/'.$other->id.'/assign', ['manager_ids' => [$manager->id]])->assertSessionHasNoErrors();
        $this->actingAs($manager)->get('/client/'.$other->id)->assertOk();
        $this->actingAs($this->actor('admin'))->post('/clients/'.$other->id.'/assign', []);
        $this->actingAs($manager)->get('/client/'.$other->id)->assertNotFound();
    }

    public function test_profile_change_requires_approval_and_rejection_note(): void
    {
        $client = $this->actor('client');
        $this->actingAs($this->actor('manager'))->post('/clients/'.$client->id.'/profile', ['name' => 'Sophia Changed', 'notes' => 'Correct display name.'])->assertSessionHasNoErrors();
        $change = PortalRecord::latest('id')->first();
        $this->assertSame('Sophia Bennett', $client->fresh()->name);
        $this->actingAs($this->actor('admin'))->post('/approvals/'.$change->id, ['decision' => 'rejected'])->assertSessionHasErrors('reason');
        $this->post('/approvals/'.$change->id, ['decision' => 'approved'])->assertSessionHasNoErrors();
        $this->assertSame('Sophia Changed', $client->fresh()->name);
    }

    public function test_client_onboarding_is_private_with_own_submission_status(): void
    {
        $client = $this->actor('client');
        $this->actingAs($client)->post('/documents', ['client_id' => $client->id, 'title' => 'My onboarding file', 'category' => 'Ownership', 'visibility' => 'client', 'document_date' => now()->toDateString(), 'file' => UploadedFile::fake()->create('onboarding.pdf', 20, 'application/pdf')])->assertSessionHasNoErrors();
        $doc = PortalRecord::latest('id')->first();
        $this->assertSame('staff', $doc->data['visibility']);
        $this->assertSame('Onboarding', $doc->data['category']);
        $this->get('/documents')->assertSee('My onboarding file')->assertSee('Pending');
        $this->get('/documents/'.$doc->id.'/download')->assertNotFound();
    }

    public function test_staff_can_create_whisky_and_wine_metadata_without_prices(): void
    {
        $payload = ['client_id' => $this->actor('client')->id, 'type' => 'wine', 'name' => 'Test wine', 'reference' => 'W-TEST', 'producer' => 'Test producer', 'quantity' => 24, 'storage' => 'Test warehouse', 'acquired' => '2026-01-01', 'vintage' => 2018, 'bottle_ml' => 750, 'bottles_per_case' => 6, 'format' => '75 cl'];
        $this->actingAs($this->actor('manager'))->post('/holdings', $payload)->assertSessionHasNoErrors();
        $holding = PortalRecord::latest('id')->first();
        $this->assertSame(24, $holding->data['quantity']);
        $this->actingAs($this->actor('client'))->get('/holding/'.$holding->id)->assertSee('Test wine')->assertDontSee('Purchase price')->assertDontSee('Indicative price');
        $this->post('/holdings', $payload)->assertForbidden();
    }

    public function test_measurements_are_validated_by_type_and_scoped_for_all_roles(): void
    {
        $payload = ['client_id' => $this->actor('client')->id, 'type' => 'wine', 'name' => 'Magnum collection', 'reference' => 'W-MAG', 'producer' => 'Producer', 'quantity' => 3, 'storage' => 'Bond', 'acquired' => '2026-01-01'];
        $this->actingAs($this->actor('manager'))->post('/holdings', $payload)->assertSessionHasErrors('bottle_ml');
        $this->post('/holdings', $payload + ['bottle_ml' => -1])->assertSessionHasErrors('bottle_ml');
        $this->post('/holdings', $payload + ['bottle_ml' => 1500, 'litres' => 999, 'abv' => 50])->assertSessionHasNoErrors();
        $wine = PortalRecord::latest('id')->first();
        $this->assertArrayNotHasKey('litres', $wine->data);
        $this->assertArrayNotHasKey('abv', $wine->data);
        foreach (['admin', 'manager', 'client'] as $role) {
            $this->actingAs($this->actor($role))->get('/holding/'.$wine->id)->assertOk()->assertSee('3 bottles')->assertSee('1,500 mL / bottle')->assertSee('4.5 L wine');
            $this->get('/dashboard')->assertSee('69 bottles')->assertSee('54 L recorded')->assertSee($role === 'admin' ? '3 casks' : '2 casks');
        }
        $payload['type'] = 'whisky';
        $this->actingAs($this->actor('manager'))->post('/holdings', $payload)->assertSessionHasErrors('quantity');
        $payload['quantity'] = 1;
        $payload['litres'] = 200.25;
        $payload['abv'] = 62.5;
        $this->post('/holdings', $payload + ['bottle_ml' => 750, 'vintage' => 2020])->assertSessionHasNoErrors();
        $cask = PortalRecord::latest('id')->first();
        $this->assertArrayNotHasKey('bottle_ml', $cask->data);
        $this->assertArrayNotHasKey('vintage', $cask->data);
        $this->actingAs($this->actor('client'))->get('/holding/'.$cask->id)->assertSee('1 cask')->assertSee('200.25 L recorded bulk')->assertSee('62.5% ABV');
        $payload['client_id'] = $this->actor('other')->id;
        $this->actingAs($this->actor('manager'))->post('/holdings', $payload)->assertNotFound();
    }

    public function test_unknown_measurements_are_not_reported_as_zero_or_complete_totals(): void
    {
        $known = new PortalRecord(['data' => ['type' => 'wine', 'quantity' => 12, 'format' => '75 cl · 6-bottle cases']]);
        $unknown = new PortalRecord(['data' => ['type' => 'wine', 'quantity' => 6, 'format' => 'Wooden case']]);
        $zero = new PortalRecord(['data' => ['type' => 'whisky', 'quantity' => 1, 'litres' => 0]]);
        $this->assertSame('9 L wine', HoldingMeasurements::volume($known->data));
        $this->assertSame('Volume not recorded', HoldingMeasurements::volume($unknown->data));
        $this->assertSame('0 L recorded bulk', HoldingMeasurements::volume($zero->data));
        $this->assertSame('9 L recorded · 1 holding(s) missing volume', HoldingMeasurements::totalVolume(collect([$known, $unknown])));
    }
}
