<?php

namespace App\Actions;

use App\Models\Quote;
use App\Models\Sale;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Creates a pending sale with the quote's products. Services stay out: they belong to a service order. */
class ConvertQuoteToSaleAction
{
    public function execute(Quote $quote): Sale
    {
        return DB::transaction(function () use ($quote): Sale {
            $quote = Quote::query()->lockForUpdate()->with('items')->findOrFail($quote->id);

            if ($quote->sale_id && Sale::query()->whereKey($quote->sale_id)->exists()) {
                throw ValidationException::withMessages(['quote' => 'Este orçamento já gerou a venda #'.$quote->sale_id.'.']);
            }

            $products = $quote->items->where('item_type', 'product');
            $orphan = $products->first(fn ($item): bool => blank($item->product_id));

            if ($orphan) {
                throw ValidationException::withMessages(['quote' => 'O produto "'.$orphan->name.'" foi removido do catálogo. Troque ou exclua este item do orçamento antes de gerar a venda.']);
            }

            if ($products->isEmpty()) {
                throw ValidationException::withMessages(['quote' => 'O orçamento não tem produtos. Serviços são faturados por uma ordem de serviço.']);
            }

            $hasServices = $quote->items->contains('item_type', 'service');
            $sale = Sale::query()->create([
                'user_id' => auth()->id() ?? $quote->user_id,
                'company_id' => $quote->company_id,
                'client_id' => $quote->client_id,
                'number' => 'TMP-'.uniqid(),
                'sale_date' => now()->toDateString(),
                'status' => 'pending',
                'payment_status' => 'pending',
                'issue_invoice' => false,
                'discount_amount' => $hasServices ? 0 : $quote->discount_amount,
                'tax_amount' => 0,
                'notes' => trim('Gerada a partir do orçamento '.$quote->number.'.'.($quote->notes ? "\n".$quote->notes : '')),
            ]);
            $sale->forceFill(['number' => (string) $sale->id])->saveQuietly();

            foreach ($products as $item) {
                $sale->saleItems()->create([
                    'product_id' => $item->product_id,
                    'product_name' => $item->name,
                    'product_code' => (string) $item->product_id,
                    'unit' => $item->unit,
                    'quantity' => $item->quantity,
                    'unit_price' => $item->unit_price,
                    'discount_amount' => 0,
                    'tax_amount' => 0,
                ]);
            }

            $quote->forceFill(['sale_id' => $sale->id, 'status' => 'approved'])->saveQuietly();

            return $sale->fresh();
        });
    }
}
