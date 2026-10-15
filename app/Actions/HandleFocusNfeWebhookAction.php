<?php

namespace App\Actions;

use App\Models\Sale;
use App\Services\FocusWebhookProcessor;

class HandleFocusNfeWebhookAction
{
    public function __construct(protected FocusWebhookProcessor $processor) {}

    public function execute(array $payload): ?Sale
    {
        $event = $this->processor->capture('NF-e', $payload);
        $document = $this->processor->process('NF-e', $event->id);

        return $document && $document->source_type === Sale::class ? Sale::find($document->source_id) : null;
    }
}
