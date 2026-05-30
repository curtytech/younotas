<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Service;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class FocusNfseService
{
    public function __construct(
        protected FocusNfeConfigService $focusNfeConfigService,
    ) {}

    public function emit(Service $service, ?string $reference = null): array
    {
        $service->loadMissing(['client', 'user.focusNfeSetting']);

        $focusConfig = $this->focusNfeConfigService->forUser($service->user);
        $payload = $this->buildPayload($service, $focusConfig);
        $reference ??= (string) Str::uuid();

        $response = Http::baseUrl(rtrim((string) ($focusConfig['base_url'] ?? config('services.focus_nfe.base_url')), '/'))
            ->withBasicAuth(
                (string) ($focusConfig['api_key'] ?? config('services.focus_nfe.api_key')),
                (string) ($focusConfig['api_password'] ?? config('services.focus_nfe.api_password', '')),
            )
            ->acceptJson()
            ->asJson()
            ->post('/v2/nfse?ref=' . $reference, $payload);

        try {
            $response->throw();
        } catch (RequestException $exception) {
            throw new RuntimeException(
                $response->json('mensagem')
                    ?? $response->json('mensagens.0')
                    ?? $exception->getMessage(),
                previous: $exception,
            );
        }

        return [
            'reference' => $reference,
            'payload' => $payload,
            'response' => $response->json(),
        ];
    }

    public function buildPayload(Service $service, array $focusConfig): array
    {
        $this->guardRequiredConfiguration($focusConfig);
        $this->guardRequiredModelData($service);

        $client = $service->client;

        return [
            'data_emissao' => now()->toIso8601String(),
            'incentivador_cultural' => (bool) ($focusConfig['nfse']['incentivador_cultural'] ?? false),
            'natureza_operacao' => (string) ($focusConfig['nfse']['natureza_operacao'] ?? '1'),
            'optante_simples_nacional' => (bool) ($focusConfig['nfse']['optante_simples_nacional'] ?? true),
            'prestador' => [
                'cnpj' => $this->onlyDigits((string) ($focusConfig['prestador']['cnpj'] ?? '')),
                'inscricao_municipal' => (string) ($focusConfig['prestador']['inscricao_municipal'] ?? ''),
                'codigo_municipio' => $this->onlyDigits((string) ($focusConfig['prestador']['codigo_municipio'] ?? '')),
            ],
            'tomador' => array_filter([
                $this->getTomadorDocumentKey($client) => $this->getTomadorDocumentValue($client),
                'razao_social' => $client->name,
                'email' => $client->email,
                'telefone' => $this->onlyDigits((string) $client->phone),
                'endereco' => array_filter([
                    'logradouro' => $client->address,
                    'numero' => $client->address_number ?: 'S/N',
                    'complemento' => $client->address_complement,
                    'bairro' => $client->neighborhood,
                    'codigo_municipio' => $this->resolveTomadorMunicipioCode($client, $focusConfig),
                    'uf' => $client->state,
                    'cep' => $this->onlyDigits((string) $client->zip_code),
                ]),
            ]),
            'servico' => array_filter([
                'discriminacao' => $service->description ?: $service->name,
                'iss_retido' => false,
                'item_lista_servico' => $service->lc116_code ?: $service->municipal_service_code,
                'codigo_cnae' => $this->onlyDigits((string) $service->cnae_code),
                'valor_servicos' => (float) $service->unit_price,
            ], fn(mixed $value): bool => $value !== null && $value !== ''),
        ];
    }

    protected function guardRequiredConfiguration(array $focusConfig): void
    {
        $required = [
            'api_key' => 'API Key da Focus',
            'prestador.cnpj' => 'CNPJ do prestador',
            'prestador.inscricao_municipal' => 'Inscrição municipal do prestador',
            'prestador.codigo_municipio' => 'Código do município do prestador',
        ];

        foreach ($required as $configKey => $label) {
            if (blank(data_get($focusConfig, $configKey))) {
                throw new RuntimeException("Configure {$label} na Configuração Fiscal antes de emitir a NFS-e.");
            }
        }
    }

    protected function guardRequiredModelData(Service $service): void
    {
        $service->loadMissing('client');

        if (! $service->client) {
            throw new RuntimeException('O servico precisa estar vinculado a um cliente para emitir NFS-e.');
        }

        if (blank($service->unit_price) || (float) $service->unit_price <= 0) {
            throw new RuntimeException('Informe um valor unitario maior que zero no servico.');
        }

        if (blank($service->lc116_code) && blank($service->municipal_service_code)) {
            throw new RuntimeException('Informe o codigo LC 116 ou o codigo municipal do servico.');
        }

        if (blank($service->client->document_type) || blank($service->client->document)) {
            throw new RuntimeException('O cliente precisa ter tipo e numero de documento para emitir NFS-e.');
        }

        if (blank($service->client->address) || blank($service->client->city) || blank($service->client->state)) {
            throw new RuntimeException('O cliente precisa ter endereco, cidade e UF preenchidos para emitir NFS-e.');
        }
    }

    protected function getTomadorDocumentKey(Client $client): string
    {
        return match ($client->document_type) {
            'cpf' => 'cpf',
            'cnpj' => 'cnpj',
            'nif' => 'nif',
            default => throw new RuntimeException('Tipo de documento do cliente nao suportado para emissao de NFS-e.'),
        };
    }

    protected function getTomadorDocumentValue(Client $client): string
    {
        return match ($client->document_type) {
            'cpf', 'cnpj' => $this->onlyDigits((string) $client->document),
            'nif' => Str::upper(trim((string) $client->document)),
            default => throw new RuntimeException('Tipo de documento do cliente nao suportado para emissao de NFS-e.'),
        };
    }

    protected function resolveTomadorMunicipioCode(Client $client, array $focusConfig): string
    {
        $zipCode = $this->onlyDigits((string) $client->zip_code);

        if (blank($zipCode)) {
            return $this->onlyDigits((string) ($focusConfig['prestador']['codigo_municipio'] ?? ''));
        }

        return $this->onlyDigits((string) ($focusConfig['prestador']['codigo_municipio'] ?? ''));
    }

    protected function onlyDigits(string $value): string
    {
        return preg_replace('/\D/', '', $value) ?? '';
    }
}
