<?php

namespace Tests\Feature;

use App\Models\Holding;
use App\Models\PortalEntry;
use App\Models\User;
use App\Services\DealAgreement;
use App\Services\DocusignDemo;
use App\Services\DomainRecords;
use Database\Seeders\PrototypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DealTest extends TestCase
{
    use RefreshDatabase, \Tests\Support\WorkflowFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(PrototypeSeeder::class);
        foreach (User::where('role', 'client')->get() as $client) {
            $this->approveClient($client);
        }
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
        $this->actingAs($this->user('admin'))->post('/deals', $this->payload())->assertSessionHasNoErrors();
        $deal = PortalEntry::where('kind', 'deal')->firstOrFail();
        $this->assertSame(['stock' => 600000, 'markup' => 30000, 'management' => 15000, 'brokerage' => 12000, 'total' => 657000, 'deposit' => 150000, 'balance' => 507000], $deal->data['totals']);
        $this->get('/deals/'.$deal->id)->assertOk()->assertSee('GBP 5,070.00');
        $this->post('/deals/'.$deal->id.'/generate')->assertSessionHasNoErrors();
        $doc = PortalEntry::findOrFail($deal->fresh()->data['document_id']);
        $this->assertStringStartsWith('%PDF-', Storage::disk('local')->get($doc->data['path']));
        $this->assertSame(hash('sha256', Storage::disk('local')->get($doc->data['path'])), $doc->data['signing_hotspots']['source_sha256']);
        $this->assertArrayHasKey('full_name', $doc->data['signing_hotspots']['fields']);
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
        $this->actingAs($this->user('amelia'))->get('/deals/'.$deal->id)->assertForbidden();
    }

    public function test_validation_and_assignment_isolation(): void
    {
        $this->actingAs($this->user('admin'));
        $this->post('/deals', array_replace($this->payload(), ['brokerage_rate' => 99]))->assertSessionHasErrors('brokerage_rate');
        $this->post('/deals', array_replace($this->payload(), ['deposit' => '99999']))->assertSessionHasErrors('deposit');
        $this->post('/deals', array_replace($this->payload(), ['unit_price' => '1.123']))->assertSessionHasErrors('unit_price');
        $this->post('/deals', array_replace($this->payload(), ['type' => 'whisky', 'quantity' => 2]))->assertSessionHasErrors(['quantity', 'ola', 'rla', 'cask_size']);
        $this->assertSame(0, PortalEntry::where('kind', 'deal')->count());
    }

    public function test_rejected_agreement_remains_private_and_one_manager_can_manage_multiple_clients(): void
    {
        $manager = $this->user('manager');
        $this->actingAs($this->user('admin'))->post('/clients/'.$this->user('james')->id.'/assign', ['manager_ids' => [$manager->id]])->assertRedirect();
        $this->actingAs($manager)->get('/clients')->assertSee('Sophia Bennett')->assertSee('James Whitmore');
        $this->actingAs($this->user('admin'));
        $this->post('/deals', $this->payload())->assertSessionHasNoErrors();
        $deal = PortalEntry::where('kind', 'deal')->firstOrFail();
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
        $this->actingAs($this->user('admin'))->get('/dashboard')->assertSee('Service requests')->assertDontSee('Collection mix');
        $pending = PortalEntry::where('kind', 'profile')->firstOrFail();
        $this->post('/approvals/'.$pending->id, ['decision' => 'approved']);
        $this->get('/dashboard')->assertSee('Service requests');
    }

    public function test_manager_cannot_view_or_change_deals_or_agreements(): void
    {
        $this->actingAs($this->user('admin'))->post('/deals', $this->payload());
        $deal = PortalEntry::where('kind', 'deal')->firstOrFail();
        $this->post('/deals/'.$deal->id.'/generate');
        $doc = $deal->fresh()->data['document_id'];
        $this->post('/approvals/'.$doc, ['decision' => 'approved']);

        $this->actingAs($this->user('manager'));
        $this->get('/deals')->assertForbidden();
        $this->get('/deals/'.$deal->id)->assertForbidden();
        $this->post('/deals', $this->payload())->assertForbidden();
        $this->post('/deals/'.$deal->id.'/generate')->assertForbidden();
        $this->post('/deals/'.$deal->id.'/acknowledge', ['acknowledged' => 1])->assertForbidden();
        $this->post('/deals/'.$deal->id.'/sign', ['sandbox_ack' => 1])->assertForbidden();
        $this->post('/deals/'.$deal->id.'/signing-refresh')->assertForbidden();
        $this->get('/documents/'.$doc.'/download')->assertNotFound();
        $this->get('/documents')->assertDontSee('Deal FW-'.$deal->id);
        $this->get('/dashboard')->assertDontSee('Deals to prepare')->assertDontSee('Prepare a deal');
    }

    public function test_generated_deal_signing_requires_owner_and_verified_completion_before_publication(): void
    {
        $this->actingAs($this->user('admin'))->post('/deals', $this->payload());
        $deal = PortalEntry::where('kind', 'deal')->firstOrFail();
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
        $signed = PortalEntry::findOrFail($deal->fresh()->data['signed_document_id']);
        $this->assertSame('approved', $signed->data['status']);
        $this->assertSame($original, $signed->data['replaces_id']);
        $this->get('/documents/'.$signed->id.'/download')->assertOk();
        $this->post('/deals/'.$deal->id.'/signing-refresh')->assertRedirect();
        $this->assertSame(1, PortalEntry::where('kind', 'document')->whereField('replaces_id', $original)->count());
        $this->actingAs($this->user('admin'))->post('/approvals/'.$signed->id, ['decision' => 'approved'])->assertStatus(409);
        $this->actingAs($this->user('client'))->get('/documents/'.$signed->id.'/download')->assertOk();
        $this->get('/documents/'.$original.'/download')->assertOk();
        $this->post('/deals/'.$deal->id.'/sign', ['sandbox_ack' => 1])->assertStatus(409);
    }

    public function test_mixed_agreement_drafts_can_be_edited_until_publication_and_retain_old_pdf(): void
    {
        $wine = Arr::only($this->payload(), [...DomainRecords::ITEM, 'unit_price']);
        $whisky = ['type' => 'whisky', 'name' => 'Test cask', 'reference' => 'CASK-TEST', 'producer' => 'Test distillery', 'storage' => 'Test bond', 'quantity' => 1, 'unit_price' => '1000.00', 'cask_type' => 'Barrel', 'cask_size' => 200, 'ola' => 120, 'rla' => 110, 'abv' => 60, 'measurement_date' => now()->toDateString()];
        $payload = Arr::except($this->payload(), [...DomainRecords::ITEM, 'unit_price']);
        $payload['items'] = [$wine, $whisky];
        $payload['terms'] = 'Terms for mixed stock.';
        $this->actingAs($this->user('admin'))->post('/deals', $payload)->assertRedirect()->assertSessionHasNoErrors();
        $deal = PortalEntry::where('kind', 'deal')->firstOrFail();
        $this->assertCount(2, $deal->data['items']);
        $this->assertSame(700000, $deal->data['totals']['stock']);
        $this->post('/deals/'.$deal->id.'/generate')->assertRedirect();
        $original = $deal->fresh()->data['document_id'];
        $path = PortalEntry::find($original)->data['path'];
        $hash = hash('sha256', Storage::disk('local')->get($path));
        $payload['items'][0]['quantity'] = 30;
        $this->post('/deals/'.$deal->id.'/edit', $payload)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('draft', $deal->fresh()->status);
        $this->assertSame(850000, $deal->fresh()->data['totals']['stock']);
        $this->assertSame('rejected', PortalEntry::find($original)->status);
        $this->assertSame($hash, hash('sha256', Storage::disk('local')->get($path)));
        $this->post('/deals/'.$deal->id.'/generate')->assertRedirect();
        $source = $deal->fresh()->data['document_id'];
        $this->assertNotSame($original, $source);
        $this->post('/approvals/'.$source, ['decision' => 'approved'])->assertRedirect();
        $this->post('/deals/'.$deal->id.'/edit', $payload)->assertStatus(409);
        $this->actingAs($this->user('manager'))->post('/deals/'.$deal->id.'/edit', $payload)->assertForbidden();
    }

    public function test_holdings_need_their_signed_agreement_and_explicit_release(): void
    {
        $client = $this->user('client');
        $holding = Holding::where('client_id', $client->id)->first();
        $this->actingAs($client)->get('/holding/'.$holding->id)->assertNotFound();
        $this->get('/dashboard')->assertRedirect('/deals');
        $signed = $this->signedAgreement($client);
        $this->actingAs($this->user('admin'))->post('/holdings/'.$holding->id.'/agreement', ['agreement_id' => $signed->id])->assertRedirect();
        $this->actingAs($client)->get('/holding/'.$holding->id)->assertNotFound();
        $this->actingAs($this->user('manager'))->post('/holdings/'.$holding->id.'/release')->assertForbidden();
        $this->actingAs($this->user('admin'))->post('/holdings/'.$holding->id.'/release')->assertRedirect();
        $this->actingAs($client)->get('/holding/'.$holding->id)->assertOk();
        $this->get('/dashboard')->assertOk();
        $this->actingAs($this->user('admin'))->post('/deals', $this->payload())->assertRedirect();
        $draft = PortalEntry::where('kind', 'deal')->latest('id')->first();
        $new = Holding::where('client_id', $client->id)->where('id', '!=', $holding->id)->first();
        $this->post('/holdings/'.$new->id.'/agreement', ['agreement_id' => $draft->id])->assertRedirect();
        $this->post('/holdings/'.$new->id.'/release')->assertStatus(409);
        $this->actingAs($client)->get('/holding/'.$holding->id)->assertOk();
        $this->get('/holding/'.$new->id)->assertNotFound();
        $this->actingAs($this->user('admin'))->post('/holdings/'.$holding->id.'/agreement', ['agreement_id' => $draft->id])->assertStatus(409);
        $other = Holding::where('client_id', $this->user('james')->id)->first();
        $this->post('/holdings/'.$other->id.'/agreement', ['agreement_id' => $signed->id])->assertNotFound();
    }

    public function test_dashboard_timeline_shows_five_published_agreements_with_status_and_no_drafts(): void
    {
        $this->actingAs($this->user('admin'));
        $signed = [];
        for ($i = 0; $i < 6; $i++) {
            $signed[] = $this->signedAgreement($this->user('client'));
            $this->travel(1)->minutes();
        }
        $this->post('/deals', $this->payload())->assertRedirect();
        $published = PortalEntry::where('kind', 'deal')->latest('id')->firstOrFail();
        $this->post('/deals/'.$published->id.'/generate')->assertRedirect();
        $this->post('/approvals/'.$published->fresh()->data['document_id'], ['decision' => 'approved'])->assertRedirect();
        $this->travel(1)->minutes();
        $this->post('/deals', array_replace($this->payload(), ['name' => 'Unpublished draft item']))->assertRedirect();
        $draft = PortalEntry::where('kind', 'deal')->latest('id')->firstOrFail();
        $holding = Holding::where('client_id', $this->user('client')->id)->firstOrFail();
        foreach (['pending', 'pending', 'in progress', 'pending review', 'resolved', 'pending'] as $status) {
            PortalEntry::create(['client_id' => $holding->client_id, 'kind' => 'request', 'data' => ['holding_id' => $holding->id, 'holding_name' => $holding->name, 'type' => 'Inspection', 'notes' => 'Dashboard fixture', 'status' => $status]]);
            $this->travel(1)->minutes();
        }
        $response = $this->get('/dashboard')->assertOk()->assertDontSee('<table', false)->assertSee('Signed')->assertSee('Pending signature')
            ->assertSee('Inspection by '.$this->user('client')->name)->assertSee('Pending review')->assertSee('Resolved');
        $this->assertSame(5, substr_count($response->getContent(), 'data-dashboard-agreement='));
        $this->assertSame(5, substr_count($response->getContent(), 'data-dashboard-service='));
        $response->assertSee('data-dashboard-agreement="'.$published->id.'"', false)
            ->assertDontSee('data-dashboard-agreement="'.$draft->id.'"', false)
            ->assertDontSee('data-dashboard-agreement="'.$signed[0]->id.'"', false)
            ->assertDontSee('data-dashboard-agreement="'.$signed[1]->id.'"', false);
        $this->actingAs($this->user('manager'))->get('/dashboard')->assertOk()->assertDontSee('data-dashboard-agreement=', false)->assertSee('Inspection by '.$this->user('client')->name);
    }
}
