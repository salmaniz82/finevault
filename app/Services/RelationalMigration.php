<?php

namespace App\Services;

use App\Models\PortalEntry;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class RelationalMigration
{
    public function backfill(): void
    {
        DB::transaction(function () {
            if (DB::table('portal_entries')->exists()) {
                throw new \RuntimeException('Backfill requires empty destination tables. Restore or reconcile before retrying.');
            }
            foreach (DB::table('users')->orderBy('id')->get() as $user) {
                if ($user->role === 'client') {
                    DB::table('client_profiles')->insert(['user_id' => $user->id, 'created_at' => $user->created_at, 'updated_at' => $user->updated_at]);
                }
            }
            foreach (DB::table('users')->orderBy('id')->get() as $user) {
                foreach (array_unique(json_decode($user->manager_ids ?? '[]', true, 512, JSON_THROW_ON_ERROR) ?? []) as $manager) {
                    if ($user->role !== 'client' || ! DB::table('users')->where('id', $manager)->where('role', 'manager')->exists()) {
                        throw new \RuntimeException('Invalid legacy manager assignment on user '.$user->id);
                    }
                    DB::table('client_manager_assignments')->insert(['client_id' => $user->id, 'manager_id' => $manager, 'created_at' => $user->created_at, 'updated_at' => $user->updated_at]);
                }
            }
            $records = DB::table('portal_records')->orderBy('id')->get();
            foreach ($records as $record) {
                $data = json_decode($record->data, true, 512, JSON_THROW_ON_ERROR);
                app(DomainRecords::class)->validate($record->kind, $data);
                DB::table('portal_entries')->insert(['id' => $record->id, 'client_id' => $record->client_id, 'kind' => $record->kind, 'status' => $data['status'] ?? null, 'created_at' => $record->created_at, 'updated_at' => $record->updated_at]);
            }
            // Create parents first. Deal-to-version links are populated after all versions exist.
            foreach (['holding', 'deal', 'document', 'request', 'profile', 'onboarding_ack', 'direct_signature_demo'] as $kind) {
                foreach ($records->where('kind', $kind) as $record) {
                    $data = json_decode($record->data, true, 512, JSON_THROW_ON_ERROR);
                    if ($kind === 'deal') {
                        $data = Arr::except($data, ['document_id', 'signed_document_id', 'envelope_id', 'signing_status']);
                    }
                    app(DomainRecords::class)->write(PortalEntry::findOrFail($record->id), $data);
                }
            }
            foreach ($records->where('kind', 'deal') as $record) {
                app(DomainRecords::class)->write(PortalEntry::findOrFail($record->id), json_decode($record->data, true, 512, JSON_THROW_ON_ERROR));
            }
            foreach ($records as $record) {
                DB::table('audit_events')->insert(['entry_id' => $record->id, 'action' => 'legacy.imported', 'new_status' => json_decode($record->data, true)['status'] ?? null, 'occurred_at' => now()->utc()]);
            }
            $this->verify('portal_records');
            foreach ($records as $record) {
                $old = json_decode($record->data, true, 512, JSON_THROW_ON_ERROR);
                unset($old['client_id']);
                $this->compare($old, PortalEntry::findOrFail($record->id)->data, 'record '.$record->id);
            }
        });
    }

    public function verify(?string $legacyTable = null): array
    {
        $legacyTable ??= Schema::hasTable('legacy_portal_records') ? 'legacy_portal_records' : null;
        $counts = [];
        $tables = ['holding' => 'holdings', 'deal' => 'deals', 'document' => 'document_versions', 'request' => 'service_requests', 'profile' => 'profile_changes', 'onboarding_ack' => 'document_acknowledgements', 'direct_signature_demo' => 'direct_signatures'];
        foreach ($tables as $kind => $table) {
            $expected = DB::table('portal_entries')->where('kind', $kind)->count();
            if ($expected !== DB::table($table)->count()) {
                throw new \RuntimeException('Detail count mismatch: '.$kind);
            }
            if (DB::table('portal_entries as e')->leftJoin($table.' as d', 'd.id', '=', 'e.id')->where('e.kind', $kind)->whereNull('d.id')->exists()) {
                throw new \RuntimeException('Missing detail identity: '.$kind);
            }
            $counts[$kind] = $expected;
        }
        if ($legacyTable) {
            foreach (DB::table($legacyTable)->orderBy('id')->get() as $old) {
                $entry = PortalEntry::findOrFail($old->id);
                if ($entry->kind !== $old->kind || (int) $entry->client_id !== (int) $old->client_id) {
                    throw new \RuntimeException('Legacy identity mismatch: '.$old->id);
                }
                // Original rows are retained unchanged; mutable workflow status may legitimately advance after cutover.
                $data = json_decode($old->data, true, 512, JSON_THROW_ON_ERROR);
                if (in_array($old->kind, ['document', 'direct_signature_demo']) && isset($data['path']) && $entry->data['path'] !== $data['path']) {
                    throw new \RuntimeException('Private file reference changed: '.$old->id);
                }
            }
        }
        foreach (PortalEntry::orderBy('id')->get() as $entry) {
            $data = $entry->data; // Hydrates every required relationship and commercial total.
            if ($entry->kind === 'deal' && DealAgreement::totals($data) != $data['totals']) {
                throw new \RuntimeException('Deal totals mismatch: '.$entry->id);
            }
            if ($entry->kind === 'document' && $data['category'] === 'KYC' && $data['visibility'] !== 'staff') {
                throw new \RuntimeException('Unsafe KYC classification: '.$entry->id);
            }
        }

        return $counts;
    }

    private function compare(array $expected, array $actual, string $path): void
    {
        foreach ($expected as $field => $value) {
            $found = $actual[$field] ?? null;
            if (is_array($value) && is_array($found)) {
                $this->compare($value, $found, $path.'.'.$field);
                if (array_is_list($value) && count($value) !== count($found)) {
                    throw new \RuntimeException('Backfill list mismatch: '.$path.'.'.$field);
                }

                continue;
            }
            if ($value !== null && in_array($field, ['reviewed_at', 'acknowledged_at', 'signed_at', 'at'], true)) {
                $value = Carbon::parse($value)->utc()->format('Y-m-d H:i:s');
            }
            if ($value != $found) {
                throw new \RuntimeException('Backfill value mismatch: '.$path.'.'.$field);
            }
        }
    }
}
