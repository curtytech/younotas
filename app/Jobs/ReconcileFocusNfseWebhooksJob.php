<?php

namespace App\Jobs;

use App\Actions\HandleFocusNfseWebhookAction;
use App\Models\FocusNfseWebhookEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ReconcileFocusNfseWebhooksJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $uniqueFor = 900;

    public function __construct(public string $reference) {}

    public function uniqueId(): string
    {
        return $this->reference;
    }

    public function handle(HandleFocusNfseWebhookAction $action): void
    {
        FocusNfseWebhookEvent::query()
            ->where('reference', $this->reference)
            ->whereNull('processed_at')
            ->orderBy('id')
            ->each(fn (FocusNfseWebhookEvent $event) => $action->execute($event->payload));
    }
}
