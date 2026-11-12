<?php

// Local-only additive migration: verified SQL backup, rehearsal, then optional cutover.
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$config = config('database.connections.mysql');
if (config('database.default') !== 'mysql' || ! in_array($config['host'], ['127.0.0.1', 'localhost']) || $config['database'] !== 'fineworth-portal') {
    throw new RuntimeException('Workflow cutover is restricted to the named local working database.');
}
$apply = in_array('--apply', $argv, true);
$stamp = gmdate('Ymd_His');
$destination = 'fineworth_portal_workflow_rehearsal_'.$stamp;
$directory = storage_path('app/private/backups/workflow-'.$stamp);
if (! mkdir($directory, 0700, true)) {
    throw new RuntimeException('Cannot create private backup.');
}
$source = $config['database'];
$manifest = [];
foreach (DB::getSchemaBuilder()->getTables($source) as $table) {
    $name = $table['name'];
    if ($name === 'migrations') {
        continue;
    }
    $columns = DB::getSchemaBuilder()->getColumnListing($name);
    $rows = DB::table($name)->select($columns)->get()->map(fn ($row) => json_encode($row, JSON_THROW_ON_ERROR))->sort()->values()->all();
    $manifest[$name] = ['columns' => $columns, 'count' => count($rows), 'sha256' => hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR))];
}
$files = [];
foreach (DB::table('document_versions')->whereNotNull('path')->pluck('path')->merge(DB::table('direct_signatures')->pluck('path'))->unique() as $path) {
    if (! Storage::disk('local')->exists($path)) {
        throw new RuntimeException('A referenced private file is missing.');
    }
    $files[$path] = hash_file('sha256', Storage::disk('local')->path($path));
}
file_put_contents($directory.'/manifest.json', json_encode(['tables' => $manifest, 'files' => $files], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
$verify = function () use ($manifest, $files) {
    foreach ($manifest as $table => $expected) {
        $rows = DB::table($table)->select($expected['columns'])->get()->map(fn ($row) => json_encode($row, JSON_THROW_ON_ERROR))->sort()->values()->all();
        if (hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR)) !== $expected['sha256']) {
            throw new RuntimeException('Pre-existing data differs: '.$table);
        }
    }
    foreach ($files as $path => $hash) {
        if (hash_file('sha256', Storage::disk('local')->path($path)) !== $hash) {
            throw new RuntimeException('A private file changed.');
        }
    }
};
Artisan::call('down');
try {
    $statements = ['SET FOREIGN_KEY_CHECKS=0'];
    foreach (DB::getSchemaBuilder()->getTables($source) as $table) {
        $name = $table['name'];
        $quoted = '`'.str_replace('`', '``', $name).'`';
        $definition = (array) DB::selectOne('SHOW CREATE TABLE '.$quoted);
        $statements[] = $definition['Create Table'];
        foreach (DB::table($name)->get() as $row) {
            $fields = array_keys((array) $row);
            $columns = implode(',', array_map(fn ($field) => '`'.str_replace('`', '``', $field).'`', $fields));
            $values = implode(',', array_map(fn ($value) => $value === null ? 'NULL' : DB::getPdo()->quote((string) $value), array_values((array) $row)));
            $statements[] = 'INSERT INTO '.$quoted.' ('.$columns.') VALUES ('.$values.')';
        }
    }
    $statements[] = 'SET FOREIGN_KEY_CHECKS=1';
    // Keep the full restore material private; no credentials enter a command line.
    file_put_contents($directory.'/before.sql', implode(";\n", $statements).";\n");
    DB::statement('CREATE DATABASE `'.$destination.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    config(['database.connections.mysql.database' => $destination]);
    DB::purge('mysql');
    foreach ($statements as $sql) {
        DB::unprepared($sql);
    }
    config(['database.connections.mysql.database' => $destination]);
    DB::purge('mysql');
    if (Artisan::call('migrate', ['--force' => true]) !== 0) {
        throw new RuntimeException('Rehearsal migration failed.');
    }
    $verify();
    echo 'Rehearsal verified: '.count($manifest).' existing tables and '.count($files).' private files unchanged.'.PHP_EOL;
    config(['database.connections.mysql.database' => $source]);
    DB::purge('mysql');
    if ($apply) {
        $verify();
        if (Artisan::call('migrate', ['--force' => true]) !== 0) {
            throw new RuntimeException('Local migration failed; retain backup and inspect before retrying.');
        }
        $verify();
        echo 'Local additive migration verified. No existing client was approved and no holding was released automatically.'.PHP_EOL;
    }
    echo 'Backup retained privately: '.$directory.PHP_EOL;
} finally {
    config(['database.connections.mysql.database' => $source]);
    DB::purge('mysql');
    Artisan::call('up');
}
