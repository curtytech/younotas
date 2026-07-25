<?php

use App\Http\Controllers\FocusNfeWebhookController;
use App\Http\Controllers\FocusNfseWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('/webhooks/focus-nfse', FocusNfseWebhookController::class)
    ->middleware('throttle:30,1');

Route::post('/webhooks/focus-nfe', FocusNfeWebhookController::class)
    ->middleware('throttle:30,1');
