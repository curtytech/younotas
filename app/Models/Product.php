<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::created(function (Product $product): void {
            $expectedCode = (string) $product->id;

            if ($product->code !== $expectedCode) {
                $product->forceFill(['code' => $expectedCode])->saveQuietly();
            }
        });

        static::saving(function (Product $product): void {
            if ($product->exists) {
                $product->code = (string) $product->id;
            }
        });
    }

    protected $fillable = [
        'user_id',
        'code',
        'sku',
        'barcode',
        'name',
        'description',
        'ncm_code',
        'cest_code',
        'gtin',
        'unit',
        'cost_price',
        'sale_price',
        'stock_quantity',
        'minimum_stock',
        'icms_aliquot',
        'ipi_aliquot',
        'pis_aliquot',
        'cofins_aliquot',
        'is_active',
        'notes',
    ];

    protected $casts = [
        'cost_price' => 'decimal:2',
        'sale_price' => 'decimal:2',
        'stock_quantity' => 'decimal:3',
        'minimum_stock' => 'decimal:3',
        'icms_aliquot' => 'decimal:2',
        'ipi_aliquot' => 'decimal:2',
        'pis_aliquot' => 'decimal:2',
        'cofins_aliquot' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    public function saleItems(): HasMany
    {
        return $this->hasMany(SaleItem::class);
    }
}
