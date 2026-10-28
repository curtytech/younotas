<?php

namespace App\Http\Controllers;

use App\Models\Quote;
use App\Services\QuotePdfService;
use Illuminate\Http\Response;

class QuotePdfController
{
    public function __invoke(Quote $quote): Response
    {
        abort_unless(auth()->user()?->canAccessCompany($quote->company_id, 'view'), 403);

        return app(QuotePdfService::class)->download($quote);
    }
}
