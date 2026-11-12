<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class StagingDemo
{
    // Parent-first order. Circular agreement/document and holding/agreement links
    // are completed only after all referenced identities have been inserted.
    public const TABLES = [
        'roles', 'document_types', 'users', 'client_profiles', 'client_manager_assignments',
        'portal_entries', 'holdings', 'whisky_holdings', 'wine_holdings', 'documents',
        'deals', 'deal_items', 'deal_fees', 'external_deposits', 'document_versions',
        'document_reviews', 'signing_requests', 'service_requests', 'request_events',
        'profile_changes', 'document_acknowledgements', 'direct_signatures',
        'holding_updates', 'holding_update_files', 'holding_publications',
        'audit_events', 'portal_notifications',
    ];

    private const KEYS = [
        'roles' => ['code'], 'document_types' => ['code'], 'client_profiles' => ['user_id'],
        'client_manager_assignments' => ['client_id', 'manager_id'],
        'whisky_holdings' => ['holding_id'], 'wine_holdings' => ['holding_id'],
    ];

    private function snapshot(): array
    {
        if (! config('staging-demo.enabled') || ! app()->environment('local', 'testing', 'staging', 'development')) {
            throw new RuntimeException('Demo replication requires PORTAL_DEMO_SEED=true and a non-production environment.');
        }
        return json_decode(file_get_contents(database_path('seeders/fixtures/staging/snapshot.json')), true, 512, JSON_THROW_ON_ERROR);
    }

    private function backup(): void
    {
        $tables = [];
        foreach ([...self::TABLES, 'portal_records', 'legacy_portal_records', 'migrations'] as $table) {
            if (Schema::hasTable($table)) {
                $tables[$table] = DB::table($table)->get()->all();
            }
        }
        Storage::disk('local')->put('backups/staging-seed/'.now()->format('Ymd-His').'-'.Str::uuid().'.json', json_encode($tables, JSON_THROW_ON_ERROR));
    }

    public function users(): void
    {
        $snapshot = $this->snapshot();
        $this->backup();
        DB::transaction(function () use ($snapshot) {
            foreach ($snapshot['tables']['users'] as $row) {
                $existing = DB::table('users')->where('id', $row['id'])->first();
                if ($existing) {
                    if ($existing->email !== $row['email'] || $existing->role !== $row['role']) {
                        throw new RuntimeException('Demo user identity conflict at ID '.$row['id'].'; reconcile explicitly.');
                    }
                    continue; // Retain staging passwords, verification and personal edits.
                }
                if (DB::table('users')->where('email', $row['email'])->exists()) {
                    throw new RuntimeException('Demo email already has another user ID; refusing automatic reassignment.');
                }
                $row['password'] = Hash::make(Str::random(64));
                $row['remember_token'] = null;
                if (Schema::hasColumn('users', 'manager_ids')) {
                    $row['manager_ids'] = json_encode($snapshot['manager_ids'][$row['id']] ?? []);
                }
                DB::table('users')->insert($row);
            }
        });
    }

    public function seed(): void
    {
        $snapshot = $this->snapshot();
        $this->users();
        // Verify all files before copying any. Never replace existing private bytes.
        foreach ($snapshot['files'] as $path => $hash) {
            if (str_contains($path, '..') || str_starts_with($path, '/') || str_contains($path, '\\')) {
                throw new RuntimeException('Unsafe demo file path.');
            }
            $source = database_path('seeders/fixtures/staging/'.$hash.'.pdf');
            if (! is_file($source) || ! hash_equals($hash, hash_file('sha256', $source))) {
                throw new RuntimeException('Demo asset checksum mismatch: '.$path);
            }
            if (Storage::disk('local')->exists($path) && ! hash_equals($hash, hash('sha256', Storage::disk('local')->get($path)))) {
                throw new RuntimeException('Existing private file differs: '.$path.'; retained without changes.');
            }
        }
        foreach ($snapshot['files'] as $path => $hash) {
            if (! Storage::disk('local')->exists($path)) {
                Storage::disk('local')->put($path, file_get_contents(database_path('seeders/fixtures/staging/'.$hash.'.pdf')));
            }
        }
        DB::transaction(function () use ($snapshot) {
            $links = [];
            foreach (self::TABLES as $table) {
                if ($table === 'users') {
                    continue;
                }
                foreach ($snapshot['tables'][$table] as $row) {
                    // Activity/notification IDs are local sequences, not domain
                    // identities. Preserve staging events and append missing ones.
                    if (in_array($table, ['audit_events', 'portal_notifications'])) {
                        $match = $row;
                        unset($match['id']);
                        if ($table === 'portal_notifications') {
                            unset($match['read_at']);
                        } elseif ($row['action'] === 'legacy.imported') {
                            unset($match['occurred_at']);
                        }
                        if (! DB::table($table)->where($match)->exists()) {
                            unset($row['id']);
                            DB::table($table)->insert($row);
                        }
                        continue;
                    }
                    $keys = self::KEYS[$table] ?? ['id'];
                    $identity = array_intersect_key($row, array_flip($keys));
                    $existing = DB::table($table)->where($identity)->first();
                    if ($existing) {
                        // These columns distinguish identities from mutable workflow state.
                        foreach (['client_id', 'kind', 'user_id', 'holding_id', 'deal_id', 'document_id', 'series', 'category', 'path', 'position', 'request_id', 'update_id', 'entry_id', 'subject_user_id', 'action', 'document_version_id', 'type', 'token'] as $field) {
                            if (array_key_exists($field, $row) && ($existing->$field ?? null) != $row[$field]) {
                                throw new RuntimeException('Demo identity conflict: '.$table.' '.json_encode($identity).' field '.$field.'; existing row retained.');
                            }
                        }
                        continue;
                    }
                    $deferred = $table === 'deals' ? ['document_id', 'signed_document_id'] : ($table === 'holdings' ? ['agreement_id'] : []);
                    foreach ($deferred as $field) {
                        if ($row[$field] !== null) {
                            $links[] = [$table, $identity, $field, $row[$field]];
                            $row[$field] = null;
                        }
                    }
                    DB::table($table)->insert($row);
                }
            }
            foreach ($links as [$table, $identity, $field, $value]) {
                DB::table($table)->where($identity)->update([$field => $value]);
            }
            app(RelationalMigration::class)->verify();
        });
    }
}
