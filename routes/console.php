<?php

\Illuminate\Support\Facades\Artisan::command('docusign:check', function () {
    try {
        app(\App\Services\DocusignDemo::class)->checkConnection();
        $this->info('DocuSign sandbox authentication and account access verified.');
    } catch (\Illuminate\Http\Client\ConnectionException $e) {
        $this->error(str_contains($e->getMessage(), 'cURL error 60:')
            ? 'PHP could not verify the DocuSign HTTPS certificate chain. Configure a trusted CA store; keep TLS verification enabled.'
            : 'Cannot reach DocuSign over HTTPS. Check outbound access to account-d.docusign.com:443 and demo.docusign.net:443, then retry.');
        return 1;
    } catch (\Throwable $e) {
        $this->error($e instanceof \RuntimeException ? $e->getMessage() : 'DocuSign connection failed. Check network access and local configuration.');
        return 1;
    }
})->purpose('Verify sandbox authentication without creating an envelope or sending email');

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('signing:build-samples', function () {
    foreach (['standard', 'extended'] as $variant) {
        $generated = app(\App\Services\SigningDocument::class)->generate(view('agreements.direct-demo', ['extended' => $variant === 'extended'])->render());
        $path = resource_path('demo/direct-'.$variant.'.pdf');
        file_put_contents($path, $generated['pdf']);
        file_put_contents($path.'.json', json_encode($generated['hotspots'], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        $this->info($variant.': '.$generated['hotspots']['page_count'].' pages; signing page '.$generated['hotspots']['fields']['signature']['page']);
    }
})->purpose('Rebuild fictional direct-signing PDFs and their measured hotspot maps');

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
