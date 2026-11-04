<?php

use App\Models\User;
use App\Services\Portal;
use Illuminate\Contracts\Console\Kernel;
use Symfony\Component\HttpKernel\Exception\HttpException;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (config('app.env') !== 'testing' || config('database.connections.mysql.database') !== 'fineworth_portal_relational_test') {
    throw new RuntimeException('Worker is restricted to the dedicated test database.');
}
$admin = User::findOrFail((int) $argv[2]);
auth()->setUser($admin);
echo "READY\n";
flush();
try {
    app(Portal::class)->review($admin, (int) $argv[1], 'approved', null);
    echo "APPROVED\n";
} catch (HttpException $e) {
    if ($e->getStatusCode() !== 409) {
        throw $e;
    }
    echo "ALREADY_REVIEWED\n";
}
