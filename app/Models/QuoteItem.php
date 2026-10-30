<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class QuoteItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'quote_id', 'product_id', 'service_id', 'item_type', 'name',
        'unit', 'quantity', 'unit_price', 'total_amount',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
        'unit_price' => 'decimal:2',
        'total_amount' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::saving(function (QuoteItem $item): void {
            $quoteCompanyId = $item->quote()->value('company_id');
            $catalogItem = $item->item_type === 'product' ? $item->product() : $item->service();
            if (! in_array($item->item_type, ['product', 'service'], true)
                || ($item->product_id && $item->service_id)
                || (! $item->product_id && ! $item->service_id)
                || (int) $catalogItem->value('company_id') !== (int) $quoteCompanyId) {
                throw ValidationException::withMessages([
                    'items' => 'O item deve ser um produto ou serviço da mesma empresa do orçamento.',
                ]);
            }

            $item->total_amount = max(round((float) $item->quantity * (float) $item->unit_price, 2), 0);
        });

        static::saved(fn (QuoteItem $item) => $item->quote?->recalculateTotal());
        static::deleted(fn (QuoteItem $item) => $item->quote?->recalculateTotal());
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }
}
