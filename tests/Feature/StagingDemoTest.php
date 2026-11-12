<?php

namespace Tests\Feature;

use App\Services\StagingDemo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class StagingDemoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['staging-demo.enabled' => true]);
        Storage::fake('local');
    }

    public function test_full_local_demo_snapshot_is_repeatable_and_preserves_existing_user_edits(): void
    {
        $demo = app(StagingDemo::class);
        $demo->seed();
        $snapshot = json_decode(file_get_contents(database_path('seeders/fixtures/staging/snapshot.json')), true);
        foreach ($snapshot['tables'] as $table => $rows) {
            $this->assertDatabaseCount($table, count($rows));
        }
        foreach ($snapshot['files'] as $path => $hash) {
            $this->assertSame($hash, hash('sha256', Storage::disk('local')->get($path)));
        }
        $password = DB::table('users')->where('id', 6)->value('password');
        DB::table('users')->where('id', 6)->update(['name' => 'Existing staging name']);
        $demo->seed();
        $this->assertDatabaseHas('users', ['id' => 6, 'name' => 'Existing staging name', 'password' => $password]);
        $this->assertDatabaseCount('portal_entries', 29);
        $this->assertDatabaseHas('deals', ['id' => 18, 'client_id' => 6]);
        $this->assertDatabaseHas('document_versions', ['id' => 19, 'client_id' => 6]);
        $this->assertDatabaseHas('client_manager_assignments', ['client_id' => 6]);
        $this->assertSame(0, Artisan::call('portal:verify'));
    }

    public function test_conflicting_user_identity_is_never_reassigned(): void
    {
        app(StagingDemo::class)->users();
        DB::table('users')->where('id', 6)->update(['email' => 'different@example.test']);
        try {
            app(StagingDemo::class)->seed();
            $this->fail('Expected conflict');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('identity conflict', $e->getMessage());
            $this->assertDatabaseHas('users', ['id' => 6, 'email' => 'different@example.test']);
            $this->assertDatabaseCount('portal_entries', 0);
        }
    }

    public function test_existing_private_bytes_are_never_replaced(): void
    {
        Storage::disk('local')->put('documents/sample-1.pdf', 'existing original bytes');
        try {
            app(StagingDemo::class)->seed();
            $this->fail('Expected file conflict');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Existing private file differs', $e->getMessage());
            $this->assertSame('existing original bytes', Storage::disk('local')->get('documents/sample-1.pdf'));
            $this->assertDatabaseCount('portal_entries', 0);
        }
    }

    public function test_demo_seed_requires_explicit_opt_in(): void
    {
        config(['staging-demo.enabled' => false]);
        $this->expectException(RuntimeException::class);
        app(StagingDemo::class)->seed();
    }
}
