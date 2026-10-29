<?php

use App\Http\Controllers\PortalController as Portal;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');
Route::middleware('guest')->group(function () {
    Route::view('/login', 'login')->name('login');
    Route::post('/login', [Portal::class, 'login'])->middleware('throttle:6,1');
});
Route::middleware('auth')->group(function () {
    Route::get('/signing-demo', [\App\Http\Controllers\SigningDemoController::class, 'show']);
    Route::get('/signing-demo/sample', [\App\Http\Controllers\SigningDemoController::class, 'sample']);
    Route::post('/signing-demo/connect', [\App\Http\Controllers\SigningDemoController::class, 'connect']);
    Route::get('/signing-demo/consent', [\App\Http\Controllers\SigningDemoController::class, 'consent']);
    Route::post('/signing-demo/sign', [\App\Http\Controllers\SigningDemoController::class, 'sign'])->middleware('throttle:10,1')->block();
    Route::get('/signing-demo/return', [\App\Http\Controllers\SigningDemoController::class, 'refresh'])->block();
    Route::post('/signing-demo/refresh', [\App\Http\Controllers\SigningDemoController::class, 'refresh'])->block();
    Route::get('/signing-demo/signed', [\App\Http\Controllers\SigningDemoController::class, 'download']);
    Route::post('/signing-demo/reset', [\App\Http\Controllers\SigningDemoController::class, 'reset'])->block();
    Route::post('/onboarding/{id}/acknowledge', [Portal::class, 'acknowledge']);
    Route::post('/logout', [Portal::class, 'logout']);
    Route::post('/accounts', [Portal::class, 'user']);
    Route::post('/clients/{id}/assign', [Portal::class, 'assign']);
    Route::post('/clients/{id}/profile', [Portal::class, 'profile']);
    Route::post('/holdings', [Portal::class, 'holding']);
    Route::post('/documents', [Portal::class, 'document']);
    Route::get('/documents/{id}/download', [Portal::class, 'download']);
    Route::post('/approvals/{id}', [Portal::class, 'review']);
    Route::post('/requests', [Portal::class, 'service']);
    Route::post('/requests/{id}', [Portal::class, 'progress']);
    Route::get('/{page}/{id?}', [Portal::class, 'page'])->where('page', 'dashboard|holdings|holding|documents|requests|clients|client|managers|approvals|account')->whereNumber('id');
});
