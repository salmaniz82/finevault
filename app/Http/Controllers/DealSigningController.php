<?php

namespace App\Http\Controllers;

use App\Models\PortalRecord;
use App\Services\DocusignDemo;
use App\Services\Portal;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DealSigningController extends Controller
{
    public function __construct(private Portal $portal, private DocusignDemo $signing) {}

    public function sign(Request $request, int $id)
    {
        abort_unless($request->user()->role === 'client', 403);
        $deal = $this->portal->record($request->user(), $id, 'deal');
        abort_unless(($deal->data['template_version'] ?? '') === 'demo-2026-11-03-v2', 409, 'Create a new agreement using the signing-ready template.');
        $request->validate(['sandbox_ack' => 'accepted']);
        $document = $this->portal->record($request->user(), $deal->data['document_id'], 'document');
        abort_unless(($document->data['status'] ?? '') === 'approved', 409);
        try {
            // Cross-session lock prevents double-clicks creating duplicate envelopes.
            DB::transaction(function () use ($id, $document) {
                $deal = PortalRecord::lockForUpdate()->findOrFail($id);
                if (empty($deal->data['envelope_id'])) {
                    $envelope = $this->signing->createDealEnvelope(Storage::disk('local')->get($document->data['path']),
                        $deal->data['client_name'], $deal->data['client_email'], 'deal-'.$id.'-client-'.$deal->client_id);
                    $deal->update(['data' => array_merge($deal->data, ['envelope_id' => $envelope, 'signing_status' => 'sent'])]);
                }
            });
            $deal->refresh();
            if ($this->signing->status($deal->data['envelope_id']) === 'completed') {
                return $this->refresh($request, $id);
            }
            $state = Str::random(48);
            $request->session()->put('deal_signing.'.$id, $state);

            return redirect()->away($this->signing->dealSigningUrl($deal->data['envelope_id'], url('/deals/'.$id.'/signing-return').'?state='.$state,
                $deal->data['client_name'], $deal->data['client_email'], 'deal-'.$id.'-client-'.$deal->client_id));
        } catch (\RuntimeException|ConnectionException $e) {
            return redirect('/deals/'.$id)->withErrors(['signing' => 'Sandbox signing could not start. Ask admin to check the DocuSign connection, then retry; any existing envelope will be reused.']);
        }
    }

    public function refresh(Request $request, int $id)
    {
        abort_if($request->user()->role === 'manager', 403);
        $deal = $this->portal->record($request->user(), $id, 'deal');
        if ($request->isMethod('get')) {
            $state = $request->session()->pull('deal_signing.'.$id);
            abort_unless(is_string($state) && is_string($request->query('state')) && hash_equals($state, $request->query('state')), 403);
        }
        abort_unless($envelope = $deal->data['envelope_id'] ?? null, 404);
        $path = null;
        try {
            // Browser return events never establish completion.
            $status = $this->signing->status($envelope);
            DB::transaction(function () use ($id, $status, $envelope, &$path) {
                $deal = PortalRecord::lockForUpdate()->findOrFail($id);
                $data = array_merge($deal->data, ['signing_status' => $status]);
                if ($status === 'completed' && empty($data['signed_document_id'])) {
                    $original = PortalRecord::findOrFail($data['document_id']);
                    $path = 'agreements/'.Str::uuid().'.pdf';
                    $pdf = $this->signing->completedPdf($envelope);
                    if (! str_starts_with($pdf, '%PDF-')) {
                        throw new \RuntimeException('The signed file was not a PDF.');
                    }
                    abort_unless(Storage::disk('local')->put($path, $pdf), 500);
                    $signed = PortalRecord::create(['client_id' => $deal->client_id, 'kind' => 'document', 'data' => [
                        'title' => 'Signed deal FW-'.$id.' — '.$data['name'], 'category' => 'Agreement',
                        'status' => 'pending', 'visibility' => 'client', 'document_date' => now()->toDateString(),
                        'path' => $path, 'original_name' => 'fineworth-deal-'.$id.'-signed.pdf',
                        'series' => $original->data['series'], 'replaces_id' => $original->id,
                        'uploaded_by' => 'Verified DocuSign sandbox', 'uploaded_by_id' => null,
                        'notes' => 'Completion verified server-side. Signed copy awaits admin publication; original approved version is retained.',
                    ]]);
                    $data['signed_document_id'] = $signed->id;
                }
                $deal->update(['data' => $data]);
            });

            return redirect('/deals/'.$id)->with('success', $status === 'completed' ? 'Sandbox signature verified. The signed copy is queued for admin approval.' : 'Sandbox signing status: '.$status.'.');
        } catch (\Throwable $e) {
            if ($path) {
                Storage::disk('local')->delete($path);
            }

            return redirect('/deals/'.$id)->withErrors(['signing' => 'Unable to verify and retain the signed agreement. Refresh status to retry.']);
        }
    }
}
