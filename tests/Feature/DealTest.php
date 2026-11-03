<?php

namespace Tests\Feature;

use App\Models\PortalRecord;
use App\Models\User;
use App\Services\DealAgreement;
use App\Services\DocusignDemo;
use Database\Seeders\PrototypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DealTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(PrototypeSeeder::class);
    }

    private function user(string $email): User
    {
        return User::where('email', $email.'@fineworth.test')->firstOrFail();
    }

    private function payload(): array
    {
        return ['client_id' => $this->user('client')->id, 'client_address' => '14 Example Square, London',
            'type' => 'wine', 'name' => 'Château Margaux', 'reference' => 'FW-W-DEMO-24',
            'producer' => 'Château Margaux', 'storage' => 'London City Bond', 'quantity' => 24,
            'vintage' => 2018, 'bottle_ml' => 750, 'unit_price' => '250.00',
            'deposit' => '1500.00', 'deposit_reference' => 'DEMO-TRANSFER', 'deposit_date' => now()->toDateString(),
            'balance_due' => now()->addDays(30)->toDateString(), 'markup_rate' => '5',
            'management_rate' => '2.5', 'brokerage_rate' => '2'];
    }

    public function test_deal_generation_approval_client_review_and_private_access(): void
    {
        $this->actingAs($this->user('manager'))->post('/deals', $this->payload())->assertSessionHasNoErrors();
        $deal = PortalRecord::where('kind', 'deal')->firstOrFail();
        $this->assertSame(['stock' => 600000, 'markup' => 30000, 'management' => 15000, 'brokerage' => 12000, 'total' => 657000, 'deposit' => 150000, 'balance' => 507000], $deal->data['totals']);
        $this->get('/deals/'.$deal->id)->assertOk()->assertSee('GBP 5,070.00');
        $this->post('/deals/'.$deal->id.'/generate')->assertSessionHasNoErrors();
        $doc = PortalRecord::findOrFail($deal->fresh()->data['document_id']);
        $this->assertStringStartsWith('%PDF-', Storage::disk('local')->get($doc->data['path']));
        $this->post('/deals/'.$deal->id.'/generate')->assertStatus(409);
        $this->actingAs($this->user('client'))->get('/deals/'.$deal->id)->assertNotFound();
        $this->get('/deals')->assertDontSee('FW-W-DEMO-24');
        $this->get('/documents/'.$doc->id.'/download?view=1')->assertNotFound();
        $this->post('/deals', $this->payload())->assertForbidden();
        $this->actingAs($this->user('manager'))->post('/approvals/'.$doc->id, ['decision' => 'approved'])->assertForbidden();
        $this->actingAs($this->user('admin'))->post('/approvals/'.$doc->id, ['decision' => 'approved'])->assertRedirect();
        $this->assertSame('published', $deal->fresh()->data['status']);
        $this->actingAs($this->user('client'))->get('/deals/'.$deal->id)->assertOk()->assertSee('GBP 5,070.00');
        $this->get('/documents/'.$doc->id.'/download?view=1')->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->post('/deals/'.$deal->id.'/acknowledge', ['acknowledged' => 1])->assertRedirect();
        $this->assertSame('reviewed', $deal->fresh()->data['status']);
        $this->actingAs($this->user('james'))->get('/deals/'.$deal->id)->assertNotFound();
        $this->get('/documents/'.$doc->id.'/download')->assertNotFound();
        $this->actingAs($this->user('amelia'))->get('/deals/'.$deal->id)->assertNotFound();
    }

    public function test_validation_and_assignment_isolation(): void
    {
        $this->actingAs($this->user('manager'));
        $this->post('/deals', array_replace($this->payload(), ['client_id' => $this->user('james')->id]))->assertNotFound();
        $this->post('/deals', array_replace($this->payload(), ['brokerage_rate' => 99]))->assertSessionHasErrors('brokerage_rate');
        $this->post('/deals', array_replace($this->payload(), ['deposit' => '99999']))->assertSessionHasErrors('deposit');
        $this->post('/deals', array_replace($this->payload(), ['unit_price' => '1.123']))->assertSessionHasErrors('unit_price');
        $this->post('/deals', array_replace($this->payload(), ['type' => 'whisky', 'quantity' => 2]))->assertSessionHasErrors(['quantity', 'ola', 'rla', 'cask_size']);
        $this->assertSame(0, PortalRecord::where('kind', 'deal')->count());
    }

    public function test_rejected_agreement_remains_private_and_one_manager_can_manage_multiple_clients(): void
    {
        $manager = $this->user('manager');
        $this->actingAs($this->user('admin'))->post('/clients/'.$this->user('james')->id.'/assign', ['manager_ids' => [$manager->id]])->assertRedirect();
        $this->actingAs($manager)->get('/clients')->assertSee('Sophia Bennett')->assertSee('James Whitmore');
        $this->post('/deals', $this->payload())->assertSessionHasNoErrors();
        $deal = PortalRecord::where('kind', 'deal')->firstOrFail();
        $this->post('/deals/'.$deal->id.'/generate');
        $doc = $deal->fresh()->data['document_id'];
        $this->actingAs($this->user('admin'))->post('/approvals/'.$doc, ['decision' => 'rejected', 'reason' => 'Correct the deposit reference.'])->assertRedirect();
        $this->assertSame('rejected', $deal->fresh()->data['status']);
        $this->actingAs($this->user('client'))->get('/deals/'.$deal->id)->assertNotFound();
        $this->get('/documents/'.$doc.'/download')->assertNotFound();
        $this->post('/deals/'.$deal->id.'/acknowledge', ['acknowledged' => 1])->assertNotFound();
    }

    public function test_rounding_and_actual_dashboard_action_counts(): void
    {
        $amounts = DealAgreement::totals(array_replace($this->payload(), ['quantity' => 1, 'unit_price' => '0.10', 'markup_rate' => 5, 'management_rate' => 0, 'brokerage_rate' => 0, 'deposit' => '0.01']));
        $this->assertSame(1, $amounts['markup']);
        $this->assertSame(10, $amounts['balance']);
        $this->actingAs($this->user('admin'))->get('/dashboard')->assertSee('3 items need attention')->assertDontSee('Collection mix');
        $pending = PortalRecord::where('kind', 'profile')->firstOrFail();
        $this->post('/approvals/'.$pending->id, ['decision' => 'approved']);
        $this->get('/dashboard')->assertSee('2 items need attention');
    }

    public function test_generated_deal_signing_requires_owner_and_verified_completion_before_publication(): void
    {
        $this->actingAs($this->user('manager'))->post('/deals', $this->payload());
        $deal = PortalRecord::where('kind', 'deal')->firstOrFail();
        $this->post('/deals/'.$deal->id.'/generate');
        $original = $deal->fresh()->data['document_id'];
        $this->actingAs($this->user('admin'))->post('/approvals/'.$original, ['decision' => 'approved']);
        $signing = $this->mock(DocusignDemo::class);
        $envelope = '11111111-2222-3333-4444-555555555555';
        $signing->shouldReceive('createDealEnvelope')->once()->withArgs(fn ($pdf, $name, $email, $clientId) => str_starts_with($pdf, '%PDF-') && $email === 'client@fineworth.test' && $clientId === 'deal-'.$deal->id.'-client-'.$deal->client_id)->andReturn($envelope);
        $signing->shouldReceive('status')->with($envelope)->andReturn('sent', 'sent', 'completed', 'completed');
        $signing->shouldReceive('dealSigningUrl')->once()->andReturn('https://demo.docusign.net/signing/test');
        $this->post('/deals/'.$deal->id.'/sign', ['sandbox_ack' => 1])->assertForbidden();
        $this->actingAs($this->user('james'))->post('/deals/'.$deal->id.'/sign', ['sandbox_ack' => 1])->assertNotFound();
        $this->actingAs($this->user('client'))->post('/deals/'.$deal->id.'/sign', ['sandbox_ack' => 1])->assertRedirect('https://demo.docusign.net/signing/test');
        $this->get('/deals/'.$deal->id.'/signing-return?state=forged&event=signing_complete')->assertForbidden();
        $this->post('/deals/'.$deal->id.'/signing-refresh', ['event' => 'signing_complete'])->assertRedirect();
        $this->assertArrayNotHasKey('signed_document_id', $deal->fresh()->data);
        $signing->shouldReceive('completedPdf')->once()->with($envelope)->andReturn('%PDF-1.4 fictional signed test');
        $this->post('/deals/'.$deal->id.'/signing-refresh')->assertRedirect();
        $signed = PortalRecord::findOrFail($deal->fresh()->data['signed_document_id']);
        $this->assertSame('pending', $signed->data['status']);
        $this->assertSame($original, $signed->data['replaces_id']);
        $this->get('/documents/'.$signed->id.'/download')->assertNotFound();
        $this->post('/deals/'.$deal->id.'/signing-refresh')->assertRedirect();
        $this->assertSame(1, PortalRecord::where('kind', 'document')->where('data->replaces_id', $original)->count());
        $this->actingAs($this->user('admin'))->post('/approvals/'.$signed->id, ['decision' => 'approved'])->assertRedirect();
        $this->actingAs($this->user('client'))->get('/documents/'.$signed->id.'/download')->assertOk();
        $this->get('/documents/'.$original.'/download')->assertOk();
    }
}
