<?php

namespace App\Services;

use App\Models\Holding;
use App\Models\PortalEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class Workflow
{
    public static function approved(User $user): bool
    {
        return $user->role !== 'client' || $user->clientProfile?->onboarding_status === 'approved';
    }

    public static function fullAccess(User $user): bool
    {
        return $user->role !== 'client' || (self::approved($user) && Holding::where('client_id', $user->id)->released()->exists());
    }

    public static function audit(string $action, ?int $entry = null, ?int $client = null): void
    {
        DB::table('audit_events')->insert(['actor_id' => auth()->id(), 'entry_id' => $entry,
            'subject_user_id' => $client, 'action' => $action, 'occurred_at' => now()->utc()]);
    }

    public static function notify(iterable $ids, string $message, string $url): void
    {
        foreach (collect($ids)->unique() as $id) {
            DB::table('portal_notifications')->insert(['user_id' => $id, 'message' => $message, 'url' => $url, 'created_at' => now()->utc()]);
        }
    }

    public static function admins(): array
    {
        return User::where('role', 'admin')->pluck('id')->all();
    }

    public static function requestStatus(string $status): string
    {
        return match ($status) {
            'submitted' => 'pending', 'completed' => 'resolved', default => $status
        };
    }

    public static function agreementStatus(PortalEntry $deal): string
    {
        return ($deal->data['signing_status'] ?? '') === 'completed' ? 'Signed' : match ($deal->status) {
            'published', 'reviewed' => 'Pending signature', 'pending' => 'Draft for review', 'rejected' => 'Returned', default => 'Draft',
        };
    }

    public static function activityLabel(object $event): string
    {
        $kind = match ($event->kind) {
            'deal' => 'Agreement', 'holding' => 'Holding', 'document' => 'Document', 'request' => 'Service request', default => 'Record'
        };

        return match ($event->action) {
            'entry.created' => $kind.' created', 'entry.status_changed' => $kind.' status changed',
            'user.created' => ucfirst($event->subject_role ?? 'account').' account created',
            default => ucfirst(str_replace(['.', '_'], ' ', $event->action)),
        };
    }
}
