<?php

namespace App\Services;

use App\Exceptions\FocusNfseRequestException;
use App\Models\Client;
use App\Models\ServiceOrder;
use App\Support\FiscalDocument;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Str;
use RuntimeException;

class FocusNfseService
{
    /**
     * Magé uses the Modernização Pública provider. Its guide explicitly says
     * that the municipal tax-code field is not used and that CNAE is required.
     */
    protected const MAGE_IBGE_CODE = '3302502';

    public function __construct(
        protected FocusNfeConfigService $focusNfeConfigService,
        protected FocusApiClient $apiClient,
    ) {}

    public function configurationFor(ServiceOrder $service): array
    {
        $service->loadMissing('user.focusNfeSetting');

        return $this->focusNfeConfigService->forUser($service->user);
    }

    public function emit(array $focusConfig, string $reference, array $payload): array
    {
        $response = $this->apiClient->post($focusConfig, '/v2/nfse', $payload, ['ref' => $reference]);

        $this->throwForFailure($response);

        return $response->json();
    }

    public function consult(ServiceOrder $service): array
    {
        $service->loadMissing('user.focusNfeSetting');

        if (blank($service->focus_nfse_ref)) {
            throw new RuntimeException('O serviço não possui uma referência de NFS-e para consultar.');
        }

        $response = $this->apiClient->get(
            $this->configurationFor($service),
            '/v2/nfse/'.urlencode($service->focus_nfse_ref),
        );

        $this->throwForFailure($response);

        return $response->json();
    }

    public function cancel(ServiceOrder $service, string $justification): array
    {
        if (blank($service->focus_nfse_ref)) {
            throw new RuntimeException('O serviço não possui uma referência de NFS-e para cancelar.');
        }

        $justification = trim($justification);
        if (mb_strlen($justification) < 15 || mb_strlen($justification) > 255) {
            throw new RuntimeException('A justificativa do cancelamento deve ter entre 15 e 255 caracteres.');
        }

        $response = $this->apiClient->delete(
            $this->configurationFor($service),
            '/v2/nfse/'.urlencode($service->focus_nfse_ref),
            ['justificativa' => $justification],
        );

        $this->throwForFailure($response);

        return $response->json();
    }

    public function buildPayload(ServiceOrder $service, array $focusConfig): array
    {
        $service->loadMissing('client', 'items');
        $this->guardRequiredConfiguration($focusConfig);
        $this->guardRequiredModelData($service, $focusConfig);

        $client = $service->client;
        $prestadorMunicipio = $this->onlyDigits((string) data_get($focusConfig, 'prestador.codigo_municipio'));
        $serviceValue = $this->decimalToFloat((string) $service->total_amount);
        $items = $service->items;
        $firstItem = $items->first();
        $description = $items->map(fn ($item): string => sprintf(
            '%s x %s%s',
            $item->quantity,
            $item->service_name,
            filled($item->description) ? ': '.$item->description : '',
        ))->implode('; ');

        $itemListaServico = filled($firstItem?->lc116_code)
            ? ($this->isMage($focusConfig)
                ? $this->normalizeLc116Code($firstItem->lc116_code)
                : trim((string) $firstItem->lc116_code))
            : (string) ($firstItem?->municipal_service_code ?? '');

        return [
            'data_emissao' => now('America/Sao_Paulo')->toIso8601String(),
            'incentivador_cultural' => (bool) data_get($focusConfig, 'nfse.incentivador_cultural', false),
            'natureza_operacao' => (string) data_get($focusConfig, 'nfse.natureza_operacao', '1'),
            'optante_simples_nacional' => (bool) data_get($focusConfig, 'nfse.optante_simples_nacional', true),
            'prestador' => [
                'cnpj' => $this->onlyDigits((string) data_get($focusConfig, 'prestador.cnpj')),
                'inscricao_municipal' => (string) data_get($focusConfig, 'prestador.inscricao_municipal'),
                'codigo_municipio' => $prestadorMunicipio,
            ],
            'tomador' => array_filter([
                $this->tomadorDocumentKey($client) => $this->tomadorDocumentValue($client),
                'razao_social' => Str::limit($client->name, 115, ''),
                'email' => filled($client->email) ? Str::limit($client->email, 80, '') : null,
                'telefone' => filled($client->phone) ? Str::limit($this->onlyDigits($client->phone), 11, '') : null,
                'endereco' => array_filter([
                    'logradouro' => Str::limit($client->address, 125, ''),
                    'numero' => Str::limit($client->address_number ?: 'S/N', 10, ''),
                    'complemento' => filled($client->address_complement) ? Str::limit($client->address_complement, 60, '') : null,
                    'bairro' => filled($client->neighborhood) ? Str::limit($client->neighborhood, 60, '') : null,
                    'codigo_municipio' => $this->onlyDigits((string) $client->ibge_code),
                    'uf' => Str::upper($client->state),
                    'cep' => $this->onlyDigits((string) $client->zip_code),
                ]),
            ], static fn (mixed $value): bool => $value !== null && $value !== ''),
            'servico' => array_filter([
                'valor_servicos' => $serviceValue,
                'iss_retido' => false,
                'item_lista_servico' => $itemListaServico,
                'codigo_cnae' => filled($firstItem?->cnae_code) ? $this->onlyDigits($firstItem->cnae_code) : null,
                'codigo_tributario_municipio' => $this->municipalTaxCode($focusConfig, $firstItem?->municipal_service_code),
                'codigo_nbs' => $firstItem?->nbs_code,
                'discriminacao' => $description ?: 'Serviços da Ordem '.$service->number,
                'codigo_municipio' => $prestadorMunicipio,
                'aliquota' => $this->nullableDecimal($firstItem?->iss_aliquot),
                'valor_iss' => $this->decimalToFloat((string) $items->sum(fn ($item) => $this->taxValue($item->quantity * $item->unit_price, $item->iss_aliquot) ?: 0)),
                'valor_pis' => $this->decimalToFloat((string) $items->sum(fn ($item) => $this->taxValue($item->quantity * $item->unit_price, $item->pis_aliquot) ?: 0)),
                'valor_cofins' => $this->decimalToFloat((string) $items->sum(fn ($item) => $this->taxValue($item->quantity * $item->unit_price, $item->cofins_aliquot) ?: 0)),
                'valor_inss' => $this->decimalToFloat((string) $items->sum(fn ($item) => $this->taxValue($item->quantity * $item->unit_price, $item->inss_aliquot) ?: 0)),
                'valor_ir' => $this->decimalToFloat((string) $items->sum(fn ($item) => $this->taxValue($item->quantity * $item->unit_price, $item->ir_aliquot) ?: 0)),
                'valor_csll' => $this->decimalToFloat((string) $items->sum(fn ($item) => $this->taxValue($item->quantity * $item->unit_price, $item->csll_aliquot) ?: 0)),
            ], static fn (mixed $value): bool => $value !== null && $value !== ''),
        ] + (filled(data_get($focusConfig, 'nfse.regime_especial_tributacao'))
            ? ['regime_especial_tributacao' => (string) data_get($focusConfig, 'nfse.regime_especial_tributacao')]
            : []);
    }

    protected function throwForFailure($response): void
    {
        try {
            $response->throw();
        } catch (RequestException $exception) {
            $responseData = $response->json();

            throw new FocusNfseRequestException(
                $response->json('mensagem')
                    ?? $response->json('mensagens.0.mensagem')
                    ?? $response->json('erros.0.mensagem')
                    ?? $exception->getMessage(),
                $response->status(),
                is_array($responseData) ? $responseData : null,
                previous: $exception,
            );
        }
    }

    protected function guardRequiredConfiguration(array $focusConfig): void
    {
        foreach ([
            'api_key' => 'API Key da Focus',
            'prestador.cnpj' => 'CNPJ do prestador',
            'prestador.inscricao_municipal' => 'Inscrição municipal do prestador',
            'prestador.codigo_municipio' => 'Código IBGE do município do prestador',
        ] as $key => $label) {
            if (blank(data_get($focusConfig, $key))) {
                throw new RuntimeException("Configure {$label} na Configuração Fiscal antes de emitir a NFS-e.");
            }
        }

        if (! FiscalDocument::cnpjIsValid((string) data_get($focusConfig, 'prestador.cnpj'))) {
            throw new RuntimeException('O CNPJ do prestador é inválido.');
        }

        if (! in_array((string) data_get($focusConfig, 'nfse.natureza_operacao', '1'), ['1', '2', '3', '4', '5', '6'], true)) {
            throw new RuntimeException('A natureza da operação da NFS-e deve estar entre 1 e 6.');
        }

        $regime = data_get($focusConfig, 'nfse.regime_especial_tributacao');
        if (filled($regime) && ! in_array((string) $regime, ['1', '2', '3', '4', '5', '6'], true)) {
            throw new RuntimeException('O regime especial de tributação deve estar entre 1 e 6.');
        }
    }

    protected function guardRequiredModelData(ServiceOrder $service, array $focusConfig): void
    {
        $client = $service->client;
        if (! $client || $client->user_id !== $service->user_id) {
            throw new RuntimeException('O serviço deve estar vinculado a um cliente do mesmo emissor.');
        }

        if (! $client->is_active) {
            throw new RuntimeException('O cliente deve estar ativo para emitir NFS-e.');
        }

        if ((float) $service->total_amount <= 0 || $service->items->isEmpty()) {
            throw new RuntimeException('A O.S. deve possuir itens e total maior que zero.');
        }

        if ($service->items->contains(fn ($item): bool => blank($item->lc116_code) && blank($item->municipal_service_code))) {
            throw new RuntimeException('Cada item da O.S. deve possuir código LC 116 ou código municipal.');
        }

        if ($this->isMage($focusConfig) && $service->items->contains(fn ($item): bool => blank($item->cnae_code))) {
            throw new RuntimeException('Cada item da O.S. deve possuir código CNAE para emissão em Magé/RJ.');
        }

        if ($service->items->pluck('lc116_code')->filter()->unique()->count() > 1
            || $service->items->pluck('municipal_service_code')->filter()->unique()->count() > 1) {
            throw new RuntimeException('Todos os itens da O.S. devem usar o mesmo código de serviço para emissão da NFS-e.');
        }

        if (! $this->clientDocumentIsValid($client)) {
            throw new RuntimeException('O cliente precisa ter documento fiscal válido para emitir NFS-e.');
        }

        if (blank($client->address) || ! in_array(Str::upper((string) $client->state), $this->brazilianStates(), true) || ! preg_match('/^\d{7}$/', (string) $client->ibge_code)) {
            throw new RuntimeException('O cliente precisa ter endereço, UF válida e código IBGE de 7 dígitos preenchidos.');
        }

        if (filled($client->zip_code) && ! preg_match('/^\d{8}$/', $this->onlyDigits((string) $client->zip_code))) {
            throw new RuntimeException('O CEP do cliente deve conter 8 dígitos.');
        }

        foreach ($service->items as $item) {
            foreach (['iss_aliquot', 'pis_aliquot', 'cofins_aliquot', 'inss_aliquot', 'ir_aliquot', 'csll_aliquot'] as $field) {
                if ((float) $item->{$field} < 0 || (float) $item->{$field} > 100) {
                    throw new RuntimeException("A alíquota {$field} deve estar entre 0 e 100.");
                }
            }
        }
    }

    protected function tomadorDocumentKey(Client $client): string
    {
        return match ($client->document_type) {
            'cpf', 'cnpj', 'nif' => $client->document_type,
            default => throw new RuntimeException('Tipo de documento do cliente não suportado para emissão de NFS-e.'),
        };
    }

    protected function tomadorDocumentValue(Client $client): string
    {
        return $client->document_type === 'nif'
            ? Str::upper(trim($client->document))
            : $this->onlyDigits($client->document);
    }

    protected function clientDocumentIsValid(Client $client): bool
    {
        return match ($client->document_type) {
            'cpf' => FiscalDocument::cpfIsValid((string) $client->document),
            'cnpj' => FiscalDocument::cnpjIsValid((string) $client->document),
            'nif' => preg_match('/^[A-Z0-9]{5,20}$/', Str::upper(trim((string) $client->document))) === 1,
            default => false,
        };
    }

    protected function brazilianStates(): array
    {
        return [
            'AC', 'AL', 'AP', 'AM', 'BA', 'CE', 'DF', 'ES', 'GO', 'MA', 'MT', 'MS', 'MG',
            'PA', 'PB', 'PR', 'PE', 'PI', 'RJ', 'RN', 'RS', 'RO', 'RR', 'SC', 'SP', 'SE', 'TO',
        ];
    }

    protected function nullableDecimal(mixed $value): ?float
    {
        return (float) $value > 0 ? $this->decimalToFloat((string) $value) : null;
    }

    protected function taxValue(mixed $amount, mixed $rate): ?float
    {
        if ((float) $rate <= 0) {
            return null;
        }

        $amountCents = $this->decimalToInteger((string) $amount, 2);
        $rateBasisPoints = $this->decimalToInteger((string) $rate, 2);
        $taxCents = intdiv(($amountCents * $rateBasisPoints) + 5000, 10000);

        return $this->decimalToFloat($this->integerToDecimal($taxCents, 2));
    }

    protected function decimalToInteger(string $value, int $scale): int
    {
        $normalized = str_replace(',', '.', preg_replace('/[^0-9,.-]/', '', $value) ?? '0');
        $negative = str_starts_with($normalized, '-');
        $normalized = ltrim($normalized, '-');
        [$whole, $fraction] = array_pad(explode('.', $normalized, 2), 2, '');
        $fraction = substr(str_pad($fraction, $scale, '0'), 0, $scale);
        $integer = ((int) $whole * (10 ** $scale)) + (int) $fraction;

        return $negative ? -$integer : $integer;
    }

    protected function integerToDecimal(int $value, int $scale): string
    {
        $negative = $value < 0 ? '-' : '';
        $digits = str_pad((string) abs($value), $scale + 1, '0', STR_PAD_LEFT);

        return $negative.substr($digits, 0, -$scale).'.'.substr($digits, -$scale);
    }

    protected function decimalToFloat(string $value): float
    {
        return (float) $value;
    }

    protected function onlyDigits(string $value): string
    {
        return preg_replace('/\D/', '', $value) ?? '';
    }

    protected function normalizeLc116Code(mixed $value): ?string
    {
        $code = trim((string) $value);

        if ($code === '') {
            return null;
        }

        $digits = $this->onlyDigits($code);

        if (preg_match('/^\d{4}$/', $digits) === 1) {
            return substr($digits, 0, 2).'.'.substr($digits, 2, 2);
        }

        if (preg_match('/^\d\.\d{2}$/', $code) === 1) {
            return '0'.$code;
        }

        if (preg_match('/^\d{2}\.\d$/', $code) === 1) {
            return $code.'0';
        }

        return $code;
    }

    protected function municipalTaxCode(array $focusConfig, mixed $value): ?string
    {
        if ($this->isMage($focusConfig) || blank($value)) {
            return null;
        }

        return trim((string) $value);
    }

    protected function isMage(array $focusConfig): bool
    {
        return $this->onlyDigits((string) data_get($focusConfig, 'prestador.codigo_municipio')) === self::MAGE_IBGE_CODE;
    }
}
