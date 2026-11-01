<?php

use App\Actions\ConvertQuoteToSaleAction;
use App\Filament\Resources\QuoteResource;
use App\Filament\Resources\QuoteResource\Pages\CreateQuote;
use App\Filament\Resources\QuoteResource\Pages\EditQuote;
use App\Filament\Resources\QuoteResource\Pages\ListQuotes;
use App\Models\Client;
use App\Models\Company;
use App\Models\FocusNfeSetting;
use App\Models\Product;
use App\Models\Quote;
use App\Models\QuoteItem;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function quoteWorld(string $role = 'enterprise'): object
{
    $user = User::factory()->create(['role' => $role]);
    $product = Product::factory()->create(['user_id' => $user->id, 'stock_quantity' => 10, 'sale_price' => 25]);
    $companyId = $product->company_id;
    $service = Service::factory()->create(['user_id' => $user->id, 'company_id' => $companyId, 'unit_price' => 100]);
    $client = Client::factory()->create(['user_id' => $user->id, 'company_id' => $companyId]);
    $quote = Quote::query()->create([
        'user_id' => $user->id, 'company_id' => $companyId, 'client_id' => $client->id,
        'number' => 'ORC-'.fake()->unique()->numerify('######'), 'quote_date' => now(), 'status' => 'sent', 'discount_amount' => 0,
    ]);

    return (object) compact('user', 'product', 'service', 'client', 'quote', 'companyId');
}

function addItem(Quote $quote, Product|Service $catalog, float $quantity = 1, ?float $price = null): QuoteItem
{
    $isProduct = $catalog instanceof Product;

    return $quote->items()->create([
        'item_type' => $isProduct ? 'product' : 'service',
        'product_id' => $isProduct ? $catalog->id : null, 'service_id' => $isProduct ? null : $catalog->id,
        'name' => $catalog->name, 'unit' => 'UN', 'quantity' => $quantity, 'unit_price' => $price ?? ($isProduct ? $catalog->sale_price : $catalog->unit_price),
    ]);
}

test('item recalcula o total do orçamento ao ser salvo, alterado e excluído', function (): void {
    $w = quoteWorld();
    $item = addItem($w->quote, $w->product, 2);
    expect((float) $item->total_amount)->toBe(50.0)->and((float) $w->quote->fresh()->total_amount)->toBe(50.0);

    $item->update(['quantity' => 3.5]);
    expect((float) $w->quote->fresh()->total_amount)->toBe(87.5);

    $item->delete();
    expect((float) $w->quote->fresh()->total_amount)->toBe(0.0);
});

test('desconto maior que os itens zera o total em vez de ficar negativo', function (): void {
    $w = quoteWorld();
    addItem($w->quote, $w->product, 1);
    $w->quote->update(['discount_amount' => 999]);

    expect((float) $w->quote->fresh()->total_amount)->toBe(0.0);
});

test('item exige produto ou serviço da mesma empresa e nunca os dois', function (): void {
    $w = quoteWorld();
    $other = quoteWorld();

    expect(fn () => addItem($w->quote, $other->product))->toThrow(ValidationException::class)
        ->and(fn () => addItem($w->quote, $other->service))->toThrow(ValidationException::class)
        ->and(fn () => $w->quote->items()->create(['item_type' => 'product', 'product_id' => $w->product->id, 'service_id' => $w->service->id, 'name' => 'x', 'quantity' => 1, 'unit_price' => 1]))->toThrow(ValidationException::class)
        ->and(fn () => $w->quote->items()->create(['item_type' => 'product', 'name' => 'x', 'quantity' => 1, 'unit_price' => 1]))->toThrow(ValidationException::class)
        ->and(fn () => $w->quote->items()->create(['item_type' => 'outro', 'product_id' => $w->product->id, 'name' => 'x', 'quantity' => 1, 'unit_price' => 1]))->toThrow(ValidationException::class);
});

test('orçamento não aceita cliente de outra empresa nem mudar de empresa', function (): void {
    $w = quoteWorld();
    $other = quoteWorld();

    expect(fn () => $w->quote->update(['client_id' => $other->client->id]))->toThrow(ValidationException::class)
        ->and(fn () => $w->quote->update(['company_id' => $other->companyId]))->toThrow(ValidationException::class);
});

test('isExpired só vale para rascunho ou enviado com validade passada', function (): void {
    $w = quoteWorld();
    $w->quote->valid_until = today()->subDay();

    $w->quote->status = 'sent';
    expect($w->quote->isExpired())->toBeTrue();
    $w->quote->status = 'draft';
    expect($w->quote->isExpired())->toBeTrue();
    $w->quote->status = 'approved';
    expect($w->quote->isExpired())->toBeFalse();
    $w->quote->status = 'sent';
    $w->quote->valid_until = today();
    expect($w->quote->isExpired())->toBeFalse();
    $w->quote->valid_until = null;
    expect($w->quote->isExpired())->toBeFalse();
});

test('conversão de orçamento com vários produtos baixa o estoque de cada um', function (): void {
    $w = quoteWorld();
    $second = Product::factory()->create(['user_id' => $w->user->id, 'company_id' => $w->companyId, 'sku' => 'OUTRO', 'stock_quantity' => 5, 'sale_price' => 10]);
    addItem($w->quote, $w->product, 4);
    addItem($w->quote, $second, 5);
    $this->actingAs($w->user);

    $sale = app(ConvertQuoteToSaleAction::class)->execute($w->quote);

    expect($sale->saleItems)->toHaveCount(2)
        ->and((float) $sale->total_amount)->toBe(150.0)
        ->and((float) $w->product->fresh()->stock_quantity)->toBe(6.0)
        ->and((float) $second->fresh()->stock_quantity)->toBe(0.0);
});

test('conversão aplica o desconto só quando não há serviços e copia as observações', function (): void {
    $w = quoteWorld();
    addItem($w->quote, $w->product, 2);
    $w->quote->update(['discount_amount' => 10, 'notes' => 'Entrega em 5 dias']);
    $this->actingAs($w->user);

    $sale = app(ConvertQuoteToSaleAction::class)->execute($w->quote);

    expect((float) $sale->discount_amount)->toBe(10.0)
        ->and((float) $sale->total_amount)->toBe(40.0)
        ->and($sale->notes)->toContain($w->quote->number)->toContain('Entrega em 5 dias')
        ->and($sale->issue_invoice)->toBeFalse();
});

test('venda excluída libera o orçamento para gerar outra', function (): void {
    $w = quoteWorld();
    addItem($w->quote, $w->product, 1);
    $this->actingAs($w->user);
    $sale = app(ConvertQuoteToSaleAction::class)->execute($w->quote);
    $sale->delete();

    $again = app(ConvertQuoteToSaleAction::class)->execute($w->quote->fresh());

    expect($again->id)->not->toBe($sale->id);
});

test('orçamento sem itens não gera venda', function (): void {
    $w = quoteWorld();

    expect(fn () => app(ConvertQuoteToSaleAction::class)->execute($w->quote))->toThrow(ValidationException::class);
});

test('formulário de criação recusa envio vazio', function (): void {
    $w = quoteWorld();
    $this->actingAs($w->user);

    Livewire::test(CreateQuote::class)
        ->fillForm(['client_id' => null, 'items' => []])
        ->call('create')
        ->assertHasFormErrors(['client_id' => 'required', 'items']);

    expect(Quote::query()->count())->toBe(1);
});

test('formulário recusa validade anterior à data e desconto maior que o subtotal', function (): void {
    $w = quoteWorld();
    $this->actingAs($w->user);
    $items = [['item_type' => 'product', 'product_id' => $w->product->id, 'name' => $w->product->name, 'unit' => 'UN', 'quantity' => 1, 'unit_price' => 25]];

    Livewire::test(CreateQuote::class)
        ->fillForm(['company_id' => $w->companyId, 'client_id' => $w->client->id, 'status' => 'draft', 'quote_date' => '2026-05-10', 'valid_until' => '2026-05-01', 'items' => $items])
        ->call('create')
        ->assertHasFormErrors(['valid_until']);

    Livewire::test(CreateQuote::class)
        ->fillForm(['company_id' => $w->companyId, 'client_id' => $w->client->id, 'status' => 'draft', 'discount_amount' => 26, 'items' => $items])
        ->call('create')
        ->assertHasFormErrors(['discount_amount']);
});

test('formulário aceita observações longas e acentuadas e preserva na edição', function (): void {
    $w = quoteWorld();
    addItem($w->quote, $w->product, 1);
    $this->actingAs($w->user);
    $notes = str_repeat('Condições — ação, coração, açaí. ', 100).str_repeat('SemEspaço', 50);

    Livewire::test(EditQuote::class, ['record' => $w->quote->getRouteKey()])
        ->fillForm(['notes' => $notes, 'valid_until' => null])
        ->call('save')
        ->assertHasNoFormErrors();

    $fresh = $w->quote->fresh();
    expect($fresh->notes)->toBe($notes)->and($fresh->valid_until)->toBeNull();

    Livewire::test(EditQuote::class, ['record' => $w->quote->getRouteKey()])
        ->fillForm(['notes' => null])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($w->quote->fresh()->notes)->toBeNull()->and($w->quote->fresh()->items)->toHaveCount(1);
});

test('editar sem alterar nada mantém itens e total', function (): void {
    $w = quoteWorld();
    addItem($w->quote, $w->product, 2);
    $this->actingAs($w->user);

    Livewire::test(EditQuote::class, ['record' => $w->quote->getRouteKey()])->call('save')->assertHasNoFormErrors();

    expect($w->quote->fresh()->items)->toHaveCount(1)->and((float) $w->quote->fresh()->total_amount)->toBe(50.0);
});

test('isolamento: usuário vê só os orçamentos da própria empresa e recebe 403 no PDF de outra', function (): void {
    $mine = quoteWorld();
    $theirs = quoteWorld();
    addItem($theirs->quote, $theirs->product);
    $this->actingAs($mine->user);

    expect(QuoteResource::getEloquentQuery()->pluck('id')->all())->toBe([$mine->quote->id])
        ->and(QuoteResource::canEdit($theirs->quote))->toBeFalse();

    $this->get(route('quotes.pdf', $theirs->quote))->assertForbidden();
    Livewire::test(ListQuotes::class)->assertCanSeeTableRecords([$mine->quote])->assertCanNotSeeTableRecords([$theirs->quote]);
});

test('perfil consulta vê e baixa o PDF, mas não cria nem edita', function (): void {
    $w = quoteWorld();
    addItem($w->quote, $w->product);
    $viewer = User::factory()->create(['role' => 'enterprise']);
    Company::query()->findOrFail($w->companyId)->members()->attach($viewer->id, ['role' => 'viewer']);
    $this->actingAs($viewer);

    expect(QuoteResource::canView($w->quote))->toBeTrue()
        ->and(QuoteResource::canCreate())->toBeFalse()
        ->and(QuoteResource::canEdit($w->quote))->toBeFalse();
    $this->get(route('quotes.pdf', $w->quote))->assertOk();
});

test('usuário inativo e visitante não acessam o PDF', function (): void {
    $w = quoteWorld();
    addItem($w->quote, $w->product);

    $this->get(route('quotes.pdf', $w->quote))->assertRedirect();

    $w->user->forceFill(['is_active' => false])->save();
    $this->actingAs($w->user->fresh());
    $this->get(route('quotes.pdf', $w->quote))->assertForbidden();
});

test('PDF funciona com textos longos, sem validade, sem observações, produto removido e serviço', function (): void {
    $w = quoteWorld();
    $w->quote->update(['valid_until' => null, 'notes' => null]);
    $long = addItem($w->quote, $w->product, 1);
    $long->update(['name' => str_repeat('PalavraSemEspaçoEAcentuação', 20)]);
    addItem($w->quote, $w->service, 1);
    $orphan = addItem($w->quote, $w->product, 1);
    QuoteItem::query()->whereKey($orphan->id)->update(['product_id' => null]);
    $this->actingAs($w->user);

    $this->get(route('quotes.pdf', $w->quote))->assertOk()->assertHeader('content-type', 'application/pdf');
});
