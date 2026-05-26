<?php

namespace App\Models;

use App\Services\SaleItemStockService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaleItem extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::saving(function (SaleItem $saleItem): void {
            if ($saleItem->product_id) {
                $saleItem->product_code = (string) $saleItem->product_id;
            }

            app(SaleItemStockService::class)->validateBeforeSave($saleItem);
        });

        static::created(function (SaleItem $saleItem): void {
            app(SaleItemStockService::class)->handleCreated($saleItem);
        });

        static::updated(function (SaleItem $saleItem): void {
            app(SaleItemStockService::class)->handleUpdated($saleItem);
        });

        static::deleted(function (SaleItem $saleItem): void {
            app(SaleItemStockService::class)->handleDeleted($saleItem);
        });
    }

    protected $fillable = [
        'sale_id',
        'product_id',
        'product_name',
        'product_code',
        'unit',
        'quantity',
        'unit_price',
        'discount_amount',
        'tax_amount',
        'total_amount',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
        'unit_price' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
    ];

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
