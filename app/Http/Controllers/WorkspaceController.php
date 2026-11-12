<?php

namespace App\Http\Controllers;

use App\Models\Holding;
use App\Models\PortalEntry;
use App\Models\User;
use App\Services\Portal;
use App\Services\Workflow;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WorkspaceController extends Controller
{
    public function activity(Request $request)
    {
        abort_unless($request->user()->role === 'admin', 403);
        $events = DB::table('audit_events')->leftJoin('users as actor', 'actor.id', '=', 'audit_events.actor_id')
            ->leftJoin('portal_entries as entry', 'entry.id', '=', 'audit_events.entry_id')
            ->leftJoin('users as client', 'client.id', '=', 'entry.client_id')
            ->leftJoin('users as subject', 'subject.id', '=', 'audit_events.subject_user_id')
            ->select('audit_events.*', 'actor.name as actor_name', 'entry.kind', 'client.name as client_name', 'subject.name as subject_name', 'subject.role as subject_role')
            ->orderByDesc('audit_events.id')->paginate(40);

        return view('workflow.activity', ['title' => 'Activity log', 'events' => $events]);
    }

    public function notifications(Request $request)
    {
        return view('workflow.notifications', ['title' => 'Notifications', 'notifications' => DB::table('portal_notifications')->where('user_id', $request->user()->id)->latest('id')->paginate(30)]);
    }

    public function readNotification(Request $request, int $id)
    {
        $notification = DB::table('portal_notifications')->where('user_id', $request->user()->id)->where('id', $id)->first();
        abort_unless($notification, 404);
        DB::table('portal_notifications')->where('id', $id)->update(['read_at' => now()->utc()]);

        return redirect($notification->url);
    }

    public function request(Request $request, int $id)
    {
        $entry = app(Portal::class)->record($request->user(), $id, 'request');
        $holding = app(Portal::class)->record($request->user(), $entry->data['holding_id'], 'holding');
        $updates = DB::table('holding_updates')->where('request_id', $id);
        if ($request->user()->role === 'client') {
            $updates->where('status', 'approved');
        }

        return view('workflow.request', ['title' => 'Service request', 'entry' => $entry, 'holding' => $holding, 'updates' => $updates->latest('id')->get()]);
    }

    public function link(Request $request, int $id)
    {
        abort_unless($request->user()->role === 'admin', 403);
        $data = $request->validate(['agreement_id' => 'required|integer']);
        DB::transaction(function () use ($id, $data) {
            $holding = Holding::whereKey($id)->lockForUpdate()->firstOrFail();
            abort_if($holding->released_at || $holding->agreement_id, 409, 'An agreement link cannot be silently replaced.');
            $agreement = PortalEntry::where('kind', 'deal')->where('client_id', $holding->client_id)->findOrFail($data['agreement_id']);
            $holding->update(['agreement_id' => $agreement->id]);
            Workflow::audit('holding.agreement_linked', $id);
        });

        return back()->with('success', 'Holding linked. It stays hidden until signing and release.');
    }

    public function release(Request $request, int $id)
    {
        abort_unless($request->user()->role === 'admin', 403);
        DB::transaction(function () use ($id) {
            $holding = Holding::whereKey($id)->lockForUpdate()->firstOrFail();
            $agreement = PortalEntry::where('kind', 'deal')->where('client_id', $holding->client_id)->lockForUpdate()->findOrFail($holding->agreement_id);
            abort_unless(in_array($agreement->status, ['published', 'reviewed']) && ($agreement->data['signing_status'] ?? '') === 'completed'
                && ! empty($agreement->data['signed_document_id']) && Workflow::approved(User::findOrFail($holding->client_id)), 409, 'Approve onboarding and verify the signed agreement first.');
            if (! $holding->released_at) {
                $holding->update(['released_at' => now()->utc()]);
                Workflow::audit('holding.released', $id);
                Workflow::notify([$holding->client_id], 'Your holding is now available.', '/holding/'.$id);
            }
        });

        return back()->with('success', 'Holding released to its client.');
    }
}
