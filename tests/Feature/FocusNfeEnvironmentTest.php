<?php

use App\Filament\Resources\FocusNfeSettingResource\Pages\EditFocusNfeSetting;
use App\Models\FocusNfeSetting;
use App\Models\User;
use App\Services\FocusNfeConfigService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

const HML_URL = 'https://homologacao.focusnfe.com.br';
const PROD_URL = 'https://api.focusnfe.com.br';

beforeEach(function () {
    config(['services.focus_nfe' => array_replace(config('services.focus_nfe'), [
        'api_key' => null, 'base_url' => HML_URL, 'environment' => null,
        'api_keys' => ['homologacao' => null, 'producao' => null],
    ])]);
    $this->user = User::factory()->create();
});

function focusConfigFor(User $user, array $settings): array
{
    $user->focusNfeSetting()->create(['settings' => $settings]);

    return app(FocusNfeConfigService::class)->forUser($user->fresh());
}

it('usa o token e a URL do ambiente selecionado', function () {
    $settings = ['environment' => 'producao', 'api_keys' => ['homologacao' => 'tok-hml', 'producao' => 'tok-prod']];
    $config = focusConfigFor($this->user, $settings);

    expect($config['api_key'])->toBe('tok-prod')->and($config['base_url'])->toBe(PROD_URL);

    $this->user->focusNfeSetting->update(['settings' => ['environment' => 'homologacao'] + $settings]);
    $config = app(FocusNfeConfigService::class)->forUser($this->user->fresh());

    expect($config['api_key'])->toBe('tok-hml')->and($config['base_url'])->toBe(HML_URL);
});

it('nunca usa o token de homologação em produção quando o de produção está vazio', function () {
    expect(fn () => focusConfigFor($this->user, ['environment' => 'producao', 'api_keys' => ['homologacao' => 'tok-hml', 'producao' => '']]))
        ->toThrow(RuntimeException::class, 'sem token próprio');
});

it('mantém configurações antigas com um token e URL', function () {
    $config = focusConfigFor($this->user, ['api_key' => 'tok-antigo', 'base_url' => PROD_URL]);

    expect($config['api_key'])->toBe('tok-antigo')->and($config['base_url'])->toBe(PROD_URL);
});

it('recusa emissão sem credenciais próprias mesmo quando existe configuração fiscal global', function () {
    config(['services.focus_nfe.environment' => 'producao', 'services.focus_nfe.api_keys' => ['homologacao' => 'env-hml', 'producao' => 'env-prod']]);

    expect(fn () => app(FocusNfeConfigService::class)->forUser($this->user))
        ->toThrow(RuntimeException::class, 'sem token próprio');
});

it('a configuração do usuário substitui as credenciais do .env por inteiro', function () {
    config(['services.focus_nfe.environment' => 'producao', 'services.focus_nfe.api_keys' => ['homologacao' => 'env-hml', 'producao' => 'env-prod']]);

    $config = focusConfigFor($this->user, ['environment' => 'homologacao', 'api_keys' => ['homologacao' => 'user-hml']]);

    expect($config['api_key'])->toBe('user-hml')->and($config['base_url'])->toBe(HML_URL);
});

it('migra as credenciais legadas para conexões e mantém os campos de token vazios na edição', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $setting = FocusNfeSetting::factory()->create(['user_id' => $this->user->id]);
    $legacy = collect($setting->settings)->except(['environment', 'api_keys'])->all();
    $setting->update(['settings' => $legacy + ['api_key' => 'tok-antigo', 'base_url' => PROD_URL]]);

    $this->actingAs($admin);

    Livewire::test(EditFocusNfeSetting::class, ['record' => $setting->getRouteKey()])
        ->assertFormSet(['settings.environment' => 'producao', 'settings.api_keys.producao' => null, 'settings.api_keys.homologacao' => null])
        ->fillForm(['settings.environment' => 'homologacao', 'settings.api_keys.homologacao' => 'tok-hml', 'settings.nfse.natureza_operacao' => '1'])
        ->call('save')
        ->assertHasNoFormErrors();

    $saved = $setting->fresh()->settings;

    expect($saved['environment'])->toBe('homologacao')
        ->and($saved)->not->toHaveKeys(['api_key', 'api_keys', 'api_password', 'base_url']);
    $connections = $setting->company->connections()->get()->keyBy('environment');
    expect($connections['homologacao']->api_key)->toBe('tok-hml')
        ->and($connections['producao']->api_key)->toBe('tok-antigo');
});

it('exige o token do ambiente selecionado', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $setting = FocusNfeSetting::factory()->create(['user_id' => $this->user->id]);

    $this->actingAs($admin);

    Livewire::test(EditFocusNfeSetting::class, ['record' => $setting->getRouteKey()])
        ->fillForm(['settings.environment' => 'producao', 'settings.api_keys.producao' => ''])
        ->call('save')
        ->assertHasFormErrors(['settings.api_keys.producao' => 'required']);
});
