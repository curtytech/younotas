<?php

namespace App\Actions;

use App\Models\ServiceOrder;
use App\Services\FocusNfseService;
use App\Support\NfseStatus;
use Throwable;

class ConsultServiceNfseAction
{
    public function __construct(
        protected FocusNfseService $focusNfseService,
    ) {}

    public function execute(ServiceOrder $service): array
    {
        try {
            $response = $this->focusNfseService->consult($service);
        } catch (Throwable $exception) {
            $service->update([
                'focus_nfse_last_checked_at' => now(),
                'focus_nfse_error' => [
                    'operation' => 'consulta',
                    'message' => $exception->getMessage(),
                    'exception' => $exception::class,
                    'at' => now()->toIso8601String(),
                ],
            ]);

            throw $exception;
        }

        $service->refresh();
        $incomingStatus = NfseStatus::normalize($response['status'] ?? null);
        $status = NfseStatus::shouldReplace($service->focus_nfse_status, $incomingStatus)
            ? $incomingStatus
            : $service->focus_nfse_status;

        $service->update([
            'focus_nfse_status' => $status,
            'focus_nfse_number' => $response['numero'] ?? $response['numero_rps'] ?? $service->focus_nfse_number,
            'focus_nfse_url' => $response['url'] ?? $response['url_danfe'] ?? $service->focus_nfse_url,
            'focus_nfse_response_secure' => $response,
            'focus_nfse_response' => null,
            'focus_nfse_last_checked_at' => now(),
            'focus_nfse_error' => in_array($status, [NfseStatus::AUTHORIZATION_ERROR, NfseStatus::CANCELLATION_ERROR], true)
                ? ['response' => $response, 'at' => now()->toIso8601String()]
                : $service->focus_nfse_error,
        ]);

        if ($status === NfseStatus::AUTHORIZED) {
            $service->update(['status' => 'billed']);
        } elseif ($status === NfseStatus::CANCELED) {
            $service->update(['status' => 'completed']);
        }

        return $response;
    }
}
