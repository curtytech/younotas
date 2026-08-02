<?php

use App\Filament\Resources\FiscalDocumentResource\Pages\ListFiscalDocuments;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('página do histórico fiscal expõe as ações de importação da Focus', function (): void {
    $admin = User::create([
        'name' => 'Admin', 'email' => 'admin@import.local',
        'password' => bcrypt('password'), 'role' => 'admin',
    ]);
    $this->actingAs($admin);

    Livewire::test(ListFiscalDocuments::class)
        ->assertOk()
        ->assertActionExists('importar_focus_nfe')
        ->assertActionExists('importar_focus_nfse');
});

test('usuário de empresa não vê o seletor de empresa na importação', function (): void {
    $enterprise = User::create([
        'name' => 'Empresa', 'email' => 'empresa@import.local',
        'password' => bcrypt('password'), 'role' => 'enterprise',
    ]);
    $this->actingAs($enterprise);

    Livewire::test(ListFiscalDocuments::class)
        ->assertOk()
        ->assertActionExists('importar_focus_nfe')
        ->assertActionExists('importar_focus_nfse');
});
