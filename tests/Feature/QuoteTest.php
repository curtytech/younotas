<?php

use App\Actions\ConvertQuoteToSaleAction;
use App\Filament\Resources\QuoteResource\Pages\EditQuote;
use App\Filament\Resources\QuoteResource\Pages\ListQuotes;
use App\Models\Client;
use App\Models\Product;
use App\Models\Quote;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->user = User::factory()->create(['role' => 'enterprise']);
    $this->product = Product::factory()->create(['user_id' => $this->user->id, 'stock_quantity' => 10, 'sale_price' => 25]);
    $this->service = Service::factory()->create(['user_id' => $this->user->id, 'company_id' => $this->product->company_id, 'unit_price' => 100]);
    $this->client = Client::factory()->create(['user_id' => $this->user->id, 'company_id' => $this->product->company_id]);
    $this->quote = Quote::query()->create([
        'user_id' => $this->user->id, 'company_id' => $this->product->company_id, 'client_id' => $this->client->id,
        'number' => 'ORC-000001', 'quote_date' => now(), 'status' => 'sent', 'discount_amount' => 10,
    ]);
    $this->quote->items()->create(['item_type' => 'product', 'product_id' => $this->product->id, 'name' => $this->product->name, 'unit' => 'UN', 'quantity' => 2, 'unit_price' => 25]);
    $this->actingAs($this->user);
});

test('o total do orçamento desconta o valor e acompanha a mudança do desconto', function (): void {
    expect((float) $this->quote->fresh()->total_amount)->toBe(40.0);

    $this->quote->update(['discount_amount' => 5]);

    expect((float) $this->quote->fresh()->total_amount)->toBe(45.0);
});

test('gerar venda cria uma venda pendente com os produtos e aprova o orçamento', function (): void {
    $sale = app(ConvertQuoteToSaleAction::class)->execute($this->quote);

    expect($sale->status)->toBe('pending')
        ->and((int) $sale->client_id)->toBe($this->client->id)
        ->and($sale->saleItems)->toHaveCount(1)
        ->and((float) $sale->total_amount)->toBe(40.0)
        ->and((float) $this->product->fresh()->stock_quantity)->toBe(8.0)
        ->and($this->quote->fresh()->status)->toBe('approved')
        ->and((int) $this->quote->fresh()->sale_id)->toBe($sale->id);

    expect(fn () => app(ConvertQuoteToSaleAction::class)->execute($this->quote->fresh()))->toThrow(ValidationException::class);
});

test('serviços ficam fora da venda e o desconto global não é aplicado', function (): void {
    $this->quote->items()->create(['item_type' => 'service', 'service_id' => $this->service->id, 'name' => $this->service->name, 'unit' => 'UN', 'quantity' => 1, 'unit_price' => 100]);

    $sale = app(ConvertQuoteToSaleAction::class)->execute($this->quote);

    expect($sale->saleItems)->toHaveCount(1)->and((float) $sale->total_amount)->toBe(50.0);
});

test('orçamento só de serviços não gera venda', function (): void {
    $this->quote->items()->delete();
    $this->quote->items()->create(['item_type' => 'service', 'service_id' => $this->service->id, 'name' => $this->service->name, 'unit' => 'UN', 'quantity' => 1, 'unit_price' => 100]);

    expect(fn () => app(ConvertQuoteToSaleAction::class)->execute($this->quote))->toThrow(ValidationException::class);
});

test('estoque insuficiente impede a venda sem alterar o orçamento', function (): void {
    $this->product->update(['stock_quantity' => 1]);

    expect(fn () => app(ConvertQuoteToSaleAction::class)->execute($this->quote))->toThrow(ValidationException::class)
        ->and($this->quote->fresh()->sale_id)->toBeNull();
});

test('ações de gerar venda e PDF funcionam no painel', function (): void {
    Livewire::test(ListQuotes::class)->assertCanSeeTableRecords([$this->quote])->callTableAction('gerar_venda', $this->quote);
    expect($this->quote->fresh()->sale_id)->not->toBeNull();

    Livewire::test(EditQuote::class, ['record' => $this->quote->getRouteKey()])->assertActionHidden('gerar_venda');

    $this->get(route('quotes.pdf', $this->quote))->assertOk()->assertHeader('content-type', 'application/pdf');
});

test('produto removido do catálogo impede a venda parcial', function (): void {
    $this->quote->items()->create(['item_type' => 'product', 'product_id' => $this->product->id, 'name' => 'Produto antigo', 'unit' => 'UN', 'quantity' => 1, 'unit_price' => 5]);
    $this->quote->items()->where('name', 'Produto antigo')->update(['product_id' => null]);

    expect(fn () => app(ConvertQuoteToSaleAction::class)->execute($this->quote))->toThrow(ValidationException::class, 'Produto antigo')
        ->and($this->quote->fresh()->sale_id)->toBeNull();
});

test('criar orçamento pelo formulário aceita desconto e validade em branco', function (): void {
    Livewire::test(App\Filament\Resources\QuoteResource\Pages\CreateQuote::class)
        ->fillForm([
            'company_id' => $this->product->company_id,
            'client_id' => $this->client->id,
            'status' => 'sent',
            'valid_until' => null,
            'discount_amount' => null,
            'items' => [['item_type' => 'product', 'product_id' => $this->product->id, 'name' => $this->product->name, 'unit' => 'UN', 'quantity' => 3, 'unit_price' => 25]],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $quote = Quote::query()->latest('id')->first();
    expect($quote->number)->toStartWith('ORC-')
        ->and((float) $quote->discount_amount)->toBe(0.0)
        ->and((float) $quote->total_amount)->toBe(75.0)
        ->and($quote->items)->toHaveCount(1);
});

test('editar orçamento pelo formulário recalcula o total', function (): void {
    Livewire::test(EditQuote::class, ['record' => $this->quote->getRouteKey()])
        ->fillForm(['discount_amount' => null])
        ->call('save')
        ->assertHasNoFormErrors();

    expect((float) $this->quote->fresh()->total_amount)->toBe(50.0);
});
