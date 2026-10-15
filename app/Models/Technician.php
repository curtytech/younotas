<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Technician extends Model
{
    use HasFactory;

    protected $fillable = ['user_id', 'name', 'phone', 'email', 'is_active', 'notes'];

    protected $casts = ['is_active' => 'boolean'];

    public function user(): BelongsTo { return $this->belongsTo(User::class); }

    public function serviceOrders(): HasMany { return $this->hasMany(ServiceOrder::class); }
}
