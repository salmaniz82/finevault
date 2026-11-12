<?php

// Read-only local export of the explicitly approved fictional staging dataset.
use App\Services\StagingDemo;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('local') || ! in_array(config('database.connections.mysql.host'), ['localhost', '127.0.0.1'])) {
    throw new RuntimeException('Export only from local development.');
}
$directory = database_path('seeders/fixtures/staging');
if (is_dir($directory)) {
    throw new RuntimeException('Snapshot already exists; review changes before replacing it.');
}
mkdir($directory, 0755, true);
$snapshot = DB::transaction(function () {
    $tables = [];
    foreach (StagingDemo::TABLES as $table) {
        $tables[$table] = DB::table($table)->get()->map(fn ($row) => (array) $row)->all();
    }
    foreach ($tables['users'] as &$user) {
        if (! str_ends_with($user['email'], '@fineworth.test')) {
            throw new RuntimeException('Non-demo user found; refusing export.');
        }
        unset($user['password'], $user['remember_token']);
    }
    unset($user);
    if ($tables['signing_requests']) {
        throw new RuntimeException('Review external signing references before exporting.');
    }
    $assignments = [];
    foreach ($tables['client_manager_assignments'] as $assignment) {
        $assignments[$assignment['client_id']][] = $assignment['manager_id'];
    }
    return ['version' => 1, 'tables' => $tables, 'manager_ids' => $assignments, 'files' => []];
});
foreach (['document_versions', 'direct_signatures', 'holding_update_files'] as $table) {
    foreach ($snapshot['tables'][$table] as $row) {
        if (empty($row['path'])) {
            continue;
        }
        $path = $row['path'];
        $bytes = Storage::disk('local')->get($path);
        $hash = hash('sha256', $bytes);
        file_put_contents($directory.'/'.$hash.'.pdf', $bytes);
        $snapshot['files'][$path] = $hash;
    }
}
file_put_contents($directory.'/snapshot.json', json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
echo 'Exported '.count($snapshot['tables']['users']).' demo users, '.count($snapshot['tables']['portal_entries']).' entries and '.count($snapshot['files']).' private demo files; no login credentials exported.'.PHP_EOL;
