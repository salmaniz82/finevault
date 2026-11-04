<?php

use App\Services\DocusignDemo;
use App\Services\RelationalMigration;
use App\Services\SigningDocument;
use Illuminate\Foundation\Inspiring;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

Artisan::command('docusign:check', function () {
    try {
        app(DocusignDemo::class)->checkConnection();
        $this->info('DocuSign sandbox authentication and account access verified.');
    } catch (ConnectionException $e) {
        $this->error(str_contains($e->getMessage(), 'cURL error 60:')
            ? 'PHP could not verify the DocuSign HTTPS certificate chain. Configure a trusted CA store; keep TLS verification enabled.'
            : 'Cannot reach DocuSign over HTTPS. Check outbound access to account-d.docusign.com:443 and demo.docusign.net:443, then retry.');

        return 1;
    } catch (Throwable $e) {
        $this->error($e instanceof RuntimeException ? $e->getMessage() : 'DocuSign connection failed. Check network access and local configuration.');

        return 1;
    }
})->purpose('Verify sandbox authentication without creating an envelope or sending email');

Artisan::command('portal:verify', function () {
    $counts = app(RelationalMigration::class)->verify();
    foreach ($counts as $kind => $count) {
        $this->line($kind.': '.$count);
    }
    $this->info('Relational identities, detail counts, financial totals and KYC visibility verified.');
})->purpose('Read-only verification of the relational portal');

Artisan::command('portal:prepare-mysql-tests', function () {
    if (config('database.default') !== 'mysql' || ! in_array(config('database.connections.mysql.host'), ['127.0.0.1', 'localhost'])) {
        throw new RuntimeException('Use a local MySQL connection.');
    }
    DB::statement('CREATE DATABASE IF NOT EXISTS `fineworth_portal_relational_test` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $this->info('Dedicated MySQL test database is ready.');
})->purpose('Create the isolated local MySQL test database without modifying portal data');

Artisan::command('portal:legacy-report', function () {
    $table = Schema::hasTable('portal_records') ? 'portal_records' : 'legacy_portal_records';
    $groups = DB::table($table)->orderBy('id')->get()->groupBy('kind');
    foreach ($groups as $kind => $rows) {
        $keys = [];
        foreach ($rows as $row) {
            $keys = array_unique(array_merge($keys, array_keys(json_decode($row->data, true, 512, JSON_THROW_ON_ERROR))));
        }
        sort($keys);
        $this->line($kind.' ('.$rows->count().'): '.implode(', ', $keys));
    }
})->purpose('Inspect legacy field names and counts without printing client data');

Artisan::command('signing:build-samples', function () {
    foreach (['standard', 'extended'] as $variant) {
        $generated = app(SigningDocument::class)->generate(view('agreements.direct-demo', ['extended' => $variant === 'extended'])->render());
        $path = resource_path('demo/direct-'.$variant.'.pdf');
        file_put_contents($path, $generated['pdf']);
        file_put_contents($path.'.json', json_encode($generated['hotspots'], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        $this->info($variant.': '.$generated['hotspots']['page_count'].' pages; signing page '.$generated['hotspots']['fields']['signature']['page']);
    }
})->purpose('Rebuild fictional direct-signing PDFs and their measured hotspot maps');

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
