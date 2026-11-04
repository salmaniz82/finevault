<?php

namespace Tests\Feature;

use App\Models\PortalEntry;
use App\Models\User;
use App\Services\Portal;
use Database\Seeders\PrototypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class MySqlConcurrencyTest extends TestCase
{
    public function test_competing_approvals_serialize_and_publish_only_one_review(): void
    {
        if (config('database.default') !== 'mysql') {
            $this->markTestSkipped('Two-connection row-lock behavior is verified by the MySQL suite.');
        }
        Artisan::call('migrate:fresh', ['--force' => true]);
        Storage::fake('local');
        $this->seed(PrototypeSeeder::class);
        $admin = User::where('role', 'admin')->firstOrFail();
        $entry = PortalEntry::where('kind', 'document')->where('status', 'pending')->firstOrFail();
        $this->actingAs($admin);
        $connection = config('database.connections.mysql');
        $worker = new Process([PHP_BINARY, base_path('tests/Support/approval-worker.php'), (string) $entry->id, (string) $admin->id], base_path(), [
            'APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => 'fineworth_portal_relational_test', 'DB_URL' => '',
            'DB_HOST' => $connection['host'], 'DB_PORT' => (string) $connection['port'], 'DB_USERNAME' => $connection['username'], 'DB_PASSWORD' => $connection['password'],
            'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array',
        ]);
        $worker->setTimeout(15);
        DB::beginTransaction();
        try {
            PortalEntry::whereKey($entry->id)->lockForUpdate()->firstOrFail();
            $worker->start();
            $this->assertTrue($worker->waitUntil(fn ($type, $output) => str_contains($output, 'READY')));
            app(Portal::class)->review($admin, $entry->id, 'approved', null);
            DB::commit();
            $worker->wait();
            $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput());
            $this->assertStringContainsString('ALREADY_REVIEWED', $worker->getOutput());
            $this->assertSame(1, DB::table('document_reviews')->where('document_version_id', $entry->id)->count());
            $this->assertSame('approved', $entry->fresh()->status);
        } finally {
            if (DB::transactionLevel()) {
                DB::rollBack();
            }
            if ($worker->isRunning()) {
                $worker->stop();
            }
        }
    }

    protected function tearDown(): void
    {
        RefreshDatabaseState::$migrated = false;
        parent::tearDown();
    }
}
