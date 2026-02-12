<?php

use App\Http\Controllers\PublicUnsubscribeController;
use App\Http\Controllers\TrackingController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/unsubscribe/{token}', PublicUnsubscribeController::class)
    ->middleware('throttle:unsubscribe')
    ->name('public.unsubscribe');

Route::get('/track/open/{token}', [TrackingController::class, 'open'])
    ->name('tracking.open');

Route::get('/track/click/{token}', [TrackingController::class, 'click'])
    ->name('tracking.click');
