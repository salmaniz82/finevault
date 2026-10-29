<?php

namespace App\Http\Controllers;

use App\Models\PortalRecord;
use App\Models\User;
use App\Services\Portal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class PortalController extends Controller
{
    public function __construct(private Portal $portal) {}

    public function login(Request $request)
    {
        $credentials = $request->validate(['email' => 'required|email', 'password' => 'required|string']);
        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            return back()->withErrors(['email' => 'These credentials do not match our records.'])->onlyInput('email');
        }
        $request->session()->regenerate();

        return redirect()->intended('/dashboard');
    }

    public function acknowledge(Request $request, int $id)
    {
        abort_unless($request->user()->role === 'client', 403);
        $document = $this->portal->record($request->user(), $id, 'document');
        abort_unless($document->data['category'] === 'Onboarding', 404);
        $request->validate(['acknowledged' => 'accepted']);
        PortalRecord::firstOrCreate([
            'client_id' => $request->user()->id, 'kind' => 'onboarding_ack',
            'data->document_id' => $document->id,
        ], ['data' => ['document_id' => $document->id, 'acknowledged_at' => now()->toIso8601String()]]);
        return back()->with('success', 'Document review recorded. Your team will confirm the next step.');
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login');
    }

    public function page(Request $request, string $page = 'dashboard', ?int $id = null)
    {
        abort_unless(in_array($page, ['dashboard', 'holdings', 'holding', 'documents', 'requests', 'clients', 'client', 'managers', 'approvals', 'account']), 404);
        abort_if(in_array($page, ['holding', 'client']) && ! $id, 404);
        $user = $request->user();
        if (in_array($page, ['managers', 'approvals'])) {
            abort_unless($user->role === 'admin', 403);
        }
        if (in_array($page, ['clients', 'client'])) {
            abort_if($user->role === 'client', 403);
        }
        $clients = $this->portal->clients($user)->get();
        $records = $this->portal->records($user)->orderByDesc('updated_at')->orderByDesc('id')->get();
        $selected = $page === 'holding' ? $this->portal->record($user, $id, 'holding') : null;
        $client = $page === 'client' ? $this->portal->client($user, $id) : null;
        $managers = $user->role === 'admin' ? User::where('role', 'manager')->get() : collect();
        $submissions = $user->role === 'client' ? PortalRecord::where('client_id', $user->id)->where('kind', 'document')->where('data->uploaded_by_id', $user->id)->where('data->category', 'Onboarding')->latest()->get() : collect();

        return view('portal', compact('page', 'user', 'clients', 'records', 'selected', 'client', 'managers', 'submissions'));
    }

    public function user(Request $request)
    {
        abort_unless($request->user()->role === 'admin', 403);
        $data = $request->validate(['name' => 'required|string|max:100', 'email' => 'required|email|max:200|unique:users', 'password' => 'required|string|min:10|max:100', 'role' => ['required', Rule::in(['client', 'manager'])], 'manager_ids' => 'nullable|array', 'manager_ids.*' => ['integer', Rule::exists('users', 'id')->where('role', 'manager')]]);
        $data['manager_ids'] = $data['role'] === 'manager' ? [] : array_map('intval', $data['manager_ids'] ?? []);
        User::create($data);

        return back()->with('success', 'Account created. Share the credentials with the account holder securely.');
    }

    public function assign(Request $request, int $id)
    {
        abort_unless($request->user()->role === 'admin', 403);
        $client = $this->portal->client($request->user(), $id);
        $data = $request->validate(['manager_ids' => 'nullable|array', 'manager_ids.*' => ['integer', Rule::exists('users', 'id')->where('role', 'manager')]]);
        $client->update(['manager_ids' => array_map('intval', $data['manager_ids'] ?? [])]);

        return back()->with('success', 'Manager assignment updated.');
    }

    public function holding(Request $request)
    {
        abort_if($request->user()->role === 'client', 403);
        $data = $request->validate(['client_id' => 'required|integer', 'type' => 'required|in:whisky,wine', 'name' => 'required|string|max:160', 'reference' => 'required|string|max:80', 'producer' => 'required|string|max:100', 'quantity' => ['required', 'integer', 'min:1', Rule::when($request->input('type') === 'whisky', 'max:1', 'max:100000')], 'storage' => 'required|string|max:150', 'acquired' => 'required|date|before_or_equal:today', 'fill_date' => 'exclude_unless:type,whisky|nullable|date|before_or_equal:today', 'cask_type' => 'exclude_unless:type,whisky|nullable|string|max:100', 'abv' => 'exclude_unless:type,whisky|nullable|numeric|min:0|max:100', 'litres' => 'exclude_unless:type,whisky|nullable|numeric|min:0|max:10000', 'vintage' => 'exclude_unless:type,wine|nullable|integer|min:1800|max:2100', 'format' => 'exclude_unless:type,wine|nullable|string|max:80', 'bottle_ml' => 'exclude_unless:type,wine|required|numeric|min:1|max:30000', 'bottles_per_case' => 'exclude_unless:type,wine|nullable|integer|min:1|max:1000', 'notes' => 'nullable|string|max:2000']);
        $this->portal->client($request->user(), $data['client_id']);
        if ($data['type'] === 'whisky') {
            $data['quantity'] = 1;
        }
        PortalRecord::create(['client_id' => $data['client_id'], 'kind' => 'holding', 'data' => array_merge($data, ['status' => 'In storage'])]);

        return back()->with('success', 'Holding added to the client vault.');
    }

    public function document(Request $request)
    {
        $data = $request->validate(['client_id' => 'required|integer', 'holding_id' => 'nullable|integer', 'title' => 'required|string|max:150', 'category' => 'required|in:Ownership,Storage,Regauge,Evaluation,Onboarding,KYC', 'visibility' => 'required|in:client,staff', 'document_date' => 'required|date|before_or_equal:today', 'notes' => 'nullable|string|max:2000', 'replaces_id' => 'nullable|integer', 'file' => 'required|file|mimes:pdf,jpg,jpeg,png|max:10240']);
        $this->portal->client($request->user(), $data['client_id']);
        if (! empty($data['holding_id'])) {
            abort_unless($this->portal->record($request->user(), $data['holding_id'], 'holding')->client_id === (int) $data['client_id'], 422);
        }
        $previous = ! empty($data['replaces_id']) ? $this->portal->record($request->user(), $data['replaces_id'], 'document') : null;
        if ($previous) {
            abort_unless($previous->client_id === (int) $data['client_id'] && $previous->data['status'] === 'approved' && ($previous->data['holding_id'] ?? null) == ($data['holding_id'] ?? null) && $previous->data['category'] === $data['category'] && $previous->data['visibility'] === $data['visibility'], 422);
        }
        if ($request->user()->role === 'client') {
            $data['category'] = 'Onboarding';
            $data['visibility'] = 'staff';
            $data['replaces_id'] = null;
            abort_if($previous !== null, 403);
        }
        if ($data['category'] === 'KYC') {
            $data['visibility'] = 'staff';
        }
        $file = $request->file('file');
        unset($data['file']);
        $path = $file->store('documents', 'local');
        try {
            PortalRecord::create(['client_id' => $data['client_id'], 'kind' => 'document', 'data' => array_merge($data, ['path' => $path, 'original_name' => $file->getClientOriginalName(), 'status' => 'pending', 'uploaded_by' => $request->user()->name, 'uploaded_by_id' => $request->user()->id, 'series' => $previous?->data['series'] ?? (string) Str::uuid()])]);
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($path);
            throw $e;
        }

        return back()->with('success', 'Document submitted for admin review. Approved versions remain in history.');
    }

    public function download(Request $request, int $id)
    {
        $record = $this->portal->record($request->user(), $id, 'document');
        abort_unless(Storage::disk('local')->exists($record->data['path']), 404);

        return Storage::disk('local')->download($record->data['path'], $record->data['original_name'], ['X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }

    public function review(Request $request, int $id)
    {
        $data = $request->validate(['decision' => 'required|in:approved,rejected', 'reason' => 'nullable|required_if:decision,rejected|string|max:1000']);
        $this->portal->review($request->user(), $id, $data['decision'], $data['reason'] ?? null);

        return back()->with('success', $data['decision'] === 'approved' ? 'Approved and added to history.' : 'Submission returned with your review note.');
    }

    public function service(Request $request)
    {
        abort_unless($request->user()->role === 'client', 403);
        $data = $request->validate(['holding_id' => 'required|integer', 'type' => 'required|in:Evaluation,Regauge,Inspection,Photographs', 'notes' => 'required|string|max:2000']);
        $holding = $this->portal->record($request->user(), $data['holding_id'], 'holding');
        abort_if($data['type'] === 'Regauge' && $holding->data['type'] !== 'whisky', 422, 'Regauging applies to whisky casks only.');
        PortalRecord::create(['client_id' => $request->user()->id, 'kind' => 'request', 'data' => array_merge($data, ['status' => 'submitted', 'holding_name' => $holding->data['name']])]);

        return back()->with('success', 'Request sent to your portfolio team.');
    }

    public function progress(Request $request, int $id)
    {
        abort_if($request->user()->role === 'client', 403);
        $record = $this->portal->record($request->user(), $id, 'request');
        $data = $request->validate(['status' => 'required|in:submitted,in progress,completed', 'staff_note' => 'nullable|string|max:2000']);
        $history = $record->data['history'] ?? [];
        $history[] = $data + ['by' => $request->user()->name, 'at' => now()->toIso8601String()];
        $record->update(['data' => array_merge($record->data, $data, ['history' => $history])]);

        return back()->with('success', 'Request updated.');
    }

    public function profile(Request $request, int $id)
    {
        abort_if($request->user()->role === 'client', 403);
        $client = $this->portal->client($request->user(), $id);
        $data = $request->validate(['name' => 'required|string|max:100', 'notes' => 'required|string|max:2000']);
        PortalRecord::create(['client_id' => $client->id, 'kind' => 'profile', 'data' => $data + ['status' => 'pending', 'uploaded_by' => $request->user()->name]]);

        return back()->with('success', 'Profile change submitted for approval.');
    }
}
