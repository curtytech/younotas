<?php

namespace App\Actions;

use App\Models\FiscalDocument;
use App\Models\ServiceOrder;
use App\Services\FiscalDocumentWorkflow;
use App\Services\FocusNfseService;
use App\Support\NfseStatus;
use RuntimeException;
use Throwable;

class CancelServiceNfseAction
{
    public function __construct(protected FocusNfseService $service, protected FiscalDocumentWorkflow $workflow) {}

    public function execute(ServiceOrder|FiscalDocument $service, string $justification): array
    {
        $document = $this->workflow->resolve($service);
        app(\App\Services\FiscalEmissionPreparation::class)->authorize($document->company_id, auth()->id());
        if (! NfseStatus::canCancel($document->status)) {
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
