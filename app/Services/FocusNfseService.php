<?php

namespace App\Services;

use App\Exceptions\FocusNfseRequestException;
use App\Models\Client;
use App\Models\Service;
use App\Support\FiscalDocument;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class FocusNfseService
{
    public function __construct(
        protected FocusNfeConfigService $focusNfeConfigService,
    ) {}

    public function configurationFor(Service $service): array
    {
        $service->loadMissing('user.focusNfeSetting');

        return $this->focusNfeConfigService->forUser($service->user);
    }

    public function emit(array $focusConfig, string $reference, array $payload): array
    {
        $response = $this->client($focusConfig)
            ->asJson()
            ->post('/v2/nfse?ref='.urlencode($reference), $payload);

        $this->throwForFailure($response);

        return $response->json();
    }

    public function consult(Service $service): array
    {
        $service->loadMissing('user.focusNfeSetting');

        if (blank($service->focus_nfse_ref)) {
            throw new RuntimeException('O serviço não possui uma referência de NFS-e para consultar.');
        }

        $response = $this->client($this->configurationFor($service))
            ->get('/v2/nfse/'.urlencode($service->focus_nfse_ref));

        $this->throwForFailure($response);

        return $response->json();
    }

    public function cancel(Service $service, string $justification): array
    {
        if (blank($service->focus_nfse_ref)) {
            throw new RuntimeException('O serviço não possui uma referência de NFS-e para cancelar.');
        }

        $justification = trim($justification);
        if (mb_strlen($justification) < 15 || mb_strlen($justification) > 255) {
            throw new RuntimeException('A justificativa do cancelamento deve ter entre 15 e 255 caracteres.');
        }

        $response = $this->client($this->configurationFor($service))
            ->asJson()
            ->delete('/v2/nfse/'.urlencode($service->focus_nfse_ref), [
                'justificativa' => $justification,
            ]);

        $this->throwForFailure($response);

        return $response->json();
    }

    public function buildPayload(Service $service, array $focusConfig): array
    {
        $service->loadMissing('client');
        $this->guardRequiredConfiguration($focusConfig);
        $this->guardRequiredModelData($service);

        $client = $service->client;
        $prestadorMunicipio = $this->onlyDigits((string) data_get($focusConfig, 'prestador.codigo_municipio'));
        $serviceValue = $this->decimalToFloat((string) $service->unit_price);

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
                'item_lista_servico' => (string) ($service->lc116_code ?: $service->municipal_service_code),
                'codigo_cnae' => filled($service->cnae_code) ? $this->onlyDigits($service->cnae_code) : null,
                'codigo_tributacao_municipio' => $service->municipal_service_code,
                'codigo_nbs' => $service->nbs_code,
                'discriminacao' => $service->description ?: $service->name,
                'codigo_municipio' => $prestadorMunicipio,
                'aliquota' => $this->nullableDecimal($service->iss_aliquot),
                'valor_iss' => $this->taxValue($service->unit_price, $service->iss_aliquot),
                'valor_pis' => $this->taxValue($service->unit_price, $service->pis_aliquot),
                'valor_cofins' => $this->taxValue($service->unit_price, $service->cofins_aliquot),
                'valor_inss' => $this->taxValue($service->unit_price, $service->inss_aliquot),
                'valor_ir' => $this->taxValue($service->unit_price, $service->ir_aliquot),
                'valor_csll' => $this->taxValue($service->unit_price, $service->csll_aliquot),
            ], static fn (mixed $value): bool => $value !== null && $value !== ''),
        ] + (filled(data_get($focusConfig, 'nfse.regime_especial_tributacao'))
            ? ['regime_especial_tributacao' => (string) data_get($focusConfig, 'nfse.regime_especial_tributacao')]
            : []);
    }

    protected function client(array $focusConfig)
    {
        return Http::baseUrl(rtrim((string) data_get($focusConfig, 'base_url', config('services.focus_nfe.base_url')), '/'))
            ->withBasicAuth((string) data_get($focusConfig, 'api_key'), '')
            ->acceptJson()
            ->connectTimeout(5)
            ->timeout(20);
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

    protected function guardRequiredModelData(Service $service): void
    {
        $client = $service->client;
        if (! $client || $client->user_id !== $service->user_id) {
            throw new RuntimeException('O serviço deve estar vinculado a um cliente do mesmo emissor.');
        }

        if (! $service->is_active || ! $client->is_active) {
            throw new RuntimeException('O serviço e o cliente devem estar ativos para emitir NFS-e.');
        }

        if ((float) $service->unit_price <= 0 || blank($service->lc116_code) && blank($service->municipal_service_code)) {
            throw new RuntimeException('Informe valor unitário maior que zero e o código LC 116 ou municipal do serviço.');
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

        foreach (['iss_aliquot', 'pis_aliquot', 'cofins_aliquot', 'inss_aliquot', 'ir_aliquot', 'csll_aliquot'] as $field) {
            if ((float) $service->{$field} < 0 || (float) $service->{$field} > 100) {
                throw new RuntimeException("A alíquota {$field} deve estar entre 0 e 100.");
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
}
