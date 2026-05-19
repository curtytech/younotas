<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Driver extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'vehicle_id',
        'name',
        'birth_date',
        'description',
        'cpf',
        'cnh',
        'cnh_expiration_date',
        'toxicologic_exam_expiration_date',
    ];

    protected $casts = [
        'birth_date' => 'date',
        'cnh_expiration_date' => 'date',
        'toxicologic_exam_expiration_date' => 'date',
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
