<?php

namespace App\Services;

use App\Exceptions\FocusNfeRequestException;
use App\Models\FiscalDocument;
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

    public function configurationFor(Sale|FiscalDocument $sale): array
    {
        if ($sale instanceof Sale && blank($sale->focus_nfe_ref)) {
            return $sale->company_id ? $this->configService->forCompany($sale->company) : $this->configService->forUser($sale->user);
        }
        return $this->configService->forDocument(app(FiscalDocumentWorkflow::class)->resolve($sale));
    }

    public function buildPayload(Sale $sale, array $config): array
    {
        return $this->payloadService->buildPayload($sale, $config);
    }

    public function emit(array $config, string $reference, array $payload): array
    {
        $response = $this->apiClient->post($config, '/v2/nfe', $payload, ['ref' => $reference]);
        $this->throwForFailure($response);

        return FocusResponse::document($response);
    }

    public function consult(Sale|FiscalDocument $sale): array
    {
        $document = app(FiscalDocumentWorkflow::class)->resolve($sale);
        if (blank($document->focus_reference)) {
            throw new RuntimeException('A venda não possui uma referência de NF-e para consultar.');
        }

        $response = $this->apiClient->get(
            $this->configurationFor($document),
            '/v2/nfe/'.urlencode($document->focus_reference),
        );
        $this->throwForFailure($response);

        return FocusResponse::document($response);
    }

    public function cancel(Sale|FiscalDocument $sale, string $justification): array
    {
        $document = app(FiscalDocumentWorkflow::class)->resolve($sale);
        if (blank($document->focus_reference)) {
            throw new RuntimeException('A venda não possui uma referência de NF-e para cancelar.');
        }

        $justification = trim($justification);
        if (mb_strlen($justification) < 15 || mb_strlen($justification) > 255) {
            throw new RuntimeException('A justificativa deve ter entre 15 e 255 caracteres.');
        }

        $response = $this->apiClient->delete(
            $this->configurationFor($document),
            '/v2/nfe/'.urlencode($document->focus_reference),
            ['justificativa' => $justification],
        );
        $this->throwForFailure($response);

        return FocusResponse::document($response);
    }

    public function documentUrl(array $response, ?array $config = null): ?string
    {
        $url = $response['url_danfe'] ?? $response['url'] ?? $response['caminho_danfe'] ?? null;

        if (blank($url)) {
            return null;
        }

        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }

        return rtrim((string) ($config['base_url'] ?? config('services.focus_nfe.base_url')), '/').'/'.ltrim($url, '/');
    }

    protected function throwForFailure(Response $response): void
    {
        if ($response->successful()) {
            return;
        }

        $data = $response->json();
        throw new FocusNfeRequestException(
            'A Focus recusou a operação (HTTP '.$response->status().').',
            $response->status(),
            is_array($data) ? $data : null,
        );
    }
}
