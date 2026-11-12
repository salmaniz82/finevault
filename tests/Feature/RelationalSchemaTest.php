<?php

namespace Tests\Feature;

use App\Models\DocumentVersion;
use App\Models\Holding;
use App\Models\PortalEntry;
use App\Models\User;
use App\Services\RelationalMigration;
use Database\Seeders\PrototypeSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RelationalSchemaTest extends TestCase
{
    use RefreshDatabase, \Tests\Support\WorkflowFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(PrototypeSeeder::class);
    }

    public function test_core_data_lives_in_typed_tables_and_assignments_are_relational(): void
    {
        $this->assertFalse(Schema::hasColumn('portal_entries', 'data'));
        $this->assertFalse(Schema::hasColumn('users', 'manager_ids'));
        $this->assertFalse(Schema::hasTable('portal_records'));
        $this->assertDatabaseCount('roles', 3);
        $this->assertDatabaseCount('holdings', 7);
        $this->assertDatabaseCount('whisky_holdings', 3);
        $this->assertDatabaseCount('wine_holdings', 4);
        $this->assertDatabaseCount('client_manager_assignments', 2);
        $this->assertDatabaseCount('document_versions', 6);
        $this->assertDatabaseCount('documents', 5);
        $this->assertDatabaseCount('request_events', 1);
        $this->assertSame(7, app(RelationalMigration::class)->verify()['holding']);
    }

    public function test_database_rejects_cross_client_document_links_even_without_controller_validation(): void
    {
        $version = DocumentVersion::whereNotNull('holding_id')->firstOrFail();
        $other = Holding::where('client_id', '!=', $version->client_id)->firstOrFail();
        $this->expectException(QueryException::class);
        DB::table('document_versions')->where('id', $version->id)->update(['holding_id' => $other->id]);
    }

    public function test_database_rejects_unknown_roles_and_protects_referenced_holdings(): void
    {
        $admin = User::where('role', 'admin')->firstOrFail();
        try {
            DB::table('users')->where('id', $admin->id)->update(['role' => 'made_up']);
            $this->fail('Unknown role was accepted.');
        } catch (QueryException) {
            $this->assertDatabaseHas('users', ['id' => $admin->id, 'role' => 'admin']);
        }
        $this->expectException(QueryException::class);
        DB::table('holdings')->where('id', Holding::firstOrFail()->id)->delete();
    }

    public function test_failed_detail_write_rolls_back_the_identity_and_audit_event(): void
    {
        $entries = PortalEntry::count();
        $audit = DB::table('audit_events')->count();
        try {
            PortalEntry::create(['client_id' => User::where('role', 'client')->first()->id, 'kind' => 'holding', 'data' => ['status' => 'In storage', 'unknown_business_field' => 1]]);
            $this->fail('Unmapped input was silently discarded.');
        } catch (\InvalidArgumentException) {
            $this->assertSame($entries, PortalEntry::count());
            $this->assertSame($audit, DB::table('audit_events')->count());
        }
        $data = PortalEntry::where('kind', 'holding')->firstOrFail()->data;
        $data['quantity'] = 2;
        try {
            PortalEntry::create(['client_id' => User::where('role', 'client')->first()->id, 'kind' => 'holding', 'data' => $data]);
            $this->fail('Invalid whisky quantity was accepted.');
        } catch (\InvalidArgumentException) {
            $this->assertSame($entries, PortalEntry::count());
            $this->assertSame(7, Holding::count());
            $this->assertSame($audit, DB::table('audit_events')->count());
        }
    }

    public function test_approved_file_content_is_immutable_and_review_retains_actor_identity(): void
    {
        $pending = PortalEntry::where('kind', 'document')->where('status', 'pending')->firstOrFail();
        $admin = User::where('role', 'admin')->firstOrFail();
        $this->actingAs($admin)->post('/approvals/'.$pending->id, ['decision' => 'approved'])->assertRedirect();
        $this->assertDatabaseHas('document_reviews', ['document_version_id' => $pending->id, 'reviewed_by_id' => $admin->id, 'decision' => 'approved']);
        $this->assertDatabaseHas('audit_events', ['entry_id' => $pending->id, 'actor_id' => $admin->id, 'previous_status' => 'pending', 'new_status' => 'approved']);
        $this->post('/approvals/'.$pending->id, ['decision' => 'approved'])->assertStatus(409);
        $this->expectException(\InvalidArgumentException::class);
        $pending->fresh()->update(['data' => array_replace($pending->fresh()->data, ['path' => 'documents/replaced.pdf'])]);
    }

    public function test_acknowledgement_uniqueness_and_cross_client_constraints_are_enforced(): void
    {
        $doc = PortalEntry::where('kind', 'document')->whereField('category', 'Onboarding')->firstOrFail();
        $client = User::findOrFail($doc->client_id);
        $this->approveClient($client);
        $this->actingAs($client)->post('/onboarding/'.$doc->id.'/acknowledge', ['acknowledged' => 1])->assertRedirect();
        $this->post('/onboarding/'.$doc->id.'/acknowledge', ['acknowledged' => 1])->assertRedirect();
        $this->assertDatabaseCount('document_acknowledgements', 1);
        $this->expectException(QueryException::class);
        PortalEntry::create(['client_id' => $client->id, 'kind' => 'onboarding_ack', 'data' => ['document_id' => $doc->id, 'acknowledged_at' => now()->toIso8601String()]]);
    }
}
