<?php

namespace Tests\Feature;

use App\Models\PortalEntry;
use App\Models\User;
use App\Services\Portal;
use Database\Seeders\PrototypeSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class MySqlConcurrencyTest extends TestCase
{
    public function test_competing_approvals_serialize_and_publish_only_one_review(): void
    {
        if (config('database.default') !== 'mysql') {
            $this->markTestSkipped('Two-connection row locks require MySQL.');
        }
        Artisan::call('migrate:fresh', ['--force' => true]);
        Storage::fake('local');
        $this->seed(PrototypeSeeder::class);
        $admin = User::where('role', 'admin')->firstOrFail();
        $entry = PortalEntry::where('kind', 'document')->where('status', 'pending')->firstOrFail();
        $this->actingAs($admin);
        config(['database.connections.competitor' => config('database.connections.mysql')]);
        $primary = DB::connection('mysql');
        $competitor = DB::connection('competitor');
        $competitor->statement('SET SESSION innodb_lock_wait_timeout = 1');
        $primary->beginTransaction();
        try {
            $primary->table('portal_entries')->where('id', $entry->id)->lockForUpdate()->first();
            DB::setDefaultConnection('competitor');
            try {
                app(Portal::class)->review($admin, $entry->id, 'approved', null);
                $this->fail('A second connection bypassed the approval row lock.');
            } catch (QueryException $e) {
                $this->assertSame(1205, (int) $e->errorInfo[1]);
            }
            DB::setDefaultConnection('mysql');
            app(Portal::class)->review($admin, $entry->id, 'approved', null);
            $primary->commit();
            DB::setDefaultConnection('competitor');
            try {
                app(Portal::class)->review($admin, $entry->id, 'approved', null);
                $this->fail('The same version was reviewed twice.');
            } catch (HttpException $e) {
                $this->assertSame(409, $e->getStatusCode());
            }
            $this->assertSame(1, $competitor->table('document_reviews')->where('document_version_id', $entry->id)->count());
            $this->assertSame('approved', $competitor->table('portal_entries')->where('id', $entry->id)->value('status'));
        } finally {
            if ($primary->transactionLevel()) {
                $primary->rollBack();
            }
            if ($competitor->transactionLevel()) {
                $competitor->rollBack();
            }
            DB::setDefaultConnection('mysql');
            DB::purge('competitor');
        }
    }

    protected function tearDown(): void
    {
        RefreshDatabaseState::$migrated = false;
        parent::tearDown();
    }
}
