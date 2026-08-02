<?php

namespace App\Services;

use App\Exceptions\FocusNfeRequestException;
use App\Models\Sale;
use Illuminate\Http\Client\Response;
use RuntimeException;

class FocusNfeService
{
    public function __construct(
        protected FocusNfeConfigService $configService,
        protected FocusDanfePreviewService $payloadService,
        protected FocusApiClient $apiClient,
    ) {}

    public function configurationFor(Sale $sale): array
    {
        $sale->loadMissing('user.focusNfeSetting');

        return $this->configService->forUser($sale->user);
    }

    public function buildPayload(Sale $sale, array $config): array
    {
        return $this->payloadService->buildPayload($sale);
    }

    public function emit(array $config, string $reference, array $payload): array
    {
        $response = $this->apiClient->post($config, '/v2/nfe', $payload, ['ref' => $reference]);
        $this->throwForFailure($response);

        return $response->json() ?? [];
    }

    public function consult(Sale $sale): array
    {
        if (blank($sale->focus_nfe_ref)) {
            throw new RuntimeException('A venda não possui uma referência de NF-e para consultar.');
        }

        $response = $this->apiClient->get(
            $this->configurationFor($sale),
            '/v2/nfe/'.urlencode($sale->focus_nfe_ref),
        );
        $this->throwForFailure($response);

        return $response->json() ?? [];
    }

    public function cancel(Sale $sale, string $justification): array
    {
        if (blank($sale->focus_nfe_ref)) {
            throw new RuntimeException('A venda não possui uma referência de NF-e para cancelar.');
        }

        $justification = trim($justification);
        if (mb_strlen($justification) < 15 || mb_strlen($justification) > 255) {
            throw new RuntimeException('A justificativa deve ter entre 15 e 255 caracteres.');
        }

        $response = $this->apiClient->delete(
            $this->configurationFor($sale),
            '/v2/nfe/'.urlencode($sale->focus_nfe_ref),
            ['justificativa' => $justification],
        );
        $this->throwForFailure($response);

        return $response->json() ?? [];
    }

    protected function throwForFailure(Response $response): void
    {
        if ($response->successful()) {
            return;
        }

        $data = $response->json();
        throw new FocusNfeRequestException(
            $data['mensagem'] ?? $data['message'] ?? data_get($data, 'erros.0.mensagem') ?? $response->body(),
            $response->status(),
            is_array($data) ? $data : null,
        );
    }
}
