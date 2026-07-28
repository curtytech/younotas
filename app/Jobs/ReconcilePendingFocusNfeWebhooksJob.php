<?php

namespace App\Jobs;

use App\Models\FocusNfeWebhookEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ReconcilePendingFocusNfeWebhooksJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $uniqueFor = 300;

    public function uniqueId(): string
    {
        return 'pending-nfe-webhooks';
    }

    public function handle(): void
    {
        FocusNfeWebhookEvent::query()
            ->whereNull('processed_at')
            ->whereNotNull('reference')
            ->orderBy('id')
            ->limit(100)
            ->pluck('reference')
            ->unique()
            ->each(fn (string $reference) => ReconcileFocusNfeWebhooksJob::dispatch($reference));
    }
}
