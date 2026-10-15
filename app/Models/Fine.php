<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Fine extends Model
{
    use HasFactory, BelongsToCompany;

    protected $fillable = [
        'user_id', 'company_id',
        'vehicle_id',
        'ait',
        'fine_date',
        'description',
        'fine_article',
    ];

    protected $casts = [
        'fine_date' => 'date',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }
}
