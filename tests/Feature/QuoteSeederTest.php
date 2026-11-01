<?php

use App\Models\Quote;
use App\Models\QuoteItem;
use App\Models\User;
use Database\Seeders\QuoteSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('db:seed roda sem erros', function (): void {
    $this->artisan('db:seed')->assertSuccessful();

    expect(Quote::query()->count())->toBeGreaterThanOrEqual(10);
});

test('seed de orçamentos cobre os cenários e pode rodar de novo sem duplicar', function (): void {
    $this->seed();
    $quotes = Quote::query()->count();
    $items = QuoteItem::query()->count();
    $this->seed(QuoteSeeder::class);

    expect(Quote::query()->count())->toBe($quotes)->and(QuoteItem::query()->count())->toBe($items);

    $user = User::where('email', 'test@example.com')->firstOrFail();
    $all = Quote::query()->with('items')->where('user_id', $user->id)->get();

    expect($all->contains(fn ($q) => $q->items->isNotEmpty() && $q->items->every(fn ($i) => $i->item_type === 'product')))->toBeTrue()
        ->and($all->contains(fn ($q) => $q->items->isNotEmpty() && $q->items->every(fn ($i) => $i->item_type === 'service')))->toBeTrue()
        ->and($all->contains(fn ($q) => $q->items->pluck('item_type')->unique()->count() === 2))->toBeTrue()
        ->and($all->contains(fn ($q) => $q->isExpired()))->toBeTrue()
        ->and($all->contains(fn ($q) => $q->valid_until === null))->toBeTrue()
        ->and($all->contains(fn ($q) => $q->sale_id !== null))->toBeTrue()
        ->and($all->contains(fn ($q) => $q->items->contains(fn ($i) => $i->item_type === 'product' && $i->product_id === null)))->toBeTrue()
        ->and($all->contains(fn ($q) => str_starts_with($q->number, QuoteSeeder::STRESS_PREFIX)))->toBeTrue();
});

test('totais semeados batem com itens e desconto e os itens pertencem à empresa do orçamento', function (): void {
    $this->seed();

    Quote::query()->with(['items.product', 'items.service'])->each(function (Quote $quote): void {
        $expected = max(round((float) $quote->items->sum('total_amount') - (float) $quote->discount_amount, 2), 0);
        expect((float) $quote->total_amount)->toBe($expected);

        foreach ($quote->items as $item) {
            expect((float) $item->total_amount)->toBe(round((float) $item->quantity * (float) $item->unit_price, 2));
            $catalog = $item->product ?? $item->service;
            if ($catalog) {
                expect($catalog->company_id)->toBe($quote->company_id);
            }
        }
    });
});
