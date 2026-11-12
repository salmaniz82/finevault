<?php

namespace App\Http\Controllers;

use App\Models\PortalEntry;
use App\Models\User;
use App\Services\Portal;
use App\Services\Workflow;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class OnboardingController extends Controller
{
    private function client(Request $request, ?int $id): User
    {
        return app(Portal::class)->client($request->user(), $id ?? $request->user()->id);
    }

    public function show(Request $request, ?int $id = null)
    {
        $client = $this->client($request, $id);
        $submissions = PortalEntry::where('client_id', $client->id)->where('kind', 'document')
            ->whereHas('documentVersion', fn ($q) => $q->whereNotNull('onboarding_purpose'))->latest()->get();

        return view('workflow.onboarding', ['title' => 'Client onboarding', 'client' => $client, 'profile' => $client->clientProfile, 'submissions' => $submissions]);
    }

    public function save(Request $request, int $id)
    {
        $client = $this->client($request, $id);
        $data = $request->validate(['name' => 'required|string|max:100', 'phone' => 'required|string|max:60', 'address' => 'required|string|max:1000']);
        DB::transaction(function () use ($client, $data) {
            $profile = $client->clientProfile()->lockForUpdate()->firstOrFail();
            abort_if(in_array($profile->onboarding_status, ['submitted', 'approved']), 409, 'Submitted details are locked during and after review.');
            $client->update(['name' => $data['name']]);
            $profile->update(['phone' => $data['phone'], 'address' => $data['address'], 'onboarding_status' => 'draft']);
            Workflow::audit('onboarding.details_saved', client: $client->id);
        });

        return back()->with('success', 'Personal details saved.');
    }

    public function upload(Request $request, int $id)
    {
        $client = $this->client($request, $id);
        $data = $request->validate(['purpose' => ['required', Rule::in(array_keys(config('onboarding.document_purposes')))],
            'label' => ['required', Rule::in(config('onboarding.document_labels'))], 'file' => 'required|file|mimes:pdf,jpg,jpeg,png|max:10240']);
        $path = null;
        try {
            DB::transaction(function () use ($request, $client, $data, &$path) {
                $profile = $client->clientProfile()->lockForUpdate()->firstOrFail();
                abort_if(in_array($profile->onboarding_status, ['submitted', 'approved']), 409);
                $path = $request->file('file')->store('onboarding', 'local');
                PortalEntry::create(['client_id' => $client->id, 'kind' => 'document', 'data' => [
                    'title' => $data['label'], 'category' => 'KYC', 'visibility' => 'staff', 'onboarding_purpose' => $data['purpose'],
                    'status' => 'pending', 'document_date' => now()->toDateString(), 'path' => $path,
                    'original_name' => $request->file('file')->getClientOriginalName(), 'series' => (string) Str::uuid(),
                    'uploaded_by' => $request->user()->name, 'uploaded_by_id' => $request->user()->id,
                ]]);
                Workflow::audit('onboarding.document_added', client: $client->id);
            });
        } catch (\Throwable $e) {
            if ($path) {
                Storage::disk('local')->delete($path);
            }
            throw $e;
        }

        return back()->with('success', 'Private onboarding document added.');
    }

    public function submit(Request $request, int $id)
    {
        $client = $this->client($request, $id);
        DB::transaction(function () use ($client) {
            $profile = $client->clientProfile()->lockForUpdate()->firstOrFail();
            abort_unless(in_array($profile->onboarding_status, ['draft', 'returned']), 409);
            $this->complete($client, $profile);
            $profile->update(['onboarding_status' => 'submitted', 'submitted_at' => now()->utc(), 'review_note' => null]);
            Workflow::audit('onboarding.submitted', client: $client->id);
            Workflow::notify(Workflow::admins(), 'Client onboarding is ready for review.', '/clients/'.$client->id.'/onboarding');
        });

        return back()->with('success', 'Onboarding submitted for admin review.');
    }

    private function complete(User $client, $profile): void
    {
        abort_unless($client->fresh()->hasVerifiedEmail(), 422, 'Verify the client email first.');
        foreach (array_keys(config('onboarding.personal_fields')) as $field) {
            abort_unless(filled($field === 'name' ? $client->name : $profile->$field), 422, 'Complete the personal details first.');
        }
        foreach (array_keys(config('onboarding.document_purposes')) as $purpose) {
            abort_unless(DB::table('document_versions')->where('client_id', $client->id)->where('onboarding_purpose', $purpose)->exists(), 422, 'Provide identity and address proof first.');
        }
    }

    public function review(Request $request, int $id)
    {
        abort_unless($request->user()->role === 'admin', 403);
        $client = $this->client($request, $id);
        $data = $request->validate(['decision' => 'required|in:approved,returned', 'reason' => 'nullable|required_if:decision,returned|string|max:1000']);
        DB::transaction(function () use ($request, $client, $data) {
            $profile = $client->clientProfile()->lockForUpdate()->firstOrFail();
            abort_unless($profile->onboarding_status === 'submitted', 409);
            $this->complete($client, $profile);
            $profile->update(['onboarding_status' => $data['decision'], 'reviewed_by_id' => $request->user()->id,
                'approved_at' => $data['decision'] === 'approved' ? now()->utc() : null, 'review_note' => $data['reason'] ?? null]);
            Workflow::audit('onboarding.'.$data['decision'], client: $client->id);
            Workflow::notify([$client->id], 'Onboarding '.$data['decision'].'.', '/onboarding');
            Workflow::notify($client->manager_ids, 'Client onboarding '.$data['decision'].'.', '/clients/'.$client->id.'/onboarding');
        });

        return back()->with('success', 'Onboarding '.$data['decision'].'.');
    }
}
