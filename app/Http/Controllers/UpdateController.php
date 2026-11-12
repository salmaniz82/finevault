<?php

namespace App\Http\Controllers;

use App\Models\PortalEntry;
use App\Models\User;
use App\Services\Portal;
use App\Services\Workflow;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class UpdateController extends Controller
{
    public function store(Request $request, int $id)
    {
        abort_if($request->user()->role === 'client', 403);
        $holding = app(Portal::class)->record($request->user(), $id, 'holding');
        $data = $request->validate(['title' => 'required|string|max:150', 'notes' => 'required|string|max:4000',
            'request_id' => 'nullable|integer', 'files' => 'nullable|array|max:15', 'files.*' => 'file|mimes:pdf,jpg,jpeg,png|max:10240',
            'storage' => 'nullable|string|max:150', 'abv' => 'nullable|numeric|min:0|max:100', 'litres' => 'nullable|numeric|min:0|max:10000']);
        $paths = [];
        try {
            DB::transaction(function () use ($request, $holding, $data, &$paths) {
                $locked = PortalEntry::whereKey($holding->id)->lockForUpdate()->firstOrFail();
                abort_if(DB::table('holding_updates')->where('holding_id', $holding->id)->where('status', 'pending review')->exists(), 409, 'Review the existing update before submitting another.');
                $service = null;
                if (! empty($data['request_id'])) {
                    $service = app(Portal::class)->record($request->user(), $data['request_id'], 'request');
                    $service = PortalEntry::whereKey($service->id)->lockForUpdate()->firstOrFail();
                    abort_unless($service->client_id === $holding->client_id && $service->data['holding_id'] === $holding->id
                        && Workflow::requestStatus($service->status) === 'in progress', 409);
                } else {
                    abort_unless($request->user()->role === 'admin', 403, 'Manager updates must belong to a client service request.');
                }
                $snapshot = $locked->data;
                unset($snapshot['status']);
                if (! DB::table('holding_updates')->where('holding_id', $holding->id)->exists()) {
                    DB::table('holding_updates')->insert(['holding_id' => $holding->id, 'client_id' => $holding->client_id,
                        'title' => 'Original recorded details', 'notes' => 'Preserved before the first update.', 'status' => 'approved',
                        'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR), 'submitted_by_id' => $request->user()->id, 'created_at' => now()->utc(), 'updated_at' => now()->utc()]);
                }
                foreach (['storage', 'abv', 'litres'] as $field) {
                    if (filled($data[$field] ?? null)) {
                        abort_if($field !== 'storage' && $snapshot['type'] !== 'whisky', 422);
                        $snapshot[$field] = $data[$field];
                    }
                }
                $update = DB::table('holding_updates')->insertGetId(['holding_id' => $holding->id, 'client_id' => $holding->client_id,
                    'request_id' => $service?->id, 'title' => $data['title'], 'notes' => $data['notes'], 'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
                    'status' => 'pending review', 'submitted_by_id' => $request->user()->id, 'created_at' => now()->utc(), 'updated_at' => now()->utc()]);
                foreach ($request->file('files', []) as $file) {
                    $path = $file->store('holding-updates', 'local');
                    $paths[] = $path;
                    DB::table('holding_update_files')->insert(['update_id' => $update, 'path' => $path, 'original_name' => $file->getClientOriginalName(), 'sha256' => hash_file('sha256', Storage::disk('local')->path($path))]);
                }
                if ($service) {
                    $this->transition($service, 'pending review', 'Results submitted for admin review.');
                }
                Workflow::audit('holding.update_submitted', $holding->id);
                Workflow::notify(Workflow::admins(), 'A holding update package awaits review.', $service ? '/requests/'.$service->id : '/holding/'.$holding->id);
            });
        } catch (\Throwable $e) {
            foreach ($paths as $path) {
                Storage::disk('local')->delete($path);
            }
            throw $e;
        }

        return back()->with('success', 'Update package submitted for review.');
    }

    public function review(Request $request, int $id)
    {
        abort_unless($request->user()->role === 'admin', 403);
        $data = $request->validate(['decision' => 'required|in:approved,returned', 'reason' => 'nullable|required_if:decision,returned|string|max:1000']);
        DB::transaction(function () use ($request, $id, $data) {
            $reference = DB::table('holding_updates')->where('id', $id)->first();
            abort_unless($reference, 404);
            $holding = PortalEntry::whereKey($reference->holding_id)->lockForUpdate()->firstOrFail();
            $update = DB::table('holding_updates')->where('id', $id)->lockForUpdate()->first();
            abort_unless($update->status === 'pending review', 409);
            $service = $update->request_id ? PortalEntry::whereKey($update->request_id)->lockForUpdate()->firstOrFail() : null;
            if ($service) {
                abort_unless($service->status === 'pending review', 409);
            }
            DB::table('holding_updates')->where('id', $id)->update(['status' => $data['decision'], 'review_note' => $data['reason'] ?? null,
                'reviewed_by_id' => $request->user()->id, 'reviewed_at' => now()->utc(), 'updated_at' => now()->utc()]);
            if ($data['decision'] === 'approved') {
                $this->publish($holding, $update, $data['reason'] ?? null);
            }
            if ($service) {
                $this->transition($service, $data['decision'] === 'approved' ? 'resolved' : 'in progress', $data['decision'] === 'approved' ? 'Results approved and request resolved.' : 'Results returned for correction.');
            }
            Workflow::audit('holding.update_'.$data['decision'], $holding->id);
            Workflow::notify(collect(User::findOrFail($holding->client_id)->manager_ids)->push($update->submitted_by_id)->all(),
                'Holding update '.$data['decision'].'.', $service ? '/requests/'.$service->id : '/holding/'.$holding->id);
        });

        return back()->with('success', 'Update package '.$data['decision'].'.');
    }

    public function restore(Request $request, int $id)
    {
        abort_unless($request->user()->role === 'admin', 403);
        $data = $request->validate(['reason' => 'required|string|max:1000']);
        DB::transaction(function () use ($id, $data) {
            $update = DB::table('holding_updates')->where('id', $id)->first();
            abort_unless($update, 404);
            $holding = PortalEntry::whereKey($update->holding_id)->lockForUpdate()->firstOrFail();
            abort_unless($update->status === 'approved', 409);
            abort_if(DB::table('holding_updates')->where('holding_id', $holding->id)->where('status', 'pending review')->exists(), 409, 'Review the pending package before restoring history.');
            $this->publish($holding, $update, $data['reason']);
            Workflow::audit('holding.version_restored', $holding->id);
        });

        return back()->with('success', 'Earlier package restored. All history retained; request status unchanged.');
    }

    private function publish(PortalEntry $holding, object $update, ?string $reason): void
    {
        $snapshot = json_decode($update->snapshot, true, flags: JSON_THROW_ON_ERROR);
        $holding->update(['data' => $snapshot + ['status' => 'In storage']]);
        DB::table('holding_publications')->insert(['holding_id' => $holding->id, 'update_id' => $update->id,
            'actor_id' => auth()->id(), 'reason' => $reason, 'published_at' => now()->utc()]);
        $client = User::findOrFail($holding->client_id);
        Workflow::notify([$client->id, ...$client->manager_ids], 'A holding update has been published.', '/holding/'.$holding->id);
    }

    public function file(Request $request, int $id)
    {
        $file = DB::table('holding_update_files')->where('id', $id)->first();
        abort_unless($file, 404);
        $update = DB::table('holding_updates')->where('id', $file->update_id)->first();
        app(Portal::class)->record($request->user(), $update->holding_id, 'holding');
        abort_if($request->user()->role === 'client' && $update->status !== 'approved', 404);
        abort_unless(Storage::disk('local')->exists($file->path), 404);
        $mime = Storage::disk('local')->mimeType($file->path);
        if ($request->boolean('view') && in_array($mime, ['image/jpeg', 'image/png'])) {
            return Storage::disk('local')->response($file->path, $file->original_name, ['Content-Type' => $mime, 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
        }

        return Storage::disk('local')->download($file->path, $file->original_name, ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function transition(PortalEntry $entry, string $status, ?string $note): void
    {
        $data = $entry->data;
        $history = $data['history'] ?? [];
        $history[] = ['status' => $status, 'staff_note' => $note, 'by' => auth()->user()->name, 'actor_id' => auth()->id(), 'at' => now()->utc()->toIso8601String()];
        $entry->update(['data' => array_merge($data, ['status' => $status, 'staff_note' => $note, 'history' => $history])]);
    }
}
