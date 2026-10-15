<?php

namespace App\Services;

use App\Models\Company;
use App\Models\FiscalConnection;
use App\Models\FiscalDocument;
use App\Models\FocusNfeSetting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class FocusNfeConfigService
{
    public const ENVIRONMENT_URLS = [
        'homologacao' => 'https://homologacao.focusnfe.com.br',
        'producao' => 'https://api.focusnfe.com.br',
    ];

    public function forUser(User $user, ?string $environment = null): array
    {
        return $this->forCompany($this->ensureCompanyForUser($user), $environment);
    }

    public function forCompany(Company $company, ?string $environment = null): array
    {
        $company = $company->fresh() ?? throw new RuntimeException('A empresa emitente não existe.');
        $environment ??= $company->default_environment;
        $this->guardEnvironment($environment);
        $connection = $company->connections()->where('provider', 'focus')->where('environment', $environment)->first();

        if (! $connection) {
            throw new RuntimeException('Configure a conexão Focus da empresa no ambiente selecionado antes de emitir.');
        }

        return $this->forConnection($connection);
    }

    public function forConnection(FiscalConnection $connection): array
    {
        $connection = $connection->fresh() ?? throw new RuntimeException('A conexão fiscal não existe.');
        $company = $connection->company()->first() ?? throw new RuntimeException('A empresa da conexão fiscal não existe.');
        $this->guardConnection($connection, $company);
        $settings = array_replace_recursive($company->fiscal_settings ?? [], $connection->settings ?? []);

        $settings['documents'] = array_replace(['nfe' => true, 'nfse' => true], ($company->fiscal_settings ?? [])['documents'] ?? [], ($connection->settings ?? [])['documents'] ?? []);
        $settings['connection_settings'] = $connection->settings ?? [];

        return array_replace($settings, $this->connectionCredentials($connection));
    }

    public function forDocument(FiscalDocument $document): array
    {
        $document = $document->fresh() ?? throw new RuntimeException('O documento fiscal não existe.');

        if (blank($document->company_id) || blank($document->fiscal_connection_id) || blank($document->environment)) {
            throw new RuntimeException('Documento legado sem ambiente/conexão comprovados. Reconcilie sua origem antes de consultar ou cancelar.');
        }

        $snapshot = $document->configuration_snapshot;

        if (! is_array($snapshot) || $snapshot === []) {
            throw new RuntimeException('O documento não possui a configuração fiscal preservada. Reconcilie sua origem.');
        }

        $connection = FiscalConnection::query()->find($document->fiscal_connection_id)
            ?? throw new RuntimeException('A conexão original do documento não existe.');
        $company = $connection->company()->first() ?? throw new RuntimeException('A empresa emitente não existe.');

        if ((int) $connection->company_id !== (int) $document->company_id || $connection->environment !== $document->environment) {
            throw new RuntimeException('A conexão fiscal não corresponde à empresa e ao ambiente originais do documento.');
        }

        $this->guardConnection($connection, $company, allowInactiveCompany: true);

        return array_replace(self::sanitizeSnapshot($snapshot), $this->connectionCredentials($connection));
    }

    public function ensureCompanyForUser(User $user): Company
    {
        return DB::transaction(function () use ($user): Company {
            $freshUser = User::query()->lockForUpdate()->findOrFail($user->id);

            if ($freshUser->primary_company_id) {
                $company = $freshUser->companies()->whereKey($freshUser->primary_company_id)->first();

                if (! $company) {
                    throw new RuntimeException('O usuário não possui vínculo com sua empresa selecionada.');
                }

                return $company;
            }

            $company = $freshUser->companies()->orderByPivot('id')->first();

            if (! $company) {
                $company = Company::query()->create([
                    'name' => $freshUser->name,
                    'cnpj' => $freshUser->cnpj,
                    'default_environment' => 'homologacao',
                    'is_active' => true,
                    'fiscal_settings' => [],
                ]);
                $company->members()->attach($freshUser->id, ['role' => 'owner']);
            }

            $freshUser->forceFill(['primary_company_id' => $company->id])->save();
            $user->setAttribute('primary_company_id', $company->id);
            $user->unsetRelation('primaryCompany');

            foreach (array_keys(self::ENVIRONMENT_URLS) as $environment) {
                $company->connections()->firstOrCreate(
                    ['provider' => 'focus', 'environment' => $environment],
                    ['enabled' => false, 'settings' => []],
                );
            }

            return $company;
        });
    }

    /** Transitional writer for existing forms and fixtures. Credentials live only in connections. */
    public function synchronizeLegacySetting(FocusNfeSetting $setting): void
    {
        $user = User::query()->find($setting->user_id);
        $company = $setting->company_id
            ? Company::query()->findOrFail($setting->company_id)
            : ($user ? $this->ensureCompanyForUser($user) : null);

        if (! $company) {
            throw new RuntimeException('Selecione a empresa emitente da configuração fiscal.');
        }

        $incoming = self::normalizeSettings($setting->settings ?? []);
        $environment = $incoming['environment'] ?? $company->default_environment;
        $this->guardEnvironment($environment);
        $common = self::sanitizeSnapshot($incoming);
        unset($common['environment'], $common['base_url'], $common['company_id'], $common['fiscal_connection_id'], $common['remove_api_keys'], $common['environments']);
        $legacyOwnerSettings = $setting->legacyOwnerConfiguration && $user ? $this->legacyFiscalSettingsFor($user) : [];
        $common = array_replace_recursive($legacyOwnerSettings, $company->fiscal_settings ?? [], $common);
        $company->forceFill([
            'fiscal_settings' => $common,
            'default_environment' => $environment,
            'cnpj' => data_get($common, 'prestador.cnpj') ?: $company->cnpj,
            'name' => data_get($common, 'nfe.emitente.nome') ?: $company->name,
        ])->save();

        foreach (array_keys(self::ENVIRONMENT_URLS) as $connectionEnvironment) {
            $connection = $company->connections()->firstOrCreate(
                ['provider' => 'focus', 'environment' => $connectionEnvironment],
                ['enabled' => false, 'settings' => []],
            );
            $token = $incoming['api_keys'][$connectionEnvironment] ?? null;
            $environmentSettings = $incoming['environments'][$connectionEnvironment] ?? [];

            if ($environmentSettings !== []) {
                $connectionSettings = $connection->settings ?? [];

                foreach (['nfe', 'nfse'] as $type) {
                    if (array_key_exists($type.'_enabled', $environmentSettings)) {
                        $connectionSettings['documents'][$type] = (bool) $environmentSettings[$type.'_enabled'];
                    }
                }

                $connection->settings = $connectionSettings;
            }

            if (data_get($incoming, 'remove_api_keys.'.$connectionEnvironment, false)) {
                $connection->forceFill(['api_key' => null, 'enabled' => false])->save();
            } elseif (filled($token)) {
                $connection->forceFill([
                    'enabled' => $connection->enabled || blank($connection->api_key),
                    'api_key' => (string) $token,
                ])->save();
            } elseif ($connection->isDirty()) {
                $connection->save();
            }
        }

        $setting->company_id = $company->id;
        $setting->settings = $common + ['environment' => $environment];
    }

    /** Never retain credentials or credential-like fields in document/audit snapshots. */
    public static function sanitizeSnapshot(array $settings): array
    {
        $clean = [];

        foreach ($settings as $key => $value) {
            if (preg_match('/api[_-]?keys?|password|token|secret|authorization|clear_api_keys|remove_api_keys/i', (string) $key)) {
                continue;
            }

            $clean[$key] = is_array($value) ? self::sanitizeSnapshot($value) : $value;
        }

        return $clean;
    }

    public function baseUrlFor(?User $user): string
    {
        return $user ? $this->forUser($user)['base_url'] : self::ENVIRONMENT_URLS['homologacao'];
    }

    public static function environmentFromUrl(?string $baseUrl): string
    {
        return parse_url((string) $baseUrl, PHP_URL_HOST) === 'api.focusnfe.com.br' ? 'producao' : 'homologacao';
    }

    public static function normalizeSettings(array $settings): array
    {
        if (! filled($settings['environment'] ?? null)) {
            $settings['environment'] = self::environmentFromUrl($settings['base_url'] ?? null);
        }

        if (filled($settings['api_key'] ?? null)) {
            $legacyEnvironment = filled($settings['base_url'] ?? null)
                ? self::environmentFromUrl($settings['base_url'])
                : $settings['environment'];
            $settings['api_keys'][$legacyEnvironment] ??= $settings['api_key'];
        }

        unset($settings['api_key'], $settings['base_url']);

        return $settings;
    }

    private function guardEnvironment(string $environment): void
    {
        if (! isset(self::ENVIRONMENT_URLS[$environment])) {
            throw new RuntimeException('Ambiente fiscal inválido.');
        }
    }

    private function guardConnection(FiscalConnection $connection, Company $company, bool $allowInactiveCompany = false): void
    {
        $this->guardEnvironment($connection->environment);

        if ($connection->provider !== 'focus') {
            throw new RuntimeException('Esta conexão não pertence à Focus.');
        }

        if ((! $allowInactiveCompany && ! $company->is_active) || ! $connection->enabled || blank($connection->api_key)) {
            throw new RuntimeException('A empresa/conexão fiscal está desativada ou sem token próprio para este ambiente.');
        }
    }

    private function legacyFiscalSettingsFor(User $user): array
    {
        $emitente = array_filter([
            'nome' => $user->razao_social ?: $user->name,
            'logradouro' => $user->address,
            'numero' => $user->address_number,
            'municipio' => $user->city,
            'uf' => $user->state,
            'cep' => $user->zip_code,
            'inscricao_estadual' => $user->inscricao_estatual,
        ], fn (mixed $value): bool => filled($value));
        $result = ['nfe' => ['emitente' => $emitente]];

        if (filled($user->cnpj)) {
            $result['prestador']['cnpj'] = $user->cnpj;
        }

        return $result;
    }

    private function connectionCredentials(FiscalConnection $connection): array
    {
        return [
            'company_id' => $connection->company_id,
            'fiscal_connection_id' => $connection->id,
            'environment' => $connection->environment,
            'base_url' => self::ENVIRONMENT_URLS[$connection->environment],
            'api_key' => $connection->api_key,
            'api_password' => '',
        ];
    }
}
