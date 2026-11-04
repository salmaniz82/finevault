<?php

namespace Tests\Feature;

use App\Models\PortalEntry;
use App\Services\RelationalMigration;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RelationalBackfillTest extends TestCase
{
    public function test_unknown_legacy_fields_abort_atomically_then_valid_data_backfills_without_changing_ids(): void
    {
        Storage::fake('local');
        Artisan::call('migrate:fresh', ['--force' => true, '--path' => [
            'database/migrations/0001_01_01_000000_create_users_table.php',
            'database/migrations/2026_10_27_000001_create_prototype_records.php',
        ]]);
        $now = now()->format('Y-m-d H:i:s');
        DB::table('users')->insert([
            ['id' => 10, 'name' => 'Staff', 'email' => 'staff@migration.test', 'password' => 'unused', 'role' => 'manager', 'manager_ids' => '[]', 'created_at' => $now, 'updated_at' => $now],
            ['id' => 20, 'name' => 'Client', 'email' => 'client@migration.test', 'password' => 'unused', 'role' => 'client', 'manager_ids' => '[10]', 'created_at' => $now, 'updated_at' => $now],
        ]);
        $holding = ['type' => 'whisky', 'name' => 'Cask', 'reference' => 'IMPORT-001', 'producer' => 'Producer', 'quantity' => 1, 'storage' => 'Warehouse', 'acquired' => '2026-01-01', 'status' => 'In storage', 'abv' => 0, 'litres' => 200.25];
        Storage::disk('local')->put('documents/import.pdf', '%PDF-original');
        $document = ['title' => 'Ownership', 'category' => 'Ownership', 'visibility' => 'client', 'series' => 'import-series', 'holding_id' => 100, 'status' => 'approved', 'document_date' => '2026-01-01', 'path' => 'documents/import.pdf', 'original_name' => 'import.pdf', 'uploaded_by' => 'Staff', 'uploaded_by_id' => 10, 'reviewed_by' => 'Historic reviewer', 'reviewed_at' => '2026-01-02T12:00:00+05:00'];
        foreach ([100 => ['holding', $holding + ['unmapped' => true]], 150 => ['document', $document]] as $id => [$kind, $data]) {
            DB::table('portal_records')->insert(['id' => $id, 'client_id' => 20, 'kind' => $kind, 'data' => json_encode($data), 'created_at' => $now, 'updated_at' => $now]);
        }
        Artisan::call('migrate', ['--force' => true, '--path' => 'database/migrations/2026_11_05_000001_create_relational_portal.php']);
        try {
            app(RelationalMigration::class)->backfill();
            $this->fail('Unknown legacy field should stop the migration.');
        } catch (\InvalidArgumentException) {
            $this->assertDatabaseCount('portal_entries', 0);
            $this->assertDatabaseCount('client_profiles', 0);
            $this->assertDatabaseCount('client_manager_assignments', 0);
            $this->assertDatabaseCount('portal_records', 2);
        }
        DB::table('portal_records')->where('id', 100)->update(['data' => json_encode($holding)]);
        Artisan::call('migrate', ['--force' => true]);
        $this->assertFalse(Schema::hasTable('portal_records'));
        $this->assertDatabaseCount('legacy_portal_records', 2);
        $this->assertDatabaseHas('client_manager_assignments', ['client_id' => 20, 'manager_id' => 10]);
        $this->assertDatabaseHas('document_reviews', ['document_version_id' => 150, 'reviewed_at' => '2026-01-02 07:00:00', 'reviewed_by_id' => null]);
        $this->assertSame(0.0, PortalEntry::findOrFail(100)->data['abv']);
        $this->assertSame(200.25, PortalEntry::findOrFail(100)->data['litres']);
        $this->assertSame(hash('sha256', '%PDF-original'), PortalEntry::findOrFail(150)->data['sha256']);
        $this->assertSame('%PDF-original', Storage::disk('local')->get('documents/import.pdf'));
        $this->assertSame(2, array_sum(app(RelationalMigration::class)->verify()));
        $this->assertSame(0, Artisan::call('migrate', ['--force' => true]));
    }

    protected function tearDown(): void
    {
        RefreshDatabaseState::$migrated = false;
        parent::tearDown();
    }
}
