<?php

namespace App\Services;

use App\Models\FiscalDocument;
use App\Models\FiscalEvent;

class FiscalAudit
{
    public function record(FiscalDocument $document, string $event, array $context = [], ?int $actorId = null): void
    {
        FiscalEvent::create([
            'company_id' => $document->company_id,
            'fiscal_document_id' => $document->id,
            'actor_id' => $actorId,
            'event' => $event,
            'context' => array_intersect_key($context, array_flip([
                'reference', 'environment', 'previous_status', 'status', 'operation',
                'http_status', 'exception', 'attempt', 'origin', 'reason',
            ])),
        ]);
    }
}
