<?php

use App\Http\Controllers\PublicUnsubscribeController;
use App\Http\Controllers\PublicProposalController;
use App\Http\Controllers\PublicPreferenceController;
use App\Http\Controllers\Api\PublicTrackingController;
use App\Http\Controllers\TrackingController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/unsubscribe/{token}', PublicUnsubscribeController::class)
    ->middleware('throttle:unsubscribe')
    ->name('public.unsubscribe');

Route::get('/preferences/{token}', [PublicPreferenceController::class, 'show'])
    ->middleware('throttle:unsubscribe')
    ->name('public.preferences.show');

Route::post('/preferences/{token}', [PublicPreferenceController::class, 'update'])
    ->middleware('throttle:unsubscribe')
    ->name('public.preferences.update');

Route::get('/track/open/{token}', [TrackingController::class, 'open'])
    ->name('tracking.open');

Route::get('/track/click/{token}', [TrackingController::class, 'click'])
    ->name('tracking.click');

Route::get('/t/{tenantPublicKey}/tracker.js', [PublicTrackingController::class, 'trackerScript'])
    ->where('tenantPublicKey', '[A-Za-z0-9_\\-]+')
    ->name('public.tracker.script');

Route::get('/proposals/{token}', [PublicProposalController::class, 'show'])
    ->middleware('throttle:public-portal-status')
    ->name('public.proposals.view');

Route::post('/proposals/{token}/accept', [PublicProposalController::class, 'accept'])
    ->middleware('throttle:public-portal')
    ->name('public.proposals.accept');

Route::get('/proposals/{token}/pdf', [PublicProposalController::class, 'pdf'])
    ->middleware('throttle:public-portal-status')
    ->name('public.proposals.pdf');
