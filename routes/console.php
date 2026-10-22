<?php

use App\Jobs\ReconcilePendingFiscalDocumentsJob;
use App\Jobs\ReconcilePendingFocusNfeWebhooksJob;
use App\Jobs\ReconcilePendingFocusNfseWebhooksJob;
use Illuminate\Support\Facades\Schedule;

Schedule::job(new ReconcilePendingFocusNfseWebhooksJob)->everyFiveMinutes()->withoutOverlapping();
Schedule::job(new ReconcilePendingFocusNfeWebhooksJob)->everyFiveMinutes()->withoutOverlapping();

Schedule::job(new ReconcilePendingFiscalDocumentsJob)->everyMinute()->withoutOverlapping();

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
