<?php

namespace App\Actions;

use App\Models\Sale;
use App\Services\FocusDanfePreviewService;
use Symfony\Component\HttpFoundation\Response;

class PreviewSaleDanfeAction
{
    public function __construct(
        protected FocusDanfePreviewService $focusDanfePreviewService,
    ) {}

    public function execute(Sale $sale): Response
    {
        $response = $this->focusDanfePreviewService->generate($sale);

        return response($response->body(), 200, [
            'Content-Type' => $response->header('Content-Type', 'application/pdf'),
            'Content-Disposition' => 'inline; filename="danfe-preview-venda-' . $sale->id . '.pdf"',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
        ]);
    }
}
