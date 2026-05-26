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
    public function emit(Service $service, ?string $reference = null): array
    {
        $payload = $this->buildPayload($service);
        $reference ??= (string) Str::uuid();

        $response = Http::baseUrl(rtrim((string) config('services.focus_nfe.base_url'), '/'))
            ->withBasicAuth(
                (string) config('services.focus_nfe.api_key'),
                (string) config('services.focus_nfe.api_password', ''),
            )
            ->acceptJson()
            ->asJson()
            ->post('/v2/nfse?ref=' . $reference, $payload);

        dd($payload);
        // dd($response->json());

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

    public function buildPayload(Service $service): array
    {
        $this->guardRequiredConfiguration();
        $this->guardRequiredModelData($service);

        $client = $service->client;

        return [
            'data_emissao' => now()->toIso8601String(),
            'incentivador_cultural' => (bool) config('services.focus_nfe.nfse.incentivador_cultural', false),
            'natureza_operacao' => (string) config('services.focus_nfe.nfse.natureza_operacao', '1'),
            'optante_simples_nacional' => (bool) config('services.focus_nfe.nfse.optante_simples_nacional', true),
            'prestador' => [
                'cnpj' => $this->onlyDigits((string) config('services.focus_nfe.prestador.cnpj')),
                'inscricao_municipal' => (string) config('services.focus_nfe.prestador.inscricao_municipal'),
                'codigo_municipio' => $this->onlyDigits((string) config('services.focus_nfe.prestador.codigo_municipio')),
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
                    'codigo_municipio' => $this->resolveTomadorMunicipioCode($client),
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

    protected function guardRequiredConfiguration(): void
    {
        $required = [
            'services.focus_nfe.api_key' => 'FOCUS_NFE_API_KEY',
            'services.focus_nfe.prestador.cnpj' => 'FOCUS_NFE_PRESTADOR_CNPJ',
            'services.focus_nfe.prestador.inscricao_municipal' => 'FOCUS_NFE_PRESTADOR_INSCRICAO_MUNICIPAL',
            'services.focus_nfe.prestador.codigo_municipio' => 'FOCUS_NFE_PRESTADOR_CODIGO_MUNICIPIO',
        ];

        foreach ($required as $configKey => $envName) {
            if (blank(config($configKey))) {
                throw new RuntimeException("Configure {$envName} antes de emitir a NFS-e.");
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

    protected function resolveTomadorMunicipioCode(Client $client): string
    {
        $zipCode = $this->onlyDigits((string) $client->zip_code);

        if (blank($zipCode)) {
            return $this->onlyDigits((string) config('services.focus_nfe.prestador.codigo_municipio'));
        }

        return $this->onlyDigits((string) config('services.focus_nfe.prestador.codigo_municipio'));
    }

    protected function onlyDigits(string $value): string
    {
        return preg_replace('/\D/', '', $value) ?? '';
    }
}
