<?php

namespace Database\Seeders;

use App\Models\PortalRecord;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

class PrototypeSeeder extends Seeder
{
    public function run(): void
    {
        // Additive and repeatable: never delete or reset records during a demo.
        $admin = User::firstOrCreate(['email' => 'admin@fineworth.test'], ['name' => 'Alex Morgan', 'password' => 'FineworthDemo!27', 'role' => 'admin', 'manager_ids' => []]);
        $manager = User::firstOrCreate(['email' => 'manager@fineworth.test'], ['name' => 'Oliver Grant', 'password' => 'FineworthDemo!27', 'role' => 'manager', 'manager_ids' => []]);
        $otherManager = User::firstOrCreate(['email' => 'amelia@fineworth.test'], ['name' => 'Amelia Clarke', 'password' => 'FineworthDemo!27', 'role' => 'manager', 'manager_ids' => []]);
        $sophia = User::firstOrCreate(['email' => 'client@fineworth.test'], ['name' => 'Sophia Bennett', 'password' => 'FineworthDemo!27', 'role' => 'client', 'manager_ids' => [$manager->id]]);
        $james = User::firstOrCreate(['email' => 'james@fineworth.test'], ['name' => 'James Whitmore', 'password' => 'FineworthDemo!27', 'role' => 'client', 'manager_ids' => [$otherManager->id]]);
        if (PortalRecord::where('kind', 'holding')->exists()) {
            $this->onboardingGuide($sophia, $admin);
            return;
        }
        $samples = [
            [$sophia->id, ['type' => 'whisky', 'name' => 'Macallan 2019', 'reference' => 'FW-C4188', 'producer' => 'The Macallan', 'quantity' => 1, 'storage' => 'Speyside Bonded Warehouse', 'acquired' => '2021-06-14', 'fill_date' => '2019-03-18', 'cask_type' => 'Ex-bourbon barrel', 'abv' => 63.4, 'litres' => 197.3, 'notes' => 'Fictional demonstration holding. Original ownership and storage evidence kept alongside the cask.']],
            [$sophia->id, ['type' => 'whisky', 'name' => 'Bunnahabhain 2016', 'reference' => 'FW-C5321', 'producer' => 'Bunnahabhain', 'quantity' => 1, 'storage' => 'Islay Bonded Storage', 'acquired' => '2022-09-21', 'fill_date' => '2016-05-10', 'cask_type' => 'Sherry hogshead', 'abv' => 59.8, 'litres' => 243.5]],
            [$sophia->id, ['type' => 'wine', 'name' => 'Château Lafite Rothschild', 'reference' => 'FW-W1024', 'producer' => 'Domaines Barons de Rothschild', 'quantity' => 24, 'storage' => 'London City Bond', 'acquired' => '2023-02-16', 'vintage' => 2018, 'format' => '75 cl · 6-bottle cases']],
            [$sophia->id, ['type' => 'wine', 'name' => 'Dom Pérignon Vintage', 'reference' => 'FW-W1025', 'producer' => 'Moët & Chandon', 'quantity' => 12, 'storage' => 'London City Bond', 'acquired' => '2023-10-05', 'vintage' => 2012, 'format' => '75 cl · 6-bottle cases']],
            [$sophia->id, ['type' => 'wine', 'name' => 'Château Margaux', 'reference' => 'FW-W1026', 'producer' => 'Château Margaux', 'quantity' => 18, 'storage' => 'Octavian Vaults', 'acquired' => '2024-04-12', 'vintage' => 2015, 'format' => '75 cl · 6-bottle cases']],
            [$sophia->id, ['type' => 'wine', 'name' => 'Opus One', 'reference' => 'FW-W1027', 'producer' => 'Opus One Winery', 'quantity' => 12, 'storage' => 'Octavian Vaults', 'acquired' => '2024-08-19', 'vintage' => 2019, 'format' => '75 cl · 6-bottle cases']],
            [$james->id, ['type' => 'whisky', 'name' => 'Highland Park 2018', 'reference' => 'FW-C7011', 'producer' => 'Highland Park', 'quantity' => 1, 'storage' => 'Orkney Bonded Warehouse', 'acquired' => '2022-05-09', 'fill_date' => '2018-02-12', 'cask_type' => 'Refill sherry butt', 'abv' => 61.2, 'litres' => 420.5]],
        ];
        $holdings = [];
        foreach ($samples as [$owner,$data]) {
            $holdings[] = PortalRecord::create(['client_id' => $owner, 'kind' => 'holding', 'data' => $data + ['status' => 'In storage']]);
        }
        $docs = [
            ['Ownership certificate', 'Ownership', '2021-06-14', 'approved', 'client', 'ownership-1', null],
            ['Storage confirmation — 2025', 'Storage', '2025-08-13', 'approved', 'client', 'storage-1', null],
            ['Storage confirmation — 2026', 'Storage', '2026-08-13', 'approved', 'client', 'storage-1', 2],
            ['Annual regauge report', 'Regauge', '2026-10-20', 'pending', 'client', 'regauge-1', null],
            ['Identity verification record', 'KYC', '2026-09-01', 'approved', 'staff', 'kyc-1', null],
        ];
        foreach ($docs as $index => [$title,$category,$date,$status,$visibility,$series,$replaces]) {
            $path = 'documents/sample-'.($index + 1).'.pdf';
            Storage::disk('local')->put($path, $this->samplePdf($title));
            PortalRecord::create(['client_id' => $sophia->id, 'kind' => 'document', 'data' => ['title' => $title, 'category' => $category, 'document_date' => $date, 'status' => $status, 'visibility' => $visibility, 'series' => $series, 'holding_id' => $category === 'KYC' ? null : $holdings[0]->id, 'path' => $path, 'original_name' => 'sample-'.($index + 1).'.pdf', 'uploaded_by' => $manager->name, 'uploaded_by_id' => $manager->id, 'reviewed_by' => $status === 'approved' ? $admin->name : null, 'reviewed_at' => $status === 'approved' ? $date.'T12:00:00+05:00' : null, 'replaces_id' => $replaces ? PortalRecord::where('kind', 'document')->where('data->title', 'Storage confirmation — 2025')->value('id') : null, 'notes' => 'Fictional sample document for demonstrating private review and record history.']]);
        }
        PortalRecord::create(['client_id' => $sophia->id, 'kind' => 'request', 'data' => ['holding_id' => $holdings[1]->id, 'holding_name' => 'Bunnahabhain 2016', 'type' => 'Photographs', 'notes' => 'Please arrange recent photographs of the cask and its warehouse markings.', 'status' => 'in progress', 'staff_note' => 'We have contacted the warehouse and are awaiting their response.', 'history' => [['status' => 'in progress', 'staff_note' => 'Warehouse contacted.', 'by' => $manager->name, 'at' => '2026-10-26T10:00:00+05:00']]]]);
        PortalRecord::create(['client_id' => $sophia->id, 'kind' => 'profile', 'data' => ['name' => 'Sophia R. Bennett', 'notes' => 'Demonstration: client requested a correction to the display name.', 'status' => 'pending', 'uploaded_by' => $manager->name]]);
        $this->onboardingGuide($sophia, $admin);
    }

    private function onboardingGuide(User $sophia, User $admin): void
    {
        // Append the onboarding example even to an already populated prototype.
        if (! PortalRecord::where('client_id', $sophia->id)->where('kind', 'document')->where('data->series', 'demo-onboarding-guide')->exists()) {
            Storage::disk('local')->put('documents/demo-onboarding-guide.pdf', file_get_contents(resource_path('demo/fineworth-onboarding-guide.pdf')));
            PortalRecord::create(['client_id' => $sophia->id, 'kind' => 'document', 'data' => [
                'title' => 'Welcome to your vault - onboarding guide', 'category' => 'Onboarding',
                'document_date' => '2026-10-29', 'status' => 'approved', 'visibility' => 'client',
                'series' => 'demo-onboarding-guide', 'holding_id' => null,
                'path' => 'documents/demo-onboarding-guide.pdf', 'original_name' => 'fineworth-onboarding-guide.pdf',
                'uploaded_by' => $admin->name, 'uploaded_by_id' => $admin->id,
                'reviewed_by' => $admin->name, 'reviewed_at' => now()->toIso8601String(),
                'notes' => 'Read the guide and record your review. Your team will confirm the next step. Fictional demonstration.',
            ]]);
        }
    }

    private function samplePdf(string $title): string
    {
        $safe = preg_replace('/[^a-zA-Z0-9 -]/', '', $title);
        $stream = "BT /F1 22 Tf 55 750 Td (FINEWORTH) Tj 0 -45 Td /F1 16 Tf ($safe) Tj 0 -40 Td /F1 11 Tf (FICTIONAL SAMPLE - FOR PROTOTYPE DEMONSTRATION ONLY) Tj 0 -30 Td (No legal ownership, storage or compliance evidence is represented.) Tj ET";
        $objects = ['<< /Type /Catalog /Pages 2 0 R >>', '<< /Type /Pages /Kids [3 0 R] /Count 1 >>', '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>', '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>', '<< /Length '.strlen($stream)." >>\nstream\n$stream\nendstream"];
        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $i => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($i + 1)." 0 obj\n$object\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 6\n0000000000 65535 f \n";
        foreach (array_slice($offsets, 1) as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf."trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n$xref\n%%EOF";
    }
}
