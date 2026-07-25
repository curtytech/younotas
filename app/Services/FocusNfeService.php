<?php

namespace App\Services;

use App\Exceptions\FocusNfeRequestException;
use App\Models\Sale;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class FocusNfeService
{
    public function __construct(
        protected FocusNfeConfigService $configService,
        protected FocusDanfePreviewService $payloadService,
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
        $response = $this->client($config)->asJson()->post('/v2/nfe?ref='.urlencode($reference), $payload);
        $this->throwForFailure($response);

        return $response->json() ?? [];
    }

    public function consult(Sale $sale): array
    {
        if (blank($sale->focus_nfe_ref)) {
            throw new RuntimeException('A venda não possui uma referência de NF-e para consultar.');
        }

        $response = $this->client($this->configurationFor($sale))
            ->get('/v2/nfe/'.urlencode($sale->focus_nfe_ref));
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

        $response = $this->client($this->configurationFor($sale))
            ->asJson()->delete('/v2/nfe/'.urlencode($sale->focus_nfe_ref), ['justificativa' => $justification]);
        $this->throwForFailure($response);

        return $response->json() ?? [];
    }

    protected function client(array $config): PendingRequest
    {
        if (blank($config['api_key'] ?? null)) {
            throw new RuntimeException('Configure a API Key da Focus na Configuração Fiscal.');
        }

        return Http::baseUrl(rtrim((string) ($config['base_url'] ?? config('services.focus_nfe.base_url')), '/'))
            ->withBasicAuth((string) $config['api_key'], (string) ($config['api_password'] ?? ''))
            ->acceptJson()->connectTimeout(5)->timeout(30);
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
