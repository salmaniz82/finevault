<?php

use App\Http\Controllers\DealController;
use App\Http\Controllers\DirectSigningController;
use App\Http\Controllers\PortalController;
use App\Models\User;
use App\Services\DirectSignature;
use App\Services\Portal;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ViewErrorBag;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
// Read-only controller/view smoke check against migrated records; no requests are posted.
config(['session.driver' => 'array']);
View::share('errors', new ViewErrorBag);
$rendered = 0;
foreach (User::orderBy('id')->get() as $user) {
    auth()->setUser($user);
    $request = Request::create('/dashboard');
    $request->setUserResolver(fn () => $user);
    $request->setLaravelSession(app('session')->driver());
    app()->instance('request', $request);
    $pages = ['dashboard', 'holdings', 'documents', 'requests', 'account'];
    if ($user->role !== 'client') {
        $pages[] = 'clients';
    }
    if ($user->role === 'admin') {
        $pages = [...$pages, 'managers', 'approvals'];
    }
    foreach ($pages as $page) {
        app(PortalController::class)->page($request, $page)->render();
        $rendered++;
    }
    foreach (app(Portal::class)->records($user, 'holding')->get() as $holding) {
        app(PortalController::class)->page($request, 'holding', $holding->id)->render();
        $rendered++;
    }
    if ($user->role !== 'manager') {
        app(DealController::class)->index($request)->render();
        $rendered++;
        foreach (app(Portal::class)->records($user, 'deal')->get() as $deal) {
            app(DealController::class)->show($request, $deal->id)->render();
            $rendered++;
        }
    }
    if ($user->role === 'admin') {
        app(DirectSigningController::class)->show($request, app(DirectSignature::class))->render();
        $rendered++;
    }
}
echo 'Rendered '.$rendered.' authorised pages from migrated local records without business-data writes.'.PHP_EOL;
