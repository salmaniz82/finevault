<?php

namespace Tests\Support;

use App\Models\PortalEntry;
use App\Models\User;
use App\Services\DealAgreement;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

trait WorkflowFixtures
{
    protected function approveClient(User $client): void
    {
        $client->markEmailAsVerified();
        $client->clientProfile->update(['phone' => '+44 000000000', 'address' => 'Fictional test address', 'onboarding_status' => 'approved', 'approved_at' => now()]);
        $client->unsetRelations();
    }

    protected function signedAgreement(User $client): PortalEntry
    {
        $data = ['client_name' => $client->name, 'client_email' => $client->email, 'client_address' => 'Fictional test address',
            'type' => 'wine', 'name' => 'Test wine', 'reference' => 'TEST', 'producer' => 'Test producer', 'storage' => 'Test store',
            'quantity' => 12, 'vintage' => 2020, 'bottle_ml' => 750, 'unit_price' => '100.00', 'deposit' => '100.00',
            'deposit_reference' => 'TEST', 'deposit_date' => now()->toDateString(), 'balance_due' => now()->addDays(10)->toDateString(),
            'markup_rate' => 0, 'management_rate' => 0, 'brokerage_rate' => 0, 'template_version' => DealAgreement::TEMPLATE_VERSION, 'status' => 'published'];
        $data['totals'] = DealAgreement::totals($data);
        $deal = PortalEntry::create(['client_id' => $client->id, 'kind' => 'deal', 'data' => $data]);
        $series = (string) Str::uuid();
        $path = 'tests/'.$series.'.pdf';
        Storage::disk('local')->put($path, '%PDF-1.4 test');
        $source = PortalEntry::create(['client_id' => $client->id, 'kind' => 'document', 'data' => ['title' => 'Test agreement',
            'category' => 'Agreement', 'visibility' => 'client', 'status' => 'approved', 'series' => $series,
            'document_date' => now()->toDateString(), 'path' => $path, 'original_name' => 'test-agreement.pdf', 'deal_id' => $deal->id]]);
        $signed = PortalEntry::create(['client_id' => $client->id, 'kind' => 'document', 'data' => ['title' => 'Test signed agreement',
            'category' => 'Agreement', 'visibility' => 'client', 'status' => 'approved', 'series' => $series,
            'document_date' => now()->toDateString(), 'path' => $path, 'replaces_id' => $source->id]]);
        $deal->update(['data' => $deal->data + ['document_id' => $source->id, 'signed_document_id' => $signed->id, 'envelope_id' => (string) Str::uuid(), 'signing_status' => 'completed']]);

        return $deal->fresh();
    }
}
