<?php

namespace App\Services;

use App\Models\Deal;
use App\Models\DealFee;
use App\Models\DealItem;
use App\Models\DirectSignatureRecord;
use App\Models\Document;
use App\Models\DocumentAcknowledgement;
use App\Models\DocumentReview;
use App\Models\DocumentVersion;
use App\Models\ExternalDeposit;
use App\Models\Holding;
use App\Models\PortalEntry;
use App\Models\ProfileChange;
use App\Models\RequestEvent;
use App\Models\ServiceRequest;
use App\Models\SigningRequest;
use App\Models\WhiskyHolding;
use App\Models\WineHolding;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

/** Maps the existing form/view DTO to typed domain rows. No generic payload is persisted. */
class DomainRecords
{
    public const HOLDING = ['type', 'name', 'reference', 'producer', 'quantity', 'storage', 'acquired', 'notes'];

    public const WHISKY = ['fill_date', 'cask_type', 'abv', 'litres'];

    public const WINE = ['vintage', 'format', 'bottle_ml', 'bottles_per_case'];

    public const VERSION = ['title', 'document_date', 'path', 'original_name', 'holding_id', 'deal_id', 'replaces_id', 'uploaded_by', 'uploaded_by_id', 'notes', 'signing_hotspots', 'sha256', 'onboarding_purpose'];

    public const REVIEW = ['reviewed_by', 'reviewed_by_id', 'reviewed_at', 'review_note'];

    public const ITEM = ['type', 'name', 'reference', 'producer', 'storage', 'quantity', 'cask_type', 'cask_size', 'ola', 'rla', 'abv', 'measurement_date', 'vintage', 'bottle_ml'];

    public const DEAL = ['client_name', 'client_email', 'client_address', 'created_by', 'created_by_id', 'template_version', 'balance_due', 'review_note', 'acknowledged_at', 'document_id', 'signed_document_id'];

    public const REQUEST = ['holding_id', 'type', 'holding_name', 'notes', 'staff_note'];

    public const PROFILE = ['name', 'notes', 'uploaded_by', 'uploaded_by_id', 'reviewed_by', 'reviewed_by_id', 'reviewed_at', 'review_note'];

    public const SIGNATURE = ['token', 'name', 'method', 'signed_at', 'path', 'source_sha256', 'signed_sha256', 'consent', 'signing_hotspots', 'placement', 'variant'];

    public function read(PortalEntry $entry): array
    {
        $data = match ($entry->kind) {
            'holding' => $this->holdingData($entry->holding),
            'document' => $this->documentData($entry->documentVersion),
            'deal' => $this->dealData($entry->deal),
            'request' => $this->requestData($entry->serviceRequest),
            'profile' => $this->fields($entry->profileChange, self::PROFILE),
            'onboarding_ack' => $this->fields($entry->acknowledgement, ['document_id', 'acknowledged_at']),
            'direct_signature_demo' => $this->fields($entry->directSignature, self::SIGNATURE),
            default => throw new InvalidArgumentException('Unknown entry kind.'),
        };
        if ($entry->status !== null) {
            $data['status'] = $entry->status;
        }

        return $data;
    }

    private function fields(?Model $row, array $fields): array
    {
        if (! $row) {
            throw new \RuntimeException('Missing relational detail row. Run portal:verify before serving requests.');
        }

        return array_filter(Arr::only($row->attributesToArray(), $fields), fn ($v) => $v !== null);
    }

    private function holdingData(Holding $holding): array
    {
        return $this->fields($holding, self::HOLDING) + ($holding->type === 'whisky'
            ? $this->fields($holding->whisky, self::WHISKY) : $this->fields($holding->wine, self::WINE));
    }

    private function documentData(DocumentVersion $version): array
    {
        $data = $this->fields($version, self::VERSION) + $this->fields($version->document, ['series', 'category', 'visibility']);
        if ($review = $version->reviews->last()) {
            $data += $this->fields($review, self::REVIEW);
        }

        return $data;
    }

    private function dealData(Deal $deal): array
    {
        $item = $deal->items->firstOrFail();
        $deposit = $deal->deposits->sole();
        $data = $this->fields($deal, self::DEAL) + $this->fields($item, self::ITEM);
        $data += ['unit_price' => number_format($item->unit_price_minor / 100, 2, '.', ''),
            'deposit' => number_format($deposit->amount_minor / 100, 2, '.', ''),
            'deposit_reference' => $deposit->reference, 'deposit_date' => $deposit->recorded_date,
            'created_at' => $deal->agreement_snapshot['created_at'] ?? null];
        $totals = ['stock' => $deal->stock_minor];
        foreach (['markup', 'management', 'brokerage'] as $type) {
            $fee = $deal->fees->firstWhere('type', $type);
            $data[$type.'_rate'] = $fee->rate;
            $totals[$type] = $fee->amount_minor;
        }
        $data['totals'] = $totals + ['total' => $deal->total_minor, 'deposit' => $deposit->amount_minor, 'balance' => $deal->balance_minor];
        $data['items'] = $deal->items->map(fn ($row) => $this->fields($row, self::ITEM) + ['unit_price' => number_format($row->unit_price_minor / 100, 2, '.', '')])->all();
        $data['terms'] = $deal->agreement_snapshot['terms'] ?? '';
        if ($signing = $deal->signingRequests->firstWhere('document_version_id', $deal->document_id)) {
            $data += ['envelope_id' => $signing->envelope_id, 'signing_status' => $signing->status];
        }

        return $data;
    }

    private function requestData(ServiceRequest $request): array
    {
        return $this->fields($request, self::REQUEST) + ['history' => $request->events->map(fn ($event) => $this->fields($event, ['status', 'staff_note', 'by', 'actor_id', 'at']))->all()];
    }

    public function validate(string $kind, array $data): void
    {
        $allowed = match ($kind) {
            'holding' => [...self::HOLDING, ...self::WHISKY, ...self::WINE],
            'document' => [...self::VERSION, ...self::REVIEW, 'series', 'category', 'visibility'],
            'deal' => [...self::DEAL, ...self::ITEM, 'items', 'terms', 'unit_price', 'deposit', 'deposit_reference', 'deposit_date', 'markup_rate', 'management_rate', 'brokerage_rate', 'totals', 'created_at', 'envelope_id', 'signing_status'],
            'request' => [...self::REQUEST, 'history'],
            'profile' => self::PROFILE,
            'onboarding_ack' => ['document_id', 'acknowledged_at'],
            'direct_signature_demo' => self::SIGNATURE,
            default => throw new InvalidArgumentException('Unknown entry kind: '.$kind),
        };
        $unknown = array_diff(array_keys($data), [...$allowed, 'status', 'client_id']);
        if ($unknown) {
            throw new InvalidArgumentException('Unmapped '.$kind.' fields: '.implode(', ', $unknown));
        }
        $statuses = match ($kind) {
            'holding' => ['In storage'], 'document', 'profile' => ['pending', 'approved', 'rejected'],
            'deal' => ['draft', 'pending', 'published', 'reviewed', 'rejected'],
            'request' => ['submitted', 'in progress', 'completed', 'pending', 'pending review', 'resolved'],
            'direct_signature_demo' => ['completed'], default => [null],
        };
        if (! in_array($data['status'] ?? null, $statuses, true)) {
            throw new InvalidArgumentException('Invalid '.$kind.' status.');
        }
    }

    public function write(PortalEntry $entry, array $data): void
    {
        $this->validate($entry->kind, $data);
        if (isset($data['client_id']) && (int) $data['client_id'] !== (int) $entry->client_id) {
            throw new InvalidArgumentException('Conflicting client identity.');
        }
        $identity = ['id' => $entry->id, 'client_id' => $entry->client_id];
        switch ($entry->kind) {
            case 'holding':
                Holding::updateOrCreate(['id' => $entry->id], $identity + Arr::only($data, self::HOLDING));
                $whisky = $data['type'] === 'whisky';
                $foreign = $whisky ? self::WINE : self::WHISKY;
                if (array_filter(Arr::only($data, $foreign), fn ($v) => $v !== null)) {
                    throw new InvalidArgumentException('Holding details do not match asset type.');
                }
                if ($whisky && (int) $data['quantity'] !== 1) {
                    throw new InvalidArgumentException('Current whisky journey requires one cask per holding.');
                }
                ($whisky ? WhiskyHolding::class : WineHolding::class)::updateOrCreate(['holding_id' => $entry->id], Arr::only($data, $whisky ? self::WHISKY : self::WINE));
                break;
            case 'document':
                $visibility = $data['category'] === 'KYC' ? 'staff' : $data['visibility'];
                $document = Document::firstOrCreate(['client_id' => $entry->client_id, 'series' => $data['series'] ?? 'legacy-version-'.$entry->id], ['category' => $data['category'], 'visibility' => $visibility]);
                if ($document->category !== $data['category'] || $document->visibility !== $visibility) {
                    throw new InvalidArgumentException('Document series classification cannot change.');
                }
                if (! empty($data['replaces_id'])) {
                    $previous = DocumentVersion::findOrFail($data['replaces_id']);
                    if ($previous->document_id !== $document->id || ($previous->holding_id ?? null) != ($data['holding_id'] ?? null)) {
                        throw new InvalidArgumentException('Replacement must belong to the same document series and holding.');
                    }
                }
                $values = $identity + ['document_id' => $document->id] + Arr::only($data, self::VERSION);
                $existing = DocumentVersion::find($entry->id);
                if ($existing) {
                    foreach (self::VERSION as $field) {
                        if (($existing->$field ?? null) != ($values[$field] ?? null)) {
                            throw new InvalidArgumentException('Document content is immutable; upload a new version.');
                        }
                    }
                } else {
                    if (! empty($values['path']) && Storage::disk('local')->exists($values['path'])) {
                        $values['sha256'] = hash_file('sha256', Storage::disk('local')->path($values['path']));
                    }
                    DocumentVersion::create($values);
                }
                if (! empty($data['reviewed_at']) && ! DocumentReview::where('document_version_id', $entry->id)->exists()) {
                    DocumentReview::create(['document_version_id' => $entry->id, 'decision' => $data['status']] + $this->dates(Arr::only($data, self::REVIEW)));
                }
                break;
            case 'deal':
                $this->writeDeal($entry, $data);
                break;
            case 'request':
                $holding = Holding::findOrFail($data['holding_id']);
                if ($data['type'] === 'Regauge' && $holding->type !== 'whisky') {
                    throw new InvalidArgumentException('Only whisky can be regauged.');
                }
                ServiceRequest::updateOrCreate(['id' => $entry->id], $identity + Arr::only($data, self::REQUEST));
                foreach ($data['history'] ?? [] as $position => $event) {
                    $values = $this->dates(Arr::only($event, ['status', 'staff_note', 'by', 'actor_id', 'at']));
                    $existing = RequestEvent::firstOrCreate(['request_id' => $entry->id, 'position' => $position + 1], $values);
                    foreach ($values as $key => $value) {
                        if ($value != $existing->$key) {
                            throw new InvalidArgumentException('Request history is append-only.');
                        }
                    }
                }
                break;
            case 'profile':
                ProfileChange::updateOrCreate(['id' => $entry->id], $identity + $this->dates(Arr::only($data, self::PROFILE)));
                break;
            case 'onboarding_ack':
                DocumentAcknowledgement::firstOrCreate(['id' => $entry->id], $identity + $this->dates(Arr::only($data, ['document_id', 'acknowledged_at'])));
                break;
            case 'direct_signature_demo':
                DirectSignatureRecord::firstOrCreate(['id' => $entry->id], $identity + $this->dates(Arr::only($data, self::SIGNATURE)));
                break;
        }
    }

    private function writeDeal(PortalEntry $entry, array $data): void
    {
        $existing = Deal::find($entry->id);
        if (! $existing) {
            $totals = $data['totals'];
            if (DealAgreement::totals($data) != $totals) {
                throw new InvalidArgumentException('Deal totals do not reconcile.');
            }
            Deal::create(['id' => $entry->id, 'client_id' => $entry->client_id,
                'stock_minor' => $totals['stock'], 'total_minor' => $totals['total'], 'balance_minor' => $totals['balance'],
                'agreement_snapshot' => Arr::except($data, ['status', 'document_id', 'signed_document_id', 'envelope_id', 'signing_status', 'review_note', 'acknowledged_at']),
            ] + $this->dates(Arr::only($data, self::DEAL)));
            foreach ($data['items'] ?? [$data] as $position => $item) {
                DealItem::create(['deal_id' => $entry->id, 'position' => $position + 1, 'unit_price_minor' => DealAgreement::pennies($item['unit_price'])] + Arr::only($item, self::ITEM));
            }
            foreach (['markup', 'management', 'brokerage'] as $type) {
                DealFee::create(['deal_id' => $entry->id, 'type' => $type, 'rate' => $data[$type.'_rate'], 'amount_minor' => $totals[$type]]);
            }
            ExternalDeposit::create(['deal_id' => $entry->id, 'position' => 1, 'amount_minor' => $totals['deposit'], 'reference' => $data['deposit_reference'], 'recorded_date' => $data['deposit_date']]);
        } else {
            $before = $this->dealData($existing);
            foreach ([...self::ITEM, 'client_name', 'client_email', 'client_address', 'balance_due', 'unit_price', 'deposit', 'deposit_reference', 'deposit_date', 'markup_rate', 'management_rate', 'brokerage_rate', 'totals', 'items', 'terms'] as $field) {
                if (($before[$field] ?? null) != ($data[$field] ?? null)) {
                    throw new InvalidArgumentException('Deal commercial data is frozen; create a corrected draft.');
                }
            }
            $existing->update($this->dates(Arr::only($data, ['template_version', 'review_note', 'acknowledged_at', 'document_id', 'signed_document_id'])));
        }
        if (! empty($data['envelope_id'])) {
            $version = DocumentVersion::findOrFail($data['document_id']);
            if ($version->client_id !== $entry->client_id || $version->deal_id !== $entry->id) {
                throw new InvalidArgumentException('Signing source does not belong to this deal.');
            }
            $signing = SigningRequest::firstOrCreate(['deal_id' => $entry->id, 'document_version_id' => $version->id], ['envelope_id' => $data['envelope_id'], 'status' => $data['signing_status'] ?? 'sent']);
            if ($signing->envelope_id !== $data['envelope_id']) {
                throw new InvalidArgumentException('Signing envelope cannot be replaced.');
            }
            $signing->update(['status' => $data['signing_status'] ?? $signing->status]);
        }
    }

    private function dates(array $values): array
    {
        foreach (['reviewed_at', 'acknowledged_at', 'signed_at', 'at'] as $field) {
            if (! empty($values[$field])) {
                $values[$field] = Carbon::parse($values[$field], 'UTC')->utc()->format('Y-m-d H:i:s');
            }
        }

        return $values;
    }
}
