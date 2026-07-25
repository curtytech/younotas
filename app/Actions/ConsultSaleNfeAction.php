<?php

namespace App\Actions;

use App\Models\Sale;
use App\Services\FocusNfeService;
use App\Support\NfeStatus;
use Throwable;

class ConsultSaleNfeAction
{
    public function __construct(protected FocusNfeService $focusNfeService) {}

    public function execute(Sale $sale): array
    {
        try {
            $response = $this->focusNfeService->consult($sale);
        } catch (Throwable $exception) {
            $sale->update(['focus_nfe_last_checked_at' => now(), 'focus_nfe_error' => [
                'operation' => 'consulta', 'message' => $exception->getMessage(), 'exception' => $exception::class,
                'at' => now()->toIso8601String(),
            ]]);
            throw $exception;
        }

        $sale->refresh();
        $incoming = NfeStatus::normalize($response['status'] ?? null);
        $status = NfeStatus::shouldReplace($sale->focus_nfe_status, $incoming) ? $incoming : $sale->focus_nfe_status;
        $sale->update([
            'focus_nfe_status' => $status,
            'focus_nfe_number' => $response['numero'] ?? $response['numero_nfe'] ?? $sale->focus_nfe_number,
            'focus_nfe_url' => $response['url_danfe'] ?? $response['url'] ?? $sale->focus_nfe_url,
            'focus_nfe_response_secure' => $response,
            'focus_nfe_last_checked_at' => now(),
            'focus_nfe_error' => $status === NfeStatus::AUTHORIZATION_ERROR ? ['response' => $response, 'at' => now()->toIso8601String()] : $sale->focus_nfe_error,
        ]);

        return $response;
    }
}
