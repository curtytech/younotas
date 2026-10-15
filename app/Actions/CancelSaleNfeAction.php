<?php

namespace App\Actions;

use App\Models\Sale;
use App\Services\FocusNfeService;
use App\Support\NfeStatus;
use RuntimeException;
use Throwable;

class CancelSaleNfeAction
{
    public function __construct(protected FocusNfeService $focusNfeService) {}

    public function execute(Sale $sale, string $justification): array
    {
        $sale->refresh();
        if (! NfeStatus::canCancel($sale->focus_nfe_status)) {
            throw new RuntimeException('Apenas NF-e autorizada pode ser cancelada.');
        }

        try {
            $response = $this->focusNfeService->cancel($sale, $justification);
        } catch (Throwable $exception) {
            $sale->update(['focus_nfe_error' => [
                'operation' => 'cancelamento', 'message' => $exception->getMessage(), 'exception' => $exception::class,
                'at' => now()->toIso8601String(),
            ]]);
            throw $exception;
        }

        $status = NfeStatus::normalize($response['status'] ?? NfeStatus::CANCELED);
        $sale->update([
            'focus_nfe_status' => $status,
            'focus_nfe_response_secure' => $response,
            'focus_nfe_error' => $status === NfeStatus::CANCELLATION_ERROR ? ['response' => $response, 'at' => now()->toIso8601String()] : null,
        ]);

        return $response;
    }
}
