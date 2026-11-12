<?php

namespace App\Http\Controllers;

use App\Models\PortalEntry;
use App\Services\DealAgreement;
use App\Services\DomainRecords;
use App\Services\Portal;
use App\Services\Workflow;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
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
            'deals' => $this->portal->records($request->user(), 'deal')->latest()->get()]);
    }

    public function create(Request $request)
    {
        abort_unless($request->user()->role === 'admin', 403);

        return view('deal-create', ['title' => 'Create agreement', 'user' => $request->user()]);
    }

    public function store(Request $request)
    {
        abort_unless($request->user()->role === 'admin', 403);
        $data = $this->validated($request);
        $client = $this->portal->client($request->user(), (int) $data['client_id']);
        $data['totals'] = DealAgreement::totals($data);
        if ($data['totals']['balance'] < 0) {
            throw ValidationException::withMessages(['deposit' => 'Deposit cannot exceed the total agreement amount.']);
        }
        $data += ['status' => 'draft', 'client_name' => $client->name, 'client_email' => $client->email,
            'created_by' => $request->user()->name, 'created_by_id' => $request->user()->id, 'created_at' => now()->toIso8601String(), 'template_version' => DealAgreement::TEMPLATE_VERSION];
        $deal = PortalEntry::create(['client_id' => $client->id, 'kind' => 'deal', 'data' => $data]);

        return redirect('/deals/'.$deal->id)->with('success', 'Deal draft saved. Generate its agreement to send it for admin review.');
    }

    private function validated(Request $request): array
    {
        $rules = [
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
        ];
        $rules['terms'] = 'nullable|string|max:10000';
        if (! $request->has('items')) {
            $data = $request->validate($rules);
            $data['items'] = [Arr::only($data, [...DomainRecords::ITEM, 'unit_price'])];

            return $data;
        }
        $itemKeys = [...DomainRecords::ITEM, 'unit_price'];
        $common = Arr::except($rules, $itemKeys);
        $data = $request->validate($common + ['items' => 'required|array|min:1|max:50', 'items.*' => 'required|array']);
        $items = [];
        foreach ($data['items'] as $position => $item) {
            $itemRules = Arr::only($rules, $itemKeys);
            $itemRules['quantity'] = 'required|integer|min:1|max:'.(($item['type'] ?? '') === 'whisky' ? '1' : '100000');
            $validator = validator($item, $itemRules);
            if ($validator->fails()) {
                throw ValidationException::withMessages(collect($validator->errors()->messages())->mapWithKeys(fn ($messages, $key) => ['items.'.$position.'.'.$key => $messages])->all());
            }
            $items[] = $validator->validated();
        }
        $data['items'] = $items;

        return $data + $items[0];
    }

    public function edit(Request $request, int $id)
    {
        abort_unless($request->user()->role === 'admin', 403);
        $data = $this->validated($request);
        DB::transaction(function () use ($request, $id, $data) {
            $entry = PortalEntry::where('kind', 'deal')->lockForUpdate()->findOrFail($id);
            abort_unless($entry->client_id === (int) $data['client_id'] && in_array($entry->status, ['draft', 'pending']) && empty($entry->data['envelope_id']), 409, 'Only unpublished drafts can be edited.');
            $totals = DealAgreement::totals($data);
            if ($totals['balance'] < 0) {
                throw ValidationException::withMessages(['deposit' => 'Deposit cannot exceed total.']);
            }
            $old = $entry->data;
            if (! empty($old['document_id'])) {
                $document = PortalEntry::findOrFail($old['document_id']);
                if ($document->status === 'pending') {
                    $this->portal->review($request->user(), $document->id, 'rejected', 'Retained draft replaced by an edited agreement.');
                }
            }
            $snapshot = array_merge($old, $data, ['totals' => $totals]);
            $model = $entry->deal;
            $model->update(['client_address' => $data['client_address'], 'balance_due' => $data['balance_due'], 'stock_minor' => $totals['stock'], 'total_minor' => $totals['total'], 'balance_minor' => $totals['balance'], 'agreement_snapshot' => $snapshot, 'document_id' => null, 'review_note' => null]);
            $model->items()->delete();
            foreach ($data['items'] as $position => $item) {
                $model->items()->create(['position' => $position + 1, 'unit_price_minor' => DealAgreement::pennies($item['unit_price'])] + Arr::only($item, DomainRecords::ITEM));
            }
            foreach (['markup', 'management', 'brokerage'] as $fee) {
                $model->fees()->where('type', $fee)->update(['rate' => $data[$fee.'_rate'], 'amount_minor' => $totals[$fee]]);
            }
            $model->deposits()->update(['amount_minor' => $totals['deposit'], 'reference' => $data['deposit_reference'], 'recorded_date' => $data['deposit_date']]);
            DB::table('portal_entries')->where('id', $id)->update(['status' => 'draft', 'updated_at' => now()]);
            Workflow::audit('agreement.draft_edited', $id);
        });

        return back()->with('success', 'Draft updated. Generate a new PDF to review before publishing.');
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
                $deal = PortalEntry::lockForUpdate()->findOrFail($id);
                abort_unless($deal->data['status'] === 'draft', 409);
                $data = $deal->data;
                $data['template_version'] = DealAgreement::TEMPLATE_VERSION;
                $path = 'agreements/'.Str::uuid().'.pdf';
                $generated = $this->agreements->generate($data, $id);
                abort_unless(Storage::disk('local')->put($path, $generated['pdf']), 500);
                $document = PortalEntry::create(['client_id' => $deal->client_id, 'kind' => 'document', 'data' => [
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
            $deal = PortalEntry::lockForUpdate()->findOrFail($id);
            abort_unless(in_array($deal->data['status'], ['published', 'reviewed']), 409);
            $deal->update(['data' => array_merge($deal->data, ['status' => 'reviewed', 'acknowledged_at' => $deal->data['acknowledged_at'] ?? now()->toIso8601String()])]);
        });

        return back()->with('success', 'Agreement review recorded. This acknowledgement is not an electronic signature.');
    }
}
