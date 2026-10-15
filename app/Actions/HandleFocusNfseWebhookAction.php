<?php

namespace App\Actions;

use App\Models\ServiceOrder;
use App\Services\FocusWebhookProcessor;

class HandleFocusNfseWebhookAction
{
    public function __construct(protected FocusWebhookProcessor $processor) {}

    public function execute(array $payload): ?ServiceOrder
    {
        $event = $this->processor->capture('NFS-e', $payload);
        $document = $this->processor->process('NFS-e', $event->id);

        return $document && $document->source_type === ServiceOrder::class ? ServiceOrder::find($document->source_id) : null;
    }
}
