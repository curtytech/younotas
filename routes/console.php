<?php

use App\Jobs\ConsultSaleNfeJob;
use App\Jobs\ReconcilePendingFocusNfeWebhooksJob;
use App\Jobs\ReconcilePendingFocusNfseWebhooksJob;
use App\Models\Sale;
use App\Support\NfeStatus;
use Illuminate\Support\Facades\Schedule;

Schedule::job(new ReconcilePendingFocusNfseWebhooksJob)->everyFiveMinutes()->withoutOverlapping();
Schedule::job(new ReconcilePendingFocusNfeWebhooksJob)->everyFiveMinutes()->withoutOverlapping();

Schedule::call(function (): void {
    Sale::query()->where('focus_nfe_status', NfeStatus::PROCESSING)
        ->where(fn ($query) => $query->whereNull('focus_nfe_last_checked_at')->orWhere('focus_nfe_last_checked_at', '<', now()->subMinutes(5)))
        ->limit(100)->pluck('id')->each(fn (int $id) => ConsultSaleNfeJob::dispatch($id));
})->name('consult-pending-focus-nfe')->everyFiveMinutes()->withoutOverlapping();

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
