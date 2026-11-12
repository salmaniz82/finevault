<?php

use App\Http\Controllers\DealController;
use App\Http\Controllers\DealSigningController;
use App\Http\Controllers\DirectSigningController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\PortalController as Portal;
use App\Http\Controllers\SigningDemoController;
use App\Http\Controllers\UpdateController;
use App\Http\Controllers\WorkspaceController;
use App\Services\Workflow;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');
Route::middleware('guest')->group(function () {
    Route::view('/login', 'login')->name('login');
    Route::post('/login', [Portal::class, 'login'])->middleware('throttle:6,1');
});
Route::middleware('auth')->group(function () {
    Route::get('/onboarding', [OnboardingController::class, 'show']);
    Route::get('/clients/{id}/onboarding', [OnboardingController::class, 'show'])->whereNumber('id');
    Route::post('/onboarding/{id}/details', [OnboardingController::class, 'save'])->whereNumber('id');
    Route::post('/onboarding/{id}/documents', [OnboardingController::class, 'upload'])->whereNumber('id');
    Route::post('/onboarding/{id}/submit', [OnboardingController::class, 'submit'])->whereNumber('id');
    Route::post('/onboarding/{id}/review', [OnboardingController::class, 'review'])->whereNumber('id');
    Route::get('/email/verify', fn () => redirect('/onboarding'))->name('verification.notice');
    Route::get('/email/verify/{id}/{hash}', function (EmailVerificationRequest $request) {
        $request->fulfill();
        Workflow::audit('onboarding.email_verified', client: $request->user()->id);

        return redirect('/onboarding')->with('success', 'Email verified.');
    })->middleware(['signed', 'throttle:6,1'])->name('verification.verify');
    Route::post('/email/verification-notification', function (Request $request) {
        if (! $request->user()->hasVerifiedEmail()) {
            $request->user()->sendEmailVerificationNotification();
        }

        return back()->with('success', 'Verification email requested.');
    })->middleware('throttle:6,1')->name('verification.send');
    Route::get('/activity', [WorkspaceController::class, 'activity']);
    Route::get('/notifications', [WorkspaceController::class, 'notifications']);
    Route::post('/notifications/{id}/read', [WorkspaceController::class, 'readNotification'])->whereNumber('id');
    Route::get('/requests/{id}', [WorkspaceController::class, 'request'])->whereNumber('id');
    Route::post('/holdings/{id}/agreement', [WorkspaceController::class, 'link'])->whereNumber('id');
    Route::post('/holdings/{id}/release', [WorkspaceController::class, 'release'])->whereNumber('id');
    Route::post('/holdings/{id}/updates', [UpdateController::class, 'store'])->whereNumber('id');
    Route::post('/updates/{id}/review', [UpdateController::class, 'review'])->whereNumber('id');
    Route::post('/updates/{id}/restore', [UpdateController::class, 'restore'])->whereNumber('id');
    Route::get('/update-files/{id}', [UpdateController::class, 'file'])->whereNumber('id');
    Route::post('/deals/{id}/edit', [DealController::class, 'edit'])->whereNumber('id');
    Route::get('/direct-signing-demo', [DirectSigningController::class, 'show'])->block();
    Route::get('/direct-signing-demo/sample', [DirectSigningController::class, 'sample']);
    Route::post('/direct-signing-demo/save', [DirectSigningController::class, 'save'])->middleware('throttle:10,1')->block();
    Route::post('/direct-signing-demo/reset', [DirectSigningController::class, 'reset'])->block();
    Route::get('/direct-signing-demo/{id}/download', [DirectSigningController::class, 'download'])->whereNumber('id');
    Route::get('/deals', [DealController::class, 'index']);
    Route::get('/deals/create', [DealController::class, 'create'])->name('deals.create');
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
