<?php

namespace App\Services;

use App\Models\PortalRecord;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class Portal
{
    public function clients(User $user): Builder
    {
        $query = User::where('role', 'client');

        return match ($user->role) {
            'admin' => $query,
            'manager' => $query->whereJsonContains('manager_ids', $user->id),
            default => $query->whereKey($user->id),
        };
    }

    public function records(User $user, ?string $kind = null): Builder
    {
        $query = PortalRecord::whereIn('client_id', $this->clients($user)->select('id'));
        if ($kind) {
            $query->where('kind', $kind);
        }
        if ($user->role === 'client') {
            $query->where('kind', '!=', 'profile');
            $query->where(function ($q) {
                $q->where('kind', '!=', 'document')->orWhere(function ($q) {
                    $q->where('data->status', 'approved')->where('data->visibility', 'client')->where('data->category', '!=', 'KYC');
                });
            });
        }

        return $query;
    }

    public function client(User $user, int $id): User
    {
        return $this->clients($user)->findOrFail($id);
    }

    public function record(User $user, int $id, ?string $kind = null): PortalRecord
    {
        return $this->records($user, $kind)->findOrFail($id);
    }

    public function review(User $actor, int $id, string $decision, ?string $reason): void
    {
        abort_unless($actor->role === 'admin', 403);
        DB::transaction(function () use ($actor, $id, $decision, $reason) {
            $record = PortalRecord::lockForUpdate()->findOrFail($id);
            abort_unless(in_array($record->kind, ['document', 'profile']) && $record->data['status'] === 'pending', 409);
            $data = array_merge($record->data, ['status' => $decision, 'reviewed_by' => $actor->name, 'reviewed_at' => now()->toIso8601String(), 'review_note' => $reason]);
            $record->update(['data' => $data]);
            if ($record->kind === 'profile' && $decision === 'approved') {
                User::findOrFail($record->client_id)->update(['name' => $data['name']]);
            }
        });
    }
}
