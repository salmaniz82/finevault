<?php

namespace App\Http\Controllers;

use App\Models\PortalRecord;
use App\Services\DealAgreement;
use App\Services\Portal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DealController extends Controller
{
    public function __construct(private Portal $portal, private DealAgreement $agreements) {}

    public function index(Request $request)
    {
        abort_if($request->user()->role === 'manager', 403);
        return view('deals', ['title' => 'Deals & agreements', 'user' => $request->user(),
            'clients' => $this->portal->clients($request->user())->get(),
            'deals' => $this->portal->records($request->user(), 'deal')->latest()->get()]);
    }

    public function store(Request $request)
    {
        abort_unless($request->user()->role === 'admin', 403);
        $data = $request->validate([
            'client_id' => 'required|integer', 'type' => 'required|in:whisky,wine',
            'name' => 'required|string|max:160', 'reference' => 'required|string|max:80',
            'producer' => 'required|string|max:100', 'storage' => 'required|string|max:150',
            'client_address' => 'required|string|max:500',
            'quantity' => ['required', 'integer', 'min:1', Rule::when($request->input('type') === 'whisky', 'max:1', 'max:100000')],
            'cask_type' => 'exclude_unless:type,whisky|required|string|max:100',
            'cask_size' => 'exclude_unless:type,whisky|required|numeric|min:1|max:10000',
            'ola' => 'exclude_unless:type,whisky|required|numeric|min:0|max:10000',
            'rla' => 'exclude_unless:type,whisky|required|numeric|min:0|max:10000',
            'abv' => 'exclude_unless:type,whisky|required|numeric|min:0|max:100',
            'measurement_date' => 'exclude_unless:type,whisky|required|date|before_or_equal:today',
            'vintage' => 'exclude_unless:type,wine|required|integer|min:1800|max:2100',
            'bottle_ml' => 'exclude_unless:type,wine|required|integer|min:1|max:30000',
            'unit_price' => 'required|numeric|decimal:0,2|min:0.01|max:10000000',
            'deposit' => 'required|numeric|decimal:0,2|min:0.01|max:1000000000',
            'deposit_reference' => 'required|string|max:100',
            'deposit_date' => 'required|date|before_or_equal:today',
            'balance_due' => 'required|date|after_or_equal:deposit_date',
            'markup_rate' => ['required', Rule::in(DealAgreement::RATES)],
            'management_rate' => ['required', Rule::in(DealAgreement::RATES)],
            'brokerage_rate' => ['required', Rule::in(DealAgreement::RATES)],
        ]);
        $client = $this->portal->client($request->user(), (int) $data['client_id']);
        $data['totals'] = DealAgreement::totals($data);
        if ($data['totals']['balance'] < 0) {
            throw ValidationException::withMessages(['deposit' => 'Deposit cannot exceed the total agreement amount.']);
        }
        $data += ['status' => 'draft', 'client_name' => $client->name, 'client_email' => $client->email,
            'created_by' => $request->user()->name, 'created_at' => now()->toIso8601String(), 'template_version' => DealAgreement::TEMPLATE_VERSION];
        $deal = PortalRecord::create(['client_id' => $client->id, 'kind' => 'deal', 'data' => $data]);

        return redirect('/deals/'.$deal->id)->with('success', 'Deal draft saved. Generate its agreement to send it for admin review.');
    }

    public function show(Request $request, int $id)
    {
        abort_if($request->user()->role === 'manager', 403);
        $deal = $this->portal->record($request->user(), $id, 'deal');
        $document = isset($deal->data['document_id']) ? $this->portal->record($request->user(), $deal->data['document_id'], 'document') : null;
        $signedDocument = isset($deal->data['signed_document_id']) ? $this->portal->records($request->user(), 'document')->find($deal->data['signed_document_id']) : null;

        return view('deal', ['title' => 'Deal FW-'.$id, 'deal' => $deal, 'document' => $document, 'signedDocument' => $signedDocument, 'user' => $request->user()]);
    }

    public function generate(Request $request, int $id)
    {
        abort_unless($request->user()->role === 'admin', 403);
        $this->portal->record($request->user(), $id, 'deal');
        $path = null;
        try {
            DB::transaction(function () use ($request, $id, &$path) {
                $deal = PortalRecord::lockForUpdate()->findOrFail($id);
                abort_unless($deal->data['status'] === 'draft', 409);
                $data = $deal->data;
                $data['template_version'] = DealAgreement::TEMPLATE_VERSION;
                $path = 'agreements/'.Str::uuid().'.pdf';
                $generated = $this->agreements->generate($data, $id);
                abort_unless(Storage::disk('local')->put($path, $generated['pdf']), 500);
                $document = PortalRecord::create(['client_id' => $deal->client_id, 'kind' => 'document', 'data' => [
                    'title' => 'Deal FW-'.$id.' — '.$data['name'], 'category' => 'Agreement',
                    'document_date' => now()->toDateString(), 'status' => 'pending', 'visibility' => 'client',
                    'path' => $path, 'original_name' => 'fineworth-deal-'.$id.'.pdf', 'series' => (string) Str::uuid(),
                    'signing_hotspots' => $generated['hotspots'],
                    'uploaded_by' => $request->user()->name, 'uploaded_by_id' => $request->user()->id,
                    'deal_id' => $id, 'notes' => 'Generated from a frozen deal snapshot. Verify client, stock, deposit and fee basis before publication.',
                ]]);
                $deal->update(['data' => array_merge($data, ['status' => 'pending', 'document_id' => $document->id])]);
            });
        } catch (\Throwable $e) {
            if ($path) {
                Storage::disk('local')->delete($path);
            }
            throw $e;
        }

        return back()->with('success', 'Agreement PDF generated privately and queued for admin approval.');
    }

    public function acknowledge(Request $request, int $id)
    {
        abort_unless($request->user()->role === 'client', 403);
        $request->validate(['acknowledged' => 'accepted']);
        $this->portal->record($request->user(), $id, 'deal');
        DB::transaction(function () use ($id) {
            $deal = PortalRecord::lockForUpdate()->findOrFail($id);
            abort_unless(in_array($deal->data['status'], ['published', 'reviewed']), 409);
            $deal->update(['data' => array_merge($deal->data, ['status' => 'reviewed', 'acknowledged_at' => $deal->data['acknowledged_at'] ?? now()->toIso8601String()])]);
        });

        return back()->with('success', 'Agreement review recorded. This acknowledgement is not an electronic signature.');
    }
}
