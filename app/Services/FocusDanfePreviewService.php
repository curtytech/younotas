<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use Illuminate\Http\Client\Response;
use RuntimeException;

class FocusDanfePreviewService
{
    public function __construct(
        protected FocusNfeConfigService $focusNfeConfigService,
        protected FocusApiClient $apiClient,
    ) {}

    public function generate(Sale $sale): Response
    {
        $sale->loadMissing(['user.focusNfeSetting', 'client', 'saleItems.product']);

        $focusConfig = $this->focusNfeConfigService->forUser($sale->user);
        $payload = $this->buildPayload($sale);

        $response = $this->apiClient->post($focusConfig, '/v2/nfe/danfe', $payload, [], 'application/pdf');

        if ($response->failed()) {
            throw new RuntimeException(
                $response->json('mensagem')
                    ?? $response->json('message')
                    ?? $response->json('erros.0.mensagem')
                    ?? 'Falha ao gerar pré-visualização da DANFe ('.$response->status().').'
            );
        }

        return $response;
    }

    public function buildPayload(Sale $sale): array
    {
        $sale->loadMissing(['user.focusNfeSetting', 'client', 'saleItems.product']);
        $focusConfig = $this->focusNfeConfigService->forUser($sale->user);

        $this->guardRequiredConfiguration($focusConfig);
        $this->guardRequiredModelData($sale);

        $emitente = $this->resolveEmitenteData($sale->user, $focusConfig);
        $destinatario = $this->resolveDestinatarioData($sale->client);
        $this->guardRequiredEmitenteData($emitente);
        $localDestino = $this->resolveLocalDestino($emitente['uf_emitente'], $destinatario['uf_destinatario']);
        $items = $sale->saleItems
            ->values()
            ->map(fn (SaleItem $item, int $index): array => $this->buildItemPayload($item, $index + 1, $focusConfig, $localDestino))
            ->all();

        return array_filter([
            'natureza_operacao' => (string) ($focusConfig['nfe']['natureza_operacao'] ?? 'VENDA DE MERCADORIA'),
            'data_emissao' => $sale->sale_date?->copy()->setTime(now()->hour, now()->minute, now()->second)->toIso8601String()
                ?? now()->toIso8601String(),
            'data_entrada_saida' => $sale->sale_date?->copy()->endOfDay()->toIso8601String()
                ?? now()->toIso8601String(),
            'tipo_documento' => (int) ($focusConfig['nfe']['tipo_documento'] ?? 1),
            'local_destino' => $localDestino,
            'finalidade_emissao' => (int) ($focusConfig['nfe']['finalidade_emissao'] ?? 1),
            'consumidor_final' => (int) ($focusConfig['nfe']['consumidor_final'] ?? 1),
            'presenca_comprador' => (int) ($focusConfig['nfe']['presenca_comprador'] ?? 1),
            'modalidade_frete' => (int) ($focusConfig['nfe']['modalidade_frete'] ?? 9),
            ...$emitente,
            ...$destinatario,
            'valor_frete' => (float) ($focusConfig['nfe']['valor_frete'] ?? 0),
            'valor_seguro' => (float) ($focusConfig['nfe']['valor_seguro'] ?? 0),
            'valor_desconto' => (float) ($sale->discount_amount ?? 0),
            'valor_outras_despesas' => (float) ($focusConfig['nfe']['valor_outras_despesas'] ?? 0),
            'valor_total' => (float) $sale->total_amount,
            'valor_produtos' => (float) $sale->subtotal_amount,
            'items' => $items,
            'formas_pagamento' => [[
                'forma_pagamento' => (string) ($focusConfig['nfe']['forma_pagamento'] ?? '01'),
                'valor_pagamento' => number_format((float) $sale->total_amount, 2, '.', ''),
            ]],
        ], fn (mixed $value): bool => $value !== null && $value !== '');
    }

    protected function buildItemPayload(SaleItem $saleItem, int $itemNumber, array $focusConfig, int $localDestino): array
    {
        /** @var Product|null $product */
        $product = $saleItem->product;

        $codigoNcm = $this->onlyDigits((string) ($product?->ncm_code ?: ($focusConfig['nfe']['codigo_ncm_padrao'] ?? '')));

        if (blank($codigoNcm)) {
            throw new RuntimeException("O produto \"{$saleItem->product_name}\" precisa ter NCM para gerar a pré-visualização da DANFe.");
        }

        return array_filter([
            'numero_item' => $itemNumber,
            'codigo_produto' => $saleItem->product?->getKey()
                ? (string) $saleItem->product->getKey()
                : ($saleItem->product_code ?: (string) $saleItem->product_id),
            'descricao' => $saleItem->product_name,
            'cfop' => $this->resolveCfop(
                (string) ($product?->cfop_code ?? ($focusConfig['nfe']['cfop_padrao'] ?? '5102')),
                $localDestino,
            ),
            'unidade_comercial' => $this->normalizeUnit((string) $saleItem->unit),
            'quantidade_comercial' => $this->formatDecimal((float) $saleItem->quantity, 3),
            'valor_unitario_comercial' => $this->formatDecimal((float) $saleItem->unit_price, 4),
            'valor_unitario_tributavel' => $this->formatDecimal((float) $saleItem->unit_price, 4),
            'unidade_tributavel' => $this->normalizeUnit((string) $saleItem->unit),
            'codigo_ncm' => $codigoNcm,
            'quantidade_tributavel' => $this->formatDecimal((float) $saleItem->quantity, 3),
            'valor_bruto' => $this->formatDecimal((float) $saleItem->total_amount, 2),
            'valor_desconto' => $this->formatDecimal((float) ($saleItem->discount_amount ?? 0), 2),
            'valor_total_tributos' => $this->formatDecimal((float) ($saleItem->tax_amount ?? 0), 2),
            'icms_situacao_tributaria' => (string) ($focusConfig['nfe']['icms_situacao_tributaria'] ?? '102'),
            'icms_origem' => (string) ($focusConfig['nfe']['icms_origem'] ?? '0'),
            'pis_situacao_tributaria' => (string) ($focusConfig['nfe']['pis_situacao_tributaria'] ?? '07'),
            'cofins_situacao_tributaria' => (string) ($focusConfig['nfe']['cofins_situacao_tributaria'] ?? '07'),
        ], fn (mixed $value): bool => $value !== null && $value !== '');
    }

    protected function resolveEmitenteData(User $user, array $focusConfig): array
    {
        $cnpj = $this->onlyDigits((string) ($focusConfig['prestador']['cnpj'] ?? $user->cnpj));

        return [
            'cnpj_emitente' => $cnpj,
            'nome_emitente' => $focusConfig['nfe']['emitente']['nome'] ?? $user->razao_social ?? $user->name,
            'nome_fantasia_emitente' => $focusConfig['nfe']['emitente']['nome_fantasia'] ?? $user->name,
            'logradouro_emitente' => $focusConfig['nfe']['emitente']['logradouro'] ?? $user->address,
            'numero_emitente' => $focusConfig['nfe']['emitente']['numero'] ?? $user->address_number,
            'bairro_emitente' => $focusConfig['nfe']['emitente']['bairro'] ?? null,
            'municipio_emitente' => $focusConfig['nfe']['emitente']['municipio'] ?? $user->city,
            'uf_emitente' => $focusConfig['nfe']['emitente']['uf'] ?? $user->state,
            'cep_emitente' => $this->onlyDigits((string) ($focusConfig['nfe']['emitente']['cep'] ?? $user->zip_code)),
            'inscricao_estadual_emitente' => $focusConfig['nfe']['emitente']['inscricao_estadual'] ?? $user->inscricao_estatual,
            'regime_tributario_emitente' => $this->nullableInt($focusConfig['nfe']['emitente']['regime_tributario'] ?? null),
        ];
    }

    protected function resolveDestinatarioData(Client $client): array
    {
        $document = $this->onlyDigits((string) $client->document);
        $documentKey = match ($client->document_type) {
            'cnpj' => 'cnpj_destinatario',
            'cpf' => 'cpf_destinatario',
            default => throw new RuntimeException('A pré-visualização da DANFe aceita apenas clientes com CPF ou CNPJ.'),
        };

        return array_filter([
            'nome_destinatario' => $client->name,
            $documentKey => $document,
            'indicador_inscricao_estadual_destinatario' => '9',
            'inscricao_estadual_destinatario' => null,
            'logradouro_destinatario' => $client->address,
            'numero_destinatario' => $client->address_number ?: 'S/N',
            'bairro_destinatario' => $client->neighborhood,
            'municipio_destinatario' => $client->city,
            'uf_destinatario' => $client->state,
            'cep_destinatario' => $this->onlyDigits((string) $client->zip_code),
            'pais_destinatario' => $client->country ?: 'Brasil',
            'telefone_destinatario' => $this->onlyDigits((string) $client->phone),
        ], fn (mixed $value): bool => $value !== null && $value !== '');
    }

    protected function guardRequiredConfiguration(array $focusConfig): void
    {
        $required = [
            'api_key' => 'API Key da Focus',
        ];

        foreach ($required as $configKey => $label) {
            if (blank(data_get($focusConfig, $configKey))) {
                throw new RuntimeException("Configure {$label} na Configuração Fiscal antes de gerar a DANFe.");
            }
        }
    }

    protected function guardRequiredModelData(Sale $sale): void
    {
        if (! $sale->user) {
            throw new RuntimeException('A venda precisa estar vinculada a um emitente.');
        }

        if (! $sale->client) {
            throw new RuntimeException('A venda precisa estar vinculada a um cliente.');
        }

        if ($sale->saleItems->isEmpty()) {
            throw new RuntimeException('Adicione ao menos um produto na venda para gerar a pré-visualização da DANFe.');
        }

        if (blank($sale->client->document_type) || blank($sale->client->document)) {
            throw new RuntimeException('O cliente precisa ter CPF ou CNPJ preenchido para gerar a DANFe.');
        }

        if (! in_array($sale->client->document_type, ['cpf', 'cnpj'], true)) {
            throw new RuntimeException('A pré-visualização da DANFe aceita apenas clientes com CPF ou CNPJ.');
        }

        if (blank($sale->client->address) || blank($sale->client->city) || blank($sale->client->state)) {
            throw new RuntimeException('O cliente precisa ter endereco, cidade e UF preenchidos para gerar a DANFe.');
        }

        if (blank($sale->subtotal_amount) || (float) $sale->subtotal_amount <= 0) {
            throw new RuntimeException('A venda precisa ter subtotal maior que zero para gerar a DANFe.');
        }
    }

    protected function guardRequiredEmitenteData(array $emitente): void
    {
        $required = [
            'cnpj_emitente' => 'FOCUS_NFE_PRESTADOR_CNPJ ou CNPJ da empresa',
            'nome_emitente' => 'FOCUS_NFE_EMITENTE_NOME ou razao_social da empresa',
            'logradouro_emitente' => 'FOCUS_NFE_EMITENTE_LOGRADOURO ou endereco da empresa',
            'numero_emitente' => 'FOCUS_NFE_EMITENTE_NUMERO ou numero do endereco da empresa',
            'bairro_emitente' => 'FOCUS_NFE_EMITENTE_BAIRRO',
            'municipio_emitente' => 'FOCUS_NFE_EMITENTE_MUNICIPIO ou cidade da empresa',
            'uf_emitente' => 'FOCUS_NFE_EMITENTE_UF ou UF da empresa',
            'cep_emitente' => 'FOCUS_NFE_EMITENTE_CEP ou CEP da empresa',
            'inscricao_estadual_emitente' => 'FOCUS_NFE_EMITENTE_INSCRICAO_ESTADUAL ou inscricao_estatual da empresa',
        ];

        foreach ($required as $field => $source) {
            if (blank($emitente[$field] ?? null)) {
                throw new RuntimeException("Preencha {$source} antes de gerar a DANFe.");
            }
        }
    }

    protected function resolveLocalDestino(?string $ufEmitente, ?string $ufDestinatario): int
    {
        if (blank($ufEmitente) || blank($ufDestinatario)) {
            return 1;
        }

        return strtoupper($ufEmitente) === strtoupper($ufDestinatario) ? 1 : 2;
    }

    protected function resolveCfop(string $cfop, int $localDestino): string
    {
        $cfop = $this->onlyDigits($cfop);

        if (strlen($cfop) !== 4) {
            return $cfop;
        }

        if ($localDestino === 2 && $cfop === '5102') {
            return '6102';
        }

        if ($localDestino === 1 && $cfop === '6102') {
            return '5102';
        }

        return $cfop;
    }

    protected function onlyDigits(string $value): string
    {
        return preg_replace('/\D/', '', $value) ?? '';
    }

    protected function formatDecimal(float $value, int $precision): string
    {
        return number_format($value, $precision, '.', '');
    }

    protected function normalizeUnit(string $value): string
    {
        $value = trim($value);

        return strtoupper($value !== '' ? $value : 'UN');
    }

    protected function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }
}
