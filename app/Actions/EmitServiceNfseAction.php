<?php

namespace App\Actions;

use App\Models\FiscalDocument;
use App\Models\ServiceOrder;
use App\Services\FiscalEmissionService;

class EmitServiceNfseAction
{
    public function __construct(protected FiscalEmissionService $emission) {}

    public function execute(ServiceOrder|FiscalDocument $service): array
    {
        return $this->emission->execute($service);
    }
}
