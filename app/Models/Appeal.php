<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Appeal extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'fine_id',
        'appeal_status_id',
        'date',
    ];

    protected $casts = [
        'date' => 'date',
    ];

    public function appealStatus(): BelongsTo
    {
        return $this->belongsTo(AppealStatus::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function fine(): BelongsTo
    {
        return $this->belongsTo(Fine::class);
    }
}
