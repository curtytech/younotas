<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Support\FiscalDocument;
use Illuminate\Http\Client\Response;
use RuntimeException;

class FocusDanfePreviewService
{
    public function __construct(
        protected FocusNfeConfigService $focusNfeConfigService,
        protected FocusApiClient $apiClient,
        protected FocusMunicipioService $municipioService,
    ) {}

    public function generate(Sale $sale): Response
    {
        $sale->loadMissing(['company', 'client', 'saleItems.product']);
        $focusConfig = $this->focusNfeConfigService->forCompany($sale->company);
        $payload = $this->buildPayload($sale, $focusConfig);

        $response = $this->apiClient->post($focusConfig, '/v2/nfe/danfe', $payload, [], 'application/pdf');

        if (! $response->successful()) {
            throw new RuntimeException('Falha ao gerar pré-visualização da DANFe (HTTP '.$response->status().').');
        }

        return $response;
    }

    public function buildPayload(Sale $sale, ?array $focusConfig = null): array
    {
        $sale->loadMissing(['company', 'client', 'saleItems.product']);
        $this->guardRequiredModelData($sale);
        $focusConfig ??= $this->focusNfeConfigService->forCompany($sale->company);
        $this->guardRequiredConfiguration($focusConfig);

        if (isset($focusConfig['company_id']) && (int) $focusConfig['company_id'] !== (int) $sale->company_id) {
            throw new RuntimeException('A configuração fiscal deve pertencer à empresa da venda.');
        }
        $this->municipioService->ensureClientIbgeCode($sale->client, $focusConfig);

        $emitente = $this->resolveEmitenteData($focusConfig);
        $destinatario = $this->resolveDestinatarioData($sale->client);
        $this->guardRequiredEmitenteData($emitente);
        $localDestino = $this->resolveLocalDestino($emitente['uf_emitente'], $destinatario['uf_destinatario']);
        $items = $sale->saleItems
            ->values()
            ->map(fn (SaleItem $item, int $index): array => $this->buildItemPayload($item, $index + 1, $focusConfig, $localDestino))
            ->all();

        return array_filter([
            'natureza_operacao' => (string) ($focusConfig['nfe']['natureza_operacao'] ?? 'VENDA DE MERCADORIA'),
            // A SEFAZ valida a data de emissão contra o horário do envio; usar a data da venda
            // causaria rejeição para vendas de outros dias.
            'data_emissao' => now(config('app.timezone'))->toIso8601String(),
            'data_entrada_saida' => now(config('app.timezone'))->toIso8601String(),
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

        if (! preg_match('/^\d{8}$/', $codigoNcm)) {
            throw new RuntimeException("O produto \"{$saleItem->product_name}\" precisa ter NCM de 8 dígitos para emitir NF-e.");
        }

        $cfop = $this->resolveCfop($product, $focusConfig, $localDestino);
        $taxes = $this->itemTaxPayload($saleItem, $focusConfig);

        if ((int) data_get($focusConfig, 'nfe.emitente.regime_tributario') === 4
            && $taxes['icms_situacao_tributaria'] === '102' && ! in_array($cfop, ['5102', '6102'], true)) {
            throw new RuntimeException('O perfil MEI com CSOSN 102 exige CFOP 5102 ou 6102.');
        }

        return array_filter([
            'numero_item' => $itemNumber,
            'codigo_produto' => $saleItem->product?->getKey()
                ? (string) $saleItem->product->getKey()
                : ($saleItem->product_code ?: (string) $saleItem->product_id),
            'descricao' => $saleItem->product_name,
            'cfop' => $cfop,
            'unidade_comercial' => $this->normalizeUnit((string) $saleItem->unit),
            'quantidade_comercial' => $this->formatDecimal((float) $saleItem->quantity, 3),
            'valor_unitario_comercial' => $this->formatDecimal((float) $saleItem->unit_price, 4),
            'valor_unitario_tributavel' => $this->formatDecimal((float) $saleItem->unit_price, 4),
            'unidade_tributavel' => $this->normalizeUnit((string) $saleItem->unit),
            'codigo_ncm' => $codigoNcm,
            'quantidade_tributavel' => $this->formatDecimal((float) $saleItem->quantity, 3),
            'valor_bruto' => $this->formatDecimal((float) $saleItem->quantity * (float) $saleItem->unit_price, 2),
            'valor_desconto' => $this->formatDecimal((float) ($saleItem->discount_amount ?? 0), 2),
            'valor_total_tributos' => $this->formatDecimal((float) ($saleItem->tax_amount ?? 0), 2),
            'cest' => $product?->cest_code,
            'codigo_barras_comercial' => $product?->gtin,
            'codigo_barras_tributavel' => $product?->gtin,
            ...$taxes,
        ], fn (mixed $value): bool => $value !== null && $value !== '');
    }

    protected function resolveEmitenteData(array $focusConfig): array
    {
        $cnpj = $this->onlyDigits((string) data_get($focusConfig, 'prestador.cnpj'));

        return [
            'cnpj_emitente' => $cnpj,
            'nome_emitente' => data_get($focusConfig, 'nfe.emitente.nome'),
            'nome_fantasia_emitente' => data_get($focusConfig, 'nfe.emitente.nome_fantasia'),
            'logradouro_emitente' => data_get($focusConfig, 'nfe.emitente.logradouro'),
            'numero_emitente' => data_get($focusConfig, 'nfe.emitente.numero'),
            'bairro_emitente' => $focusConfig['nfe']['emitente']['bairro'] ?? null,
            'municipio_emitente' => data_get($focusConfig, 'nfe.emitente.municipio'),
            'codigo_municipio_emitente' => $this->ibgeCodeOrNull($focusConfig['prestador']['codigo_municipio'] ?? null),
            'uf_emitente' => data_get($focusConfig, 'nfe.emitente.uf'),
            'cep_emitente' => $this->onlyDigits((string) data_get($focusConfig, 'nfe.emitente.cep')),
            'inscricao_estadual_emitente' => data_get($focusConfig, 'nfe.emitente.inscricao_estadual'),
            'regime_tributario_emitente' => $this->nullableInt($focusConfig['nfe']['emitente']['regime_tributario'] ?? null),
        ];
    }

    protected function resolveDestinatarioData(Client $client): array
    {
        $registration = $this->onlyDigits((string) $client->inscricao_estatual);
        $indicator = (string) ($client->indicador_ie ?: ($registration !== '' ? '1' : '9'));

        if (! in_array($indicator, ['1', '2', '9'], true) || ($indicator === '1' && $registration === '')) {
            throw new RuntimeException('Revise o indicador de inscrição estadual e informe a IE do destinatário contribuinte.');
        }

        if ($indicator !== '1' && $registration !== '') {
            throw new RuntimeException('O destinatário com IE informada deve possuir indicador de contribuinte.');
        }

        $document = $this->onlyDigits((string) $client->document);
        $documentKey = match ($client->document_type) {
            'cnpj' => 'cnpj_destinatario',
            'cpf' => 'cpf_destinatario',
            default => throw new RuntimeException('A pré-visualização da DANFe aceita apenas clientes com CPF ou CNPJ.'),
        };

        return array_filter([
            'nome_destinatario' => $client->name,
            $documentKey => $document,
            'indicador_inscricao_estadual_destinatario' => $indicator,
            'inscricao_estadual_destinatario' => $indicator === '1' ? $registration : null,
            'logradouro_destinatario' => $client->address,
            'numero_destinatario' => $client->address_number ?: 'S/N',
            'bairro_destinatario' => $client->neighborhood,
            'municipio_destinatario' => $client->city,
            'codigo_municipio_destinatario' => $this->ibgeCodeOrNull($client->ibge_code),
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
        if (! $sale->company) {
            throw new RuntimeException('A venda precisa estar vinculada a um emitente.');
        }

        if (! $sale->client) {
            throw new RuntimeException('A venda precisa estar vinculada a um cliente.');
        }

        if ((int) $sale->client->company_id !== (int) $sale->company_id
            || $sale->saleItems->contains(fn (SaleItem $item): bool => $item->product && (int) $item->product->company_id !== (int) $sale->company_id)) {
            throw new RuntimeException('O cliente e os produtos devem pertencer à empresa da venda.');
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

        if (! ($sale->client->document_type === 'cpf'
            ? FiscalDocument::cpfIsValid((string) $sale->client->document)
            : FiscalDocument::cnpjIsValid((string) $sale->client->document))) {
            throw new RuntimeException('O cliente precisa ter CPF ou CNPJ válido para emitir NF-e.');
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
            'cnpj_emitente' => 'o CNPJ do emitente',
            'nome_emitente' => 'a razão social do emitente',
            'logradouro_emitente' => 'o endereço do emitente',
            'numero_emitente' => 'o número do endereço do emitente',
            'bairro_emitente' => 'o bairro do emitente',
            'municipio_emitente' => 'o município do emitente',
            'uf_emitente' => 'a UF do emitente',
            'cep_emitente' => 'o CEP do emitente',
            'inscricao_estadual_emitente' => 'a inscrição estadual do emitente',
        ];

        foreach ($required as $field => $source) {
            if (blank($emitente[$field] ?? null)) {
                throw new RuntimeException("Preencha {$source} antes de gerar a DANFe.");
            }
        }

        if (! FiscalDocument::cnpjIsValid((string) $emitente['cnpj_emitente'])) {
            throw new RuntimeException('O CNPJ do emitente é inválido.');
        }
    }

    protected function resolveLocalDestino(?string $ufEmitente, ?string $ufDestinatario): int
    {
        if (blank($ufEmitente) || blank($ufDestinatario)) {
            return 1;
        }

        return strtoupper($ufEmitente) === strtoupper($ufDestinatario) ? 1 : 2;
    }

    protected function resolveCfop(?Product $product, array $config, int $localDestino): string
    {
        $prefix = $localDestino === 2 ? '6' : '5';
        $legacyCfop = $this->onlyDigits((string) $product?->cfop_code);
        $cfop = $localDestino === 2 ? $product?->cfop_interestadual : null;
        $cfop = $cfop ?: (str_starts_with($legacyCfop, $prefix) ? $legacyCfop : null);
        $cfop = $cfop ?: data_get($config, $localDestino === 2 ? 'nfe.cfop_interestadual_padrao' : 'nfe.cfop_padrao');
        $cfop = $this->onlyDigits((string) $cfop);

        if (! preg_match('/^'.$prefix.'\d{3}$/', $cfop)) {
            throw new RuntimeException($localDestino === 2
                ? 'Configure um CFOP interestadual explícito no produto ou na configuração fiscal. O sistema não converte o CFOP interno.'
                : 'Configure um CFOP interno explícito no produto ou na configuração fiscal.');
        }

        return $cfop;
    }

    protected function itemTaxPayload(SaleItem $item, array $config): array
    {
        $product = $item->product;
        $crt = (int) data_get($config, 'nfe.emitente.regime_tributario');
        $cst = (string) ($product?->cst_code ?: data_get($config, 'nfe.icms_situacao_tributaria', '102'));
        $simple = in_array($crt, [1, 4], true);

        if (! in_array($crt, [1, 2, 3, 4], true) || ! preg_match($simple ? '/^\d{3}$/' : '/^\d{2}$/', $cst)) {
            throw new RuntimeException('A situação tributária do produto é incompatível com o CRT do emitente.');
        }

        if (! in_array($cst, $simple ? ['102', '103', '300', '400'] : ['00', '40', '41', '50'], true)) {
            throw new RuntimeException('O CST/CSOSN informado exige um perfil tributário ainda não suportado. Revise o cadastro fiscal antes de emitir.');
        }

        if ($crt === 4 && $cst === '103') {
            throw new RuntimeException('O CSOSN 103 não é compatível com o CRT 4 do MEI.');
        }

        $origin = (string) ($product?->icms_origem ?? data_get($config, 'nfe.icms_origem', '0'));
        if (! preg_match('/^[0-8]$/', $origin)) {
            throw new RuntimeException('A origem da mercadoria deve estar entre 0 e 8.');
        }

        if ((float) $product?->ipi_aliquot !== 0.0) {
            throw new RuntimeException('Produtos com IPI exigem um perfil tributário ainda não suportado.');
        }

        $base = round((float) $item->quantity * (float) $item->unit_price - (float) $item->discount_amount, 2);
        if ($base < 0) {
            throw new RuntimeException('O desconto do item não pode superar seu valor bruto.');
        }

        foreach (['icms', 'pis', 'cofins'] as $tax) {
            $this->validatedTaxBase($item, $tax, 0, $base);
        }

        $taxes = ['icms_situacao_tributaria' => $cst, 'icms_origem' => $origin];
        if ($cst === '00') {
            $rate = $this->validatedRate($product?->icms_aliquot, 'ICMS');
            $icmsBase = $this->validatedTaxBase($item, 'icms', $rate, $base);
            $taxes += [
                'icms_modalidade_base_calculo' => '3',
                'icms_base_calculo' => $this->formatDecimal($icmsBase, 2),
                'icms_aliquota' => $this->formatDecimal($rate, 2),
                'icms_valor' => $this->formatDecimal(round($icmsBase * $rate / 100, 2), 2),
            ];
        } elseif ((float) $product?->icms_aliquot !== 0.0) {
            throw new RuntimeException('A alíquota ICMS do produto não é compatível com o CST/CSOSN sem destaque escolhido.');
        }

        foreach (['pis', 'cofins'] as $tax) {
            $cstField = $tax.'_cst';
            $rateField = $tax.'_aliquot';
            $taxCst = (string) ($product?->{$cstField} ?: data_get($config, 'nfe.'.$tax.'_situacao_tributaria', '49'));
            $rate = $this->validatedRate($product?->{$rateField}, strtoupper($tax));
            $taxes[$tax.'_situacao_tributaria'] = $taxCst;

            if (in_array($taxCst, ['01', '02', '49', '99'], true)) {
                $taxBase = $this->validatedTaxBase($item, $tax, $rate, $base);
                $taxes[$tax.'_base_calculo'] = $this->formatDecimal($taxBase, 2);
                $taxes[$tax.'_aliquota_porcentual'] = $this->formatDecimal($rate, 2);
                $taxes[$tax.'_valor'] = $this->formatDecimal(round($taxBase * $rate / 100, 2), 2);
            } elseif (! in_array($taxCst, ['04', '05', '06', '07', '08', '09'], true) || $rate !== 0.0) {
                throw new RuntimeException('O CST e a alíquota de '.strtoupper($tax).' exigem um perfil tributário diferente do suportado.');
            }
        }

        return $taxes;
    }

    protected function validatedRate(mixed $rate, string $tax): float
    {
        if ($rate !== null && (! is_numeric($rate) || (float) $rate < 0 || (float) $rate > 100)) {
            throw new RuntimeException('A alíquota de '.$tax.' deve estar entre 0 e 100.');
        }

        return (float) $rate;
    }

    protected function validatedTaxBase(SaleItem $item, string $tax, float $rate, float $netAmount): float
    {
        $base = $item->{$tax.'_base_calculo'};

        if ($base === null && $rate > 0) {
            throw new RuntimeException('Informe a base de cálculo de '.strtoupper($tax).' no item. O sistema não presume deduções tributárias.');
        }

        if ($base !== null && (! is_numeric($base) || (float) $base < 0 || (float) $base > $netAmount)) {
            throw new RuntimeException('A base de '.strtoupper($tax).' deve estar entre zero e o valor líquido do item. Bases especiais exigem um perfil ainda não suportado.');
        }

        return (float) $base;
    }

    protected function ibgeCodeOrNull(mixed $value): ?string
    {
        $code = $this->onlyDigits((string) $value);

        return strlen($code) === 7 ? $code : null;
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
