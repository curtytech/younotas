<?php

namespace App\Jobs;

use App\Actions\HandleFocusNfeWebhookAction;
use App\Models\FocusNfeWebhookEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ReconcileFocusNfeWebhooksJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $uniqueFor = 900;

    public function __construct(public string $reference) {}

    public function uniqueId(): string
    {
        return $this->reference;
    }

    public function handle(HandleFocusNfeWebhookAction $action): void
    {
        FocusNfeWebhookEvent::query()
            ->where('reference', $this->reference)
            ->whereNull('processed_at')
            ->orderBy('id')
            ->each(fn (FocusNfeWebhookEvent $event) => $action->execute($event->payload));
    }
}
