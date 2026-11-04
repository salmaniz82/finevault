<?php

use App\Services\RelationalMigration;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

// CLI only. Back up the configured local database, restore into a NEW rehearsal database,
// migrate that copy, and verify all legacy fields and private-file hashes before cutover.
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$config = config('database.connections.mysql');
$destination = 'fineworth_portal_migration_rehearsal';
$backupOnly = ($argv[2] ?? '') === '--backup-only';
if (config('database.default') !== 'mysql' || ! in_array($config['host'], ['127.0.0.1', 'localhost']) || $config['database'] === $destination) {
    throw new RuntimeException('Rehearsal requires the original local MySQL connection.');
}
if (! DB::getSchemaBuilder()->hasTable('portal_records')) {
    throw new RuntimeException('Source database is already migrated.');
}
if (! $backupOnly && DB::selectOne('SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?', [$destination])) {
    throw new RuntimeException('Rehearsal database already exists; refusing to overwrite it.');
}
$bin = $argv[1] ?? '';
$dumpBinary = $bin ? $bin.'/mysqldump.exe' : 'mysqldump';
$mysqlBinary = $bin ? $bin.'/mysql.exe' : 'mysql';
$directory = storage_path('app/private/backups/relational-'.gmdate('Ymd-His'));
if (! mkdir($directory, 0700, true)) {
    throw new RuntimeException('Cannot create private backup directory.');
}
$dump = $directory.'/before.sql';
$args = ['--host='.$config['host'], '--port='.$config['port'], '--user='.$config['username']];
$environment = ['MYSQL_PWD' => $config['password'] ?? ''];
$process = new Process([$dumpBinary, ...$args, '--single-transaction', '--no-tablespaces', '--set-gtid-purged=OFF', '--result-file='.$dump, $config['database']], null, $environment);
$process->setTimeout(120)->mustRun();
if (! is_file($dump) || filesize($dump) === 0) {
    throw new RuntimeException('Database backup is empty.');
}
$manifest = [];
foreach (DB::table('portal_records')->get() as $row) {
    $data = json_decode($row->data, true, 512, JSON_THROW_ON_ERROR);
    if (! empty($data['path'])) {
        if (! Storage::disk('local')->exists($data['path'])) {
            throw new RuntimeException('Missing private file for record '.$row->id);
        }
        $manifest[$data['path']] = hash_file('sha256', Storage::disk('local')->path($data['path']));
    }
}
file_put_contents($directory.'/files.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
echo 'Private backup: '.$directory.PHP_EOL;
if ($backupOnly) {
    exit(0);
}
DB::statement('CREATE DATABASE `'.$destination.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$input = fopen($dump, 'rb');
$restore = new Process([$mysqlBinary, ...$args, $destination], null, $environment);
$restore->setInput($input)->setTimeout(120)->mustRun();
fclose($input);
config(['database.connections.mysql.database' => $destination]);
DB::purge('mysql');
if (DB::connection()->getDatabaseName() !== $destination) {
    throw new RuntimeException('Rehearsal connection mismatch.');
}
$exit = Artisan::call('migrate', ['--force' => true]);
echo Artisan::output();
if ($exit !== 0) {
    throw new RuntimeException('Rehearsal migration failed.');
}
$counts = app(RelationalMigration::class)->verify();
foreach ($manifest as $path => $hash) {
    if (! hash_equals($hash, hash_file('sha256', Storage::disk('local')->path($path)))) {
        throw new RuntimeException('Private file changed during migration.');
    }
}
file_put_contents($directory.'/rehearsal.json', json_encode(['database' => $destination, 'counts' => $counts, 'files_verified' => count($manifest), 'verified_at' => gmdate('c')], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
echo 'Rehearsal verified: '.array_sum($counts).' records; '.count($manifest).' unchanged private files.'.PHP_EOL;
