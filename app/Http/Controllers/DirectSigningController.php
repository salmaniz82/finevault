<?php

namespace App\Http\Controllers;

use App\Models\PortalEntry;
use App\Services\DirectSignature;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class DirectSigningController extends Controller
{
    private function admin(Request $request): void
    {
        abort_unless($request->user()->role === 'admin', 403);
    }

    private function records(Request $request)
    {
        return PortalEntry::where('kind', 'direct_signature_demo')->where('client_id', $request->user()->id);
    }

    public function show(Request $request, DirectSignature $service)
    {
        $this->admin($request);
        $key = 'direct_signing.'.$request->user()->id;
        if (! $request->session()->has($key)) {
            $request->session()->put($key, (string) Str::uuid());
        }
        $token = $request->session()->get($key);
        $variant = $request->session()->get('direct_signing_variant.'.$request->user()->id, 'standard');

        return view('direct-signing', [
            'title' => 'Direct signing demo', 'token' => $token,
            'variant' => $variant, 'hotspots' => $service->hotspots($variant),
            'completed' => $this->records($request)->whereField('token', $token)->first(),
            'history' => $this->records($request)->latest()->limit(10)->get(),
        ]);
    }

    public function sample(Request $request, DirectSignature $service)
    {
        $this->admin($request);
        $variant = $request->session()->get('direct_signing_variant.'.$request->user()->id, 'standard');

        return response()->file($service->sample($variant), ['Content-Type' => 'application/pdf']);
    }

    public function save(Request $request, DirectSignature $service)
    {
        $this->admin($request);
        $input = $request->validate([
            'token' => 'required|uuid', 'name' => 'required|string|min:2|max:100',
            'method' => 'required|in:type,draw,upload', 'consent' => 'accepted',
            'signature' => 'required|string|max:1000000', 'source_sha256' => 'required|string|size:64',
        ]);
        abort_unless($input['token'] === $request->session()->get('direct_signing.'.$request->user()->id), 419);
        if ($this->records($request)->whereField('token', $input['token'])->exists()) {
            return redirect('/direct-signing-demo');
        }
        $variant = $request->session()->get('direct_signing_variant.'.$request->user()->id, 'standard');
        $hotspots = $service->hotspots($variant);
        if (! hash_equals($hotspots['source_sha256'], $input['source_sha256'])) {
            throw ValidationException::withMessages(['source_sha256' => 'The agreement changed. Reload and review the current document before signing.']);
        }
        $png = $service->image($input['signature']);
        $disk = Storage::disk('local');
        $base = 'direct-signing/'.$request->user()->id.'/'.$input['token'];
        $temporary = $base.'.png';
        $path = $base.'.pdf';
        $time = now()->utc()->format('Y-m-d H:i:s');
        try {
            throw_unless($disk->put($temporary, $png), \RuntimeException::class, 'Signature could not be saved.');
            $pdf = $service->render($input, $disk->path($temporary), $time, $service->sample($variant), $hotspots);
            throw_unless($disk->put($path, $pdf), \RuntimeException::class, 'PDF could not be saved.');
            $this->records($request)->create(['client_id' => $request->user()->id, 'kind' => 'direct_signature_demo', 'data' => [
                'token' => $input['token'], 'name' => $input['name'], 'method' => $input['method'],
                'status' => 'completed', 'signed_at' => $time, 'path' => $path,
                'source_sha256' => $hotspots['source_sha256'], 'signed_sha256' => hash('sha256', $pdf),
                'consent' => 'I have reviewed the sample agreement and agree to apply this signature for this demonstration.',
                'signing_hotspots' => $hotspots, 'variant' => $variant,
            ]]);
        } catch (\Throwable $exception) {
            $disk->delete($path);
            throw $exception;
        } finally {
            $disk->delete($temporary);
        }

        return redirect('/direct-signing-demo')->with('success', 'Completed — your signed sample PDF has been saved privately.');
    }

    public function download(Request $request, int $id)
    {
        $this->admin($request);
        $record = $this->records($request)->findOrFail($id);

        return Storage::disk('local')->download($record->data['path'], 'fineworth-direct-signed-'.$record->id.'.pdf', ['Content-Type' => 'application/pdf']);
    }

    public function reset(Request $request)
    {
        $this->admin($request);
        $request->validate(['variant' => 'sometimes|in:standard,extended']);
        $request->session()->put('direct_signing_variant.'.$request->user()->id, $request->input('variant', 'standard'));
        $request->session()->put('direct_signing.'.$request->user()->id, (string) Str::uuid());

        return redirect('/direct-signing-demo');
    }
}
