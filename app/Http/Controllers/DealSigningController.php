<?php

namespace App\Http\Controllers;

use App\Models\PortalEntry;
use App\Services\DealAgreement;
use App\Services\DocusignDemo;
use App\Services\Portal;
use App\Services\Workflow;
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
        abort_if(($deal->data['signing_status'] ?? '') === 'completed' || ! empty($deal->data['signed_document_id']), 409, 'This agreement is already signed and locked.');
        abort_unless(in_array($deal->data['template_version'] ?? '', ['demo-2026-11-03-v2', 'demo-2026-11-04-v3', DealAgreement::TEMPLATE_VERSION]), 409, 'Create a new agreement using the signing-ready template.');
        $request->validate(['sandbox_ack' => 'accepted']);
        $document = $this->portal->record($request->user(), $deal->data['document_id'], 'document');
        abort_unless(($document->data['status'] ?? '') === 'approved', 409);
        try {
            // Cross-session lock prevents double-clicks creating duplicate envelopes.
            DB::transaction(function () use ($id, $document) {
                $deal = PortalEntry::lockForUpdate()->findOrFail($id);
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
        if (! empty($deal->data['signed_document_id'])) {
            return redirect('/deals/'.$id)->with('success', 'Agreement is already signed and locked.');
        }
        $path = null;
        try {
            // Browser return events never establish completion.
            $status = $this->signing->status($envelope);
            DB::transaction(function () use ($id, $status, $envelope, &$path) {
                $deal = PortalEntry::lockForUpdate()->findOrFail($id);
                $data = array_merge($deal->data, ['signing_status' => $status]);
                if ($status === 'completed' && empty($data['signed_document_id'])) {
                    $original = PortalEntry::findOrFail($data['document_id']);
                    $path = 'agreements/'.Str::uuid().'.pdf';
                    $pdf = $this->signing->completedPdf($envelope);
                    if (! str_starts_with($pdf, '%PDF-')) {
                        throw new \RuntimeException('The signed file was not a PDF.');
                    }
                    abort_unless(Storage::disk('local')->put($path, $pdf), 500);
                    $signed = PortalEntry::create(['client_id' => $deal->client_id, 'kind' => 'document', 'data' => [
                        'title' => 'Signed deal FW-'.$id.' — '.$data['name'], 'category' => 'Agreement',
                        'status' => 'approved', 'visibility' => 'client', 'document_date' => now()->toDateString(),
                        'path' => $path, 'original_name' => 'fineworth-deal-'.$id.'-signed.pdf',
                        'series' => $original->data['series'], 'replaces_id' => $original->id,
                        'uploaded_by' => 'Verified DocuSign sandbox', 'uploaded_by_id' => null,
                        'notes' => 'Signature verified server-side. Signed copy is locked; original approved version is retained.',
                    ]]);
                    $data['signed_document_id'] = $signed->id;
                    Workflow::audit('agreement.signed', $id);
                    Workflow::notify(Workflow::admins(), 'A client signed their agreement. Review linked holdings for release.', '/deals/'.$id);
                }
                $deal->update(['data' => $data]);
            });

            return redirect('/deals/'.$id)->with('success', $status === 'completed' ? 'Signature verified. Agreement is signed and locked.' : 'Sandbox signing status: '.$status.'.');
        } catch (\Throwable $e) {
            if ($path) {
                Storage::disk('local')->delete($path);
            }

            return redirect('/deals/'.$id)->withErrors(['signing' => 'Unable to verify and retain the signed agreement. Refresh status to retry.']);
        }
    }
}
