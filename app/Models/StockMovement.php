<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockMovement extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'product_id',
        'movement_type',
        'source_type',
        'reference',
        'quantity',
        'previous_stock',
        'current_stock',
        'unit_cost',
        'notes',
        'moved_at',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
        'previous_stock' => 'decimal:3',
        'current_stock' => 'decimal:3',
        'unit_cost' => 'decimal:2',
        'moved_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
