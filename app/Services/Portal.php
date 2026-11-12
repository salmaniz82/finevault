<?php

namespace App\Services;

use App\Models\PortalEntry;
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
            'manager' => $query->whereHas('managers', fn ($q) => $q->where('client_manager_assignments.manager_id', $user->id)),
            default => $query->whereKey($user->id),
        };
    }

    public function records(User $user, ?string $kind = null): Builder
    {
        $query = PortalEntry::whereIn('client_id', $this->clients($user)->select('id'));
        if ($kind) {
            $query->where('kind', $kind);
        }
        if ($user->role === 'manager') {
            $query->where('kind', '!=', 'deal')
                ->where(function ($q) {
                    $q->where('kind', '!=', 'document')->orWhereHas('documentVersion.document', fn ($doc) => $doc->where('category', '!=', 'Agreement'));
                });
        }
        if ($user->role === 'client') {
            if (! Workflow::approved($user)) {
                $query->whereRaw('1 = 0');
            }
            $visibleHolding = fn ($holding) => $holding->released();
            $query->where(fn ($q) => $q->where('kind', '!=', 'holding')->orWhereHas('holding', $visibleHolding));
            $query->where(fn ($q) => $q->where('kind', '!=', 'request')->orWhereHas('serviceRequest.holding', $visibleHolding));
            $query->where(fn ($q) => $q->where('kind', '!=', 'document')->orWhereHas('documentVersion', fn ($v) => $v->whereNull('holding_id')->orWhereHas('holding', $visibleHolding)));
            $query->where(function ($q) {
                $q->where('kind', '!=', 'deal')->orWhereIn('status', ['published', 'reviewed']);
            });
            $query->where('kind', '!=', 'profile');
            $query->where(function ($q) {
                $q->where('kind', '!=', 'document')->orWhere(function ($q) {
                    $q->where('status', 'approved')->whereHas('documentVersion.document', fn ($doc) => $doc->where('visibility', 'client')->where('category', '!=', 'KYC'));
                });
            });
        }

        return $query->with(['holding.whisky', 'holding.wine', 'documentVersion.document', 'documentVersion.reviews', 'deal.items', 'deal.fees', 'deal.deposits', 'deal.signingRequests', 'serviceRequest.events', 'profileChange', 'acknowledgement', 'directSignature']);
    }

    public function client(User $user, int $id): User
    {
        return $this->clients($user)->findOrFail($id);
    }

    public function record(User $user, int $id, ?string $kind = null): PortalEntry
    {
        return $this->records($user, $kind)->findOrFail($id);
    }

    public function review(User $actor, int $id, string $decision, ?string $reason): void
    {
        abort_unless($actor->role === 'admin', 403);
        DB::transaction(function () use ($actor, $id, $decision, $reason) {
            $record = PortalEntry::lockForUpdate()->findOrFail($id);
            abort_unless($record->kind === 'document', 409, 'Review records in their workflow.');
            abort_unless(in_array($record->kind, ['document', 'profile']) && $record->data['status'] === 'pending', 409);
            $data = array_merge($record->data, ['status' => $decision, 'reviewed_by' => $actor->name, 'reviewed_by_id' => $actor->id, 'reviewed_at' => now()->toIso8601String(), 'review_note' => $reason]);
            $record->update(['data' => $data]);
            if ($record->kind === 'document' && isset($data['deal_id'])) {
                $deal = PortalEntry::where('kind', 'deal')->lockForUpdate()->findOrFail($data['deal_id']);
                abort_unless(($deal->data['document_id'] ?? null) === $record->id && $deal->client_id === $record->client_id, 409);
                $deal->update(['data' => array_merge($deal->data, ['status' => $decision === 'approved' ? 'published' : 'rejected', 'review_note' => $reason])]);
                if ($decision === 'approved') {
                    Workflow::audit('agreement.published', $deal->id);
                    Workflow::notify([$deal->client_id], 'An agreement is awaiting your signature.', '/deals/'.$deal->id);
                }
            }
            if ($record->kind === 'profile' && $decision === 'approved') {
                User::findOrFail($record->client_id)->update(['name' => $data['name']]);
            }
        });
    }
}
