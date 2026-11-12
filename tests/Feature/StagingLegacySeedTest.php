<?php

namespace Tests\Feature;

use App\Services\StagingDemo;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StagingLegacySeedTest extends TestCase
{
    public function test_missing_legacy_user_is_restored_before_backfill_then_full_snapshot_seeds(): void
    {
        config(['staging-demo.enabled' => true]);
        Storage::fake('local');
        Artisan::call('migrate:fresh', ['--force' => true, '--path' => [
            'database/migrations/0001_01_01_000000_create_users_table.php',
            'database/migrations/2026_10_27_000001_create_prototype_records.php',
        ]]);
        $now = now()->format('Y-m-d H:i:s');
        // Reproduce an orphan from the server without disabling foreign keys.
        Schema::disableForeignKeyConstraints();
        DB::table('portal_records')->insert([
            'id' => 100, 'client_id' => 6, 'kind' => 'holding',
            'data' => json_encode(['type' => 'wine', 'name' => 'Legacy demo', 'reference' => 'LEGACY-100', 'producer' => 'Demo', 'quantity' => 6, 'storage' => 'Demo warehouse', 'acquired' => '2026-01-01', 'status' => 'In storage']),
            'created_at' => $now, 'updated_at' => $now,
        ]);
        Schema::enableForeignKeyConstraints();
        $this->assertDatabaseMissing('users', ['id' => 6]);
        $this->assertSame(0, Artisan::call('db:seed', ['--class' => 'StagingUsersSeeder', '--force' => true]));
        $this->assertDatabaseHas('users', ['id' => 6, 'email' => 'evelyn.demo@fineworth.test']);
        $this->assertSame([2], json_decode(DB::table('users')->where('id', 6)->value('manager_ids'), true));
        $this->assertSame(0, Artisan::call('migrate', ['--force' => true]));
        $this->assertSame(0, Artisan::call('db:seed', ['--force' => true]));
        $this->assertDatabaseHas('legacy_portal_records', ['id' => 100, 'client_id' => 6]);
        $this->assertDatabaseHas('holdings', ['id' => 100, 'client_id' => 6]);
        $this->assertDatabaseHas('deals', ['id' => 18, 'document_id' => 19, 'client_id' => 6]);
        $this->assertDatabaseHas('document_versions', ['id' => 19, 'deal_id' => 18, 'client_id' => 6]);
        $this->assertSame(0, Artisan::call('portal:verify'));
        $this->assertDatabaseCount('portal_entries', 30);
    }

    protected function tearDown(): void
    {
        RefreshDatabaseState::$migrated = false;
        parent::tearDown();
    }
}
