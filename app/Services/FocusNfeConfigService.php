<?php

namespace App\Services;

use App\Models\User;

class FocusNfeConfigService
{
    public const ENVIRONMENT_URLS = [
        'homologacao' => 'https://homologacao.focusnfe.com.br',
        'producao' => 'https://api.focusnfe.com.br',
    ];

    /**
     * Configuração efetiva da Focus para o usuário. As credenciais da linha do usuário
     * substituem as do .env por inteiro; um token de um ambiente nunca é usado no outro.
     */
    public function forUser(User $user): array
    {
        $user->loadMissing('focusNfeSetting');

        $settings = $user->focusNfeSetting?->settings ?? [];
        $settings = is_array($settings) ? $settings : [];
        $defaults = config('services.focus_nfe', []);

        $credentials = $this->hasCredentials($settings) ? $settings : $defaults;

        $merged = array_replace_recursive($defaults, $settings);

        return array_replace($merged, $this->resolveCredentials($credentials, $merged));
    }

    public function baseUrlFor(?User $user): string
    {
        $config = $user ? $this->forUser($user) : config('services.focus_nfe', []);

        return rtrim((string) ($config['base_url'] ?? config('services.focus_nfe.base_url')), '/');
    }

    public static function environmentFromUrl(?string $baseUrl): string
    {
        return str_contains((string) $baseUrl, 'api.focusnfe.com.br') ? 'producao' : 'homologacao';
    }

    /**
     * Converte o formato antigo (um token + URL) para ambiente + um token por ambiente.
     */
    public static function normalizeSettings(array $settings): array
    {
        if (filled($settings['environment'] ?? null) || isset($settings['api_keys'])) {
            return $settings;
        }

        $environment = self::environmentFromUrl($settings['base_url'] ?? null);

        if (filled($settings['api_key'] ?? null)) {
            $settings['api_keys'][$environment] = $settings['api_key'];
        }

        $settings['environment'] = $environment;
        unset($settings['api_key'], $settings['base_url']);

        return $settings;
    }

    protected function hasCredentials(array $settings): bool
    {
        return filled($settings['api_key'] ?? null)
            || filled($settings['api_keys']['homologacao'] ?? null)
            || filled($settings['api_keys']['producao'] ?? null);
    }

    /**
     * @return array{api_key: ?string, base_url: ?string}
     */
    protected function resolveCredentials(array $credentials, array $merged): array
    {
        $isNewFormat = filled($credentials['environment'] ?? null)
            || filled($credentials['api_keys']['homologacao'] ?? null)
            || filled($credentials['api_keys']['producao'] ?? null);

        if (! $isNewFormat) {
            return [
                'api_key' => $credentials['api_key'] ?? null,
                'base_url' => $credentials['base_url'] ?? $merged['base_url'] ?? null,
            ];
        }

        $environment = $credentials['environment'] ?? null;

        if (! isset(self::ENVIRONMENT_URLS[$environment])) {
            $environment = self::environmentFromUrl($credentials['base_url'] ?? null);
        }

        return [
            'api_key' => $credentials['api_keys'][$environment] ?? null,
            'base_url' => self::ENVIRONMENT_URLS[$environment],
            'environment' => $environment,
        ];
    }
}
