<?php

namespace App\Services;

use App\Models\Client;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

/**
 * Resolve o código IBGE de 7 dígitos de um município a partir de nome e UF,
 * usando a tabela de municípios da Focus.
 */
class FocusMunicipioService
{
    public function __construct(
        protected FocusApiClient $apiClient,
        protected FocusNfeConfigService $configService,
    ) {}

    public function ibgeCodeFor(?User $user, ?string $city, ?string $state): ?string
    {
        $city = trim((string) $city);
        $state = Str::upper(trim((string) $state));

        if ($city === '' || ! preg_match('/^[A-Z]{2}$/', $state)) {
            return null;
        }

        $config = $user ? $this->configService->forUser($user) : config('services.focus_nfe', []);

        if (blank($config['api_key'] ?? null)) {
            return null;
        }

        $cacheKey = 'focus:municipio:'.$state.':'.$this->normalize($city);

        if (($cached = Cache::get($cacheKey)) !== null) {
            return $cached;
        }

        try {
            $response = $this->apiClient->get($config, '/v2/municipios', ['sigla_uf' => $state, 'nome_municipio' => $city]);
        } catch (Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $code = collect($response->json() ?? [])
            ->first(fn (mixed $municipio): bool => is_array($municipio)
                && Str::upper((string) ($municipio['sigla_uf'] ?? '')) === $state
                && $this->normalize((string) ($municipio['nome_municipio'] ?? '')) === $this->normalize($city))['codigo_municipio'] ?? null;

        if (! preg_match('/^\d{7}$/', (string) $code)) {
            return null;
        }

        Cache::put($cacheKey, (string) $code, now()->addDays(30));

        return (string) $code;
    }

    /**
     * Preenche o código IBGE de um cliente cadastrado sem ele (ex.: cadastros antigos).
     */
    public function ensureClientIbgeCode(Client $client): ?string
    {
        if (preg_match('/^\d{7}$/', (string) $client->ibge_code)) {
            return $client->ibge_code;
        }

        $code = $this->ibgeCodeFor($client->user, $client->city, $client->state);

        if ($code !== null && $client->exists) {
            $client->forceFill(['ibge_code' => $code])->save();
        }

        return $code;
    }

    protected function normalize(string $value): string
    {
        return Str::of($value)->ascii()->lower()->squish()->replace(["'", '`'], '')->toString();
    }
}
