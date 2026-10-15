<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FiscalEvent extends Model
{
    protected $fillable = ['company_id', 'fiscal_document_id', 'actor_id', 'event', 'context'];

    protected $casts = ['context' => 'encrypted:array'];
}
