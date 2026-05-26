<?php

namespace App\Actions;

use App\Models\Service;
use App\Services\FocusNfseService;

class EmitServiceNfseAction
{
    public function __construct(
        protected FocusNfseService $focusNfseService,
    ) {}

    public function execute(Service $service): array
    {
        $result = $this->focusNfseService->emit($service, $service->focus_nfse_ref);
        $response = $result['response'];

        $service->update([
            'focus_nfse_ref' => $result['reference'],
            'focus_nfse_status' => $response['status'] ?? 'processando',
            'focus_nfse_number' => $response['numero'] ?? $response['numero_rps'] ?? null,
            'focus_nfse_url' => $response['url'] ?? $response['url_danfe'] ?? null,
            'focus_nfse_response' => $response,
            'focus_nfse_last_sent_at' => now(),
        ]);

        return $response;
    }
}
