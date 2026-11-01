<?php

use App\Filament\Resources\QuoteResource\Pages\ListQuotes;
use App\Filament\Resources\SaleResource\Pages\ListSales;
use App\Models\Client;
use App\Models\Product;
use App\Models\Quote;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->user = User::factory()->create(['role' => 'enterprise']);
    $this->actingAs($this->user);
    $company = Product::factory()->create(['user_id' => $this->user->id])->company;
    $mk = fn (string $name) => Client::factory()->create(['user_id' => $this->user->id, 'company_id' => $company->id, 'name' => $name]);
    $this->alvo = $mk('Zeferino Distribuidora');
    $this->outro = $mk('Maria Comércio');
    $base = ['user_id' => $this->user->id, 'company_id' => $company->id];
    $this->quoteA = Quote::query()->create($base + ['client_id' => $this->alvo->id, 'number' => 'ORC-AAA-1', 'quote_date' => now(), 'status' => 'sent', 'discount_amount' => 0]);
    $this->quoteB = Quote::query()->create($base + ['client_id' => $this->outro->id, 'number' => 'ORC-BBB-2', 'quote_date' => now(), 'status' => 'sent', 'discount_amount' => 0]);
    $this->saleA = Sale::factory()->create($base + ['client_id' => $this->alvo->id, 'number' => 'VND-AAA-1']);
    $this->saleB = Sale::factory()->create($base + ['client_id' => $this->outro->id, 'number' => 'VND-BBB-2']);
});

test('orçamentos são encontrados pelo número e pelo nome do cliente', function (): void {
    Livewire::test(ListQuotes::class)->searchTable('Zeferino')
        ->assertCanSeeTableRecords([$this->quoteA])->assertCanNotSeeTableRecords([$this->quoteB])
        ->searchTable('ORC-AAA')
        ->assertCanSeeTableRecords([$this->quoteA])->assertCanNotSeeTableRecords([$this->quoteB]);
});

test('vendas são encontradas pelo número e pelo nome do cliente', function (): void {
    Livewire::test(ListSales::class)->searchTable('Zeferino')
        ->assertCanSeeTableRecords([$this->saleA])->assertCanNotSeeTableRecords([$this->saleB])
        ->searchTable('VND-AAA')
        ->assertCanSeeTableRecords([$this->saleA])->assertCanNotSeeTableRecords([$this->saleB]);
});

test('listas mostram o nome completo do cliente, escapado e sem reticências', function (): void {
    $name = 'Associação Beneficente dos Amigos <b>X</b> '.str_repeat('SemEspaços', 12).' Ltda';
    $client = Client::factory()->create(['user_id' => $this->user->id, 'company_id' => $this->quoteA->company_id, 'name' => $name]);
    $quote = Quote::query()->create(['user_id' => $this->user->id, 'company_id' => $this->quoteA->company_id, 'client_id' => $client->id, 'number' => 'ORC-LONGO-9', 'quote_date' => now(), 'status' => 'sent', 'discount_amount' => 0]);
    $sale = Sale::factory()->create(['user_id' => $this->user->id, 'company_id' => $this->quoteA->company_id, 'client_id' => $client->id, 'number' => 'VND-LONGO-9']);

    foreach ([[ListQuotes::class, $quote, 'ORC-LONGO-9'], [ListSales::class, $sale, 'VND-LONGO-9']] as [$page, $record, $number]) {
        Livewire::test($page)->assertCanSeeTableRecords([$record])
            ->assertSeeHtml(e($name))->assertDontSeeHtml('<b>X</b>')->assertSee($number);
    }
});
