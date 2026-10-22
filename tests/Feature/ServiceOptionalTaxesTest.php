<?php

use App\Filament\Resources\ServiceResource\Pages\CreateService;
use App\Filament\Resources\ServiceResource\Pages\EditService;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('serviço pode ser criado apenas com nome e preço', function () {
    $user = User::factory()->create(['role' => 'enterprise']);
    $this->actingAs($user);

    Livewire::test(CreateService::class)
        ->fillForm(['name' => 'Manutenção preventiva', 'unit_price' => 33])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertRedirect();

    $service = Service::query()->where('user_id', $user->id)->sole();
    expect($service->unit_price)->toBe('33.00');
    foreach (['iss', 'pis', 'cofins', 'inss', 'ir', 'csll'] as $tax) {
        expect($service->{"{$tax}_aliquot"})->toBe('0.00');
    }
});

test('alíquotas apagadas na edição são salvas como zero e as preenchidas são preservadas', function () {
    $user = User::factory()->create(['role' => 'enterprise']);
    $this->actingAs($user);
    $service = Service::factory()->create(['user_id' => $user->id, 'iss_aliquot' => 5]);

    Livewire::test(EditService::class, ['record' => $service->getRouteKey()])
        ->fillForm([
            'iss_aliquot' => null,
            'pis_aliquot' => '',
            'cofins_aliquot' => 3.65,
            'inss_aliquot' => null,
            'ir_aliquot' => null,
            'csll_aliquot' => null,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $service->refresh();
    expect($service->cofins_aliquot)->toBe('3.65');
    foreach (['iss', 'pis', 'inss', 'ir', 'csll'] as $tax) {
        expect($service->{"{$tax}_aliquot"})->toBe('0.00');
    }
});
