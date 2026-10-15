<?php

namespace App\Actions;

use App\Models\FiscalDocument;
use App\Models\Sale;
use App\Services\FiscalDocumentWorkflow;
use App\Services\FocusNfeService;
use Throwable;

class ConsultSaleNfeAction
{
    public function __construct(protected FocusNfeService $service, protected FiscalDocumentWorkflow $workflow) {}

    public function execute(Sale|FiscalDocument $sale): array
    {
        $document = $this->workflow->resolve($sale);
        app(\App\Services\FiscalEmissionPreparation::class)->authorize($document->company_id, auth()->id());
        try {
            $response = $this->service->consult($document);
        } catch (Throwable $exception) {
            $this->workflow->recordFailure($document, $exception, 'consulta');
            throw $exception;
        }
        $this->workflow->applyResponse($document, $response, 'consulta');

        return $response;
    }
}
