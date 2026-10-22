<?php

namespace App\Actions;

use App\Models\FiscalDocument;
use App\Models\Sale;
use App\Services\FiscalEmissionService;

class EmitSaleNfeAction
{
    public function __construct(protected FiscalEmissionService $emission) {}

    public function execute(Sale|FiscalDocument $sale): array
    {
        return $this->emission->execute($sale);
    }
}
