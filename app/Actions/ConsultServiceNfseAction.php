<?php

namespace App\Actions;

use App\Models\FiscalDocument;
use App\Models\ServiceOrder;
use App\Services\FiscalDocumentWorkflow;
use App\Services\FocusNfseService;
use Throwable;

class ConsultServiceNfseAction
{
    public function __construct(protected FocusNfseService $service, protected FiscalDocumentWorkflow $workflow) {}

    public function execute(ServiceOrder|FiscalDocument $service): array
    {
        $document = $this->workflow->resolve($service);
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
