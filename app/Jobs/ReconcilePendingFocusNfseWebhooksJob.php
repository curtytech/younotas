<?php

namespace App\Jobs;

use App\Models\FocusNfseWebhookEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ReconcilePendingFocusNfseWebhooksJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $uniqueFor = 300;

    public function uniqueId(): string
    {
        return 'pending-webhooks';
    }

    public function handle(): void
    {
        FocusNfseWebhookEvent::query()
            ->whereNull('processed_at')
            ->whereNotNull('reference')
            ->orderBy('id')
            ->limit(100)
            ->pluck('reference')
            ->unique()
            ->each(fn (string $reference) => ReconcileFocusNfseWebhooksJob::dispatch($reference));
    }
}
