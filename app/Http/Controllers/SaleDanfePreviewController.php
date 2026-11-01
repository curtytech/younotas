<?php

namespace App\Http\Controllers;

use App\Actions\PreviewSaleDanfeAction;
use App\Models\Sale;
use Illuminate\Http\Response;
use RuntimeException;

class SaleDanfePreviewController extends Controller
{
    public function __invoke(Sale $sale, PreviewSaleDanfeAction $action): Response|\Illuminate\Http\RedirectResponse
    {
        abort_unless(
            auth()->check() && auth()->user()->canAccessCompany((int) $sale->company_id, 'view'),
            403,
        );

        try {
            return $action->execute($sale);
        } catch (RuntimeException $exception) {
            \Filament\Notifications\Notification::make()->warning()
                ->title('Não foi possível abrir a DANFE')
                ->body($exception->getMessage())->persistent()->send();
            return redirect(\App\Filament\Resources\SaleResource::getUrl('edit', ['record' => $sale]));
        }
    }
}
