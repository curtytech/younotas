<?php

namespace App\Jobs;

use App\Services\FocusWebhookProcessor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessFocusWebhookJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;
    public array $backoff = [30, 120, 300, 600];
    public int $uniqueFor = 900;

    public function __construct(public string $documentType, public int $eventId) {}

    public function uniqueId(): string
    {
        return $this->documentType.':'.$this->eventId;
    }

    public function handle(FocusWebhookProcessor $processor): void
    {
        $processor->process($this->documentType, $this->eventId);
    }
}
