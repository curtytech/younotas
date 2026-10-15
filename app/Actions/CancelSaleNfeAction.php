<?php

namespace App\Actions;

use App\Models\FiscalDocument;
use App\Models\Sale;
use App\Services\FiscalDocumentWorkflow;
use App\Services\FocusNfeService;
use App\Support\NfeStatus;
use RuntimeException;
use Throwable;

class CancelSaleNfeAction
{
    public function __construct(protected FocusNfeService $service, protected FiscalDocumentWorkflow $workflow) {}

    public function execute(Sale|FiscalDocument $sale, string $justification): array
    {
        $document = $this->workflow->resolve($sale);
        app(\App\Services\FiscalEmissionPreparation::class)->authorize($document->company_id, auth()->id());
        if (! NfeStatus::canCancel($document->status)) {
            throw new RuntimeException('Apenas nota autorizada pode ser cancelada.');
        }
        try {
            $response = $this->service->cancel($document, $justification);
        } catch (Throwable $exception) {
            $this->workflow->recordFailure($document, $exception, 'cancelamento');
            throw $exception;
        }
        $this->workflow->applyResponse($document, $response, 'cancelamento');

        return $response;
    }
}
