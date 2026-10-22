<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Vehicle extends Model
{
    use HasFactory, BelongsToCompany;

    protected $table = 'vehicle'; // Migration uses singular 'vehicle'

    protected $fillable = [
        'user_id', 'company_id',
        'name',
        'plate',
        'renavam',
        'model',
        'year',
        'type',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
