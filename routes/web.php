<?php

use App\Http\Controllers\DealController;
use App\Http\Controllers\DealSigningController;
use App\Http\Controllers\PortalController as Portal;
use App\Http\Controllers\SigningDemoController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');
Route::middleware('guest')->group(function () {
    Route::view('/login', 'login')->name('login');
    Route::post('/login', [Portal::class, 'login'])->middleware('throttle:6,1');
});
Route::middleware('auth')->group(function () {
    Route::get('/deals', [DealController::class, 'index']);
    Route::post('/deals/{id}/sign', [DealSigningController::class, 'sign'])->whereNumber('id')->middleware('throttle:10,1')->block();
    Route::get('/deals/{id}/signing-return', [DealSigningController::class, 'refresh'])->whereNumber('id')->block();
    Route::post('/deals/{id}/signing-refresh', [DealSigningController::class, 'refresh'])->whereNumber('id')->middleware('throttle:10,1')->block();
    Route::post('/deals', [DealController::class, 'store']);
    Route::get('/deals/{id}', [DealController::class, 'show'])->whereNumber('id');
    Route::post('/deals/{id}/generate', [DealController::class, 'generate'])->whereNumber('id')->block();
    Route::post('/deals/{id}/acknowledge', [DealController::class, 'acknowledge'])->whereNumber('id');
    Route::get('/signing-demo', [SigningDemoController::class, 'show']);
    Route::get('/signing-demo/sample', [SigningDemoController::class, 'sample']);
    Route::post('/signing-demo/connect', [SigningDemoController::class, 'connect']);
    Route::get('/signing-demo/consent', [SigningDemoController::class, 'consent']);
    Route::post('/signing-demo/sign', [SigningDemoController::class, 'sign'])->middleware('throttle:10,1')->block();
    Route::get('/signing-demo/return', [SigningDemoController::class, 'refresh'])->block();
    Route::post('/signing-demo/refresh', [SigningDemoController::class, 'refresh'])->block();
    Route::get('/signing-demo/signed', [SigningDemoController::class, 'download']);
    Route::post('/signing-demo/reset', [SigningDemoController::class, 'reset'])->block();
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
