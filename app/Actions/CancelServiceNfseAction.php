<?php

namespace App\Actions;

use App\Models\FiscalDocument;
use App\Models\ServiceOrder;
use App\Services\FocusNfseService;
use App\Support\NfseStatus;
use RuntimeException;
use Throwable;

class CancelServiceNfseAction
{
    public function __construct(
        protected FocusNfseService $focusNfseService,
    ) {}

    public function execute(ServiceOrder $service, string $justification): array
    {
        $service->refresh();
        if (! NfseStatus::canCancel($service->focus_nfse_status)) {
            throw new RuntimeException('Apenas NFS-e autorizada pode ser cancelada.');
        }

        try {
            $response = $this->focusNfseService->cancel($service, $justification);
        } catch (Throwable $exception) {
            $service->update([
                'focus_nfse_error' => [
                    'operation' => 'cancelamento',
                    'message' => $exception->getMessage(),
                    'exception' => $exception::class,
                    'at' => now()->toIso8601String(),
                ],
            ]);

            FiscalDocument::syncFromServiceOrder($service->fresh());

            throw $exception;
        }

        $status = $response['status'] ?? NfseStatus::CANCELED;

        $service->update([
            'focus_nfse_status' => $status,
            'focus_nfse_response_secure' => $response,
            'focus_nfse_response' => null,
            'focus_nfse_error' => $status === NfseStatus::CANCELLATION_ERROR
                ? ['response' => $response, 'at' => now()->toIso8601String()]
                : null,
        ]);

        if ($status === NfseStatus::CANCELED) {
            $service->update(['status' => 'completed']);
        }

        FiscalDocument::syncFromServiceOrder($service->fresh());

        return $response;
    }
}
