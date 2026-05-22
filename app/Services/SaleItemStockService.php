<?php

namespace App\Services;

use App\Models\Product;
use App\Models\SaleItem;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SaleItemStockService
{
    public function validateBeforeSave(SaleItem $saleItem): void
    {
        if (! $saleItem->product_id || $saleItem->quantity === null) {
            return;
        }

        $product = Product::query()->find($saleItem->product_id);

        if (! $product) {
            return;
        }

        $requestedQuantity = round((float) $saleItem->quantity, 3);
        $availableStock = (float) $product->stock_quantity;

        if ($saleItem->exists && (int) $saleItem->getOriginal('product_id') === (int) $saleItem->product_id) {
            $availableStock += (float) $saleItem->getOriginal('quantity');
        }

        if ($requestedQuantity > $availableStock) {
            throw ValidationException::withMessages([
                'saleItems' => sprintf(
                    'Estoque insuficiente para o produto "%s". Disponivel: %s, solicitado: %s.',
                    $product->name,
                    number_format($availableStock, 3, ',', '.'),
                    number_format($requestedQuantity, 3, ',', '.'),
                ),
            ]);
        }
    }

    public function handleCreated(SaleItem $saleItem): void
    {
        $this->adjustStock(
            productId: (int) $saleItem->product_id,
            deltaQuantity: -((float) $saleItem->quantity),
            saleItem: $saleItem,
            note: 'Baixa de estoque por venda.',
        );
    }

    public function handleUpdated(SaleItem $saleItem): void
    {
        $originalProductId = (int) $saleItem->getOriginal('product_id');
        $originalQuantity = round((float) $saleItem->getOriginal('quantity'), 3);
        $currentProductId = (int) $saleItem->product_id;
        $currentQuantity = round((float) $saleItem->quantity, 3);

        if ($originalProductId === $currentProductId) {
            $deltaQuantity = round($currentQuantity - $originalQuantity, 3);

            if ($deltaQuantity !== 0.0) {
                $this->adjustStock(
                    productId: $currentProductId,
                    deltaQuantity: -$deltaQuantity,
                    saleItem: $saleItem,
                    note: 'Ajuste de estoque por alteracao em item da venda.',
                );
            }

            return;
        }

        if ($originalProductId > 0) {
            $this->adjustStock(
                productId: $originalProductId,
                deltaQuantity: $originalQuantity,
                saleItem: $saleItem,
                note: 'Estoque devolvido ao produto anterior por alteracao em item da venda.',
            );
        }

        $this->adjustStock(
            productId: $currentProductId,
            deltaQuantity: -$currentQuantity,
            saleItem: $saleItem,
            note: 'Baixa de estoque no novo produto por alteracao em item da venda.',
        );
    }

    public function handleDeleted(SaleItem $saleItem): void
    {
        $this->adjustStock(
            productId: (int) $saleItem->product_id,
            deltaQuantity: (float) $saleItem->quantity,
            saleItem: $saleItem,
            note: 'Estoque devolvido por exclusao de item da venda.',
        );
    }

    protected function adjustStock(int $productId, float $deltaQuantity, SaleItem $saleItem, string $note): void
    {
        if ($productId <= 0 || $deltaQuantity === 0.0) {
            return;
        }

        DB::transaction(function () use ($productId, $deltaQuantity, $saleItem, $note): void {
            $product = Product::query()
                ->lockForUpdate()
                ->find($productId);

            if (! $product) {
                return;
            }

            $previousStock = round((float) $product->stock_quantity, 3);
            $currentStock = round($previousStock + $deltaQuantity, 3);

            if ($currentStock < 0) {
                throw ValidationException::withMessages([
                    'saleItems' => sprintf(
                        'O produto "%s" nao possui estoque suficiente para esta operacao.',
                        $product->name,
                    ),
                ]);
            }

            $product->update([
                'stock_quantity' => $currentStock,
            ]);

            StockMovement::query()->create([
                'user_id' => $saleItem->sale?->user_id ?? $product->user_id,
                'product_id' => $product->id,
                'movement_type' => $deltaQuantity < 0 ? 'exit' : 'entry',
                'source_type' => 'sale',
                'reference' => $saleItem->sale?->id ? (string) $saleItem->sale->id : null,
                'quantity' => abs($deltaQuantity),
                'previous_stock' => $previousStock,
                'current_stock' => $currentStock,
                'unit_cost' => (float) ($saleItem->unit_price ?? $product->cost_price ?? 0),
                'notes' => $note,
                'moved_at' => now(),
            ]);
        });
    }
}
