<?php

namespace App\Http\Controllers;

use App\Actions\PreviewSaleDanfeAction;
use App\Models\Sale;
use Illuminate\Http\Response;
use RuntimeException;

class SaleDanfePreviewController extends Controller
{
    public function __invoke(Sale $sale, PreviewSaleDanfeAction $action): Response
    {
        abort_unless(
            auth()->check() && (auth()->user()->role === 'admin' || $sale->user_id === auth()->id()),
            403,
        );

        try {
            return $action->execute($sale);
        } catch (RuntimeException $exception) {
            return response($exception->getMessage(), 422, [
                'Content-Type' => 'text/plain; charset=UTF-8',
            ]);
        }
    }
}
