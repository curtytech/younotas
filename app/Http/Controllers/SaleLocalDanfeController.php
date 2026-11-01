<?php

namespace App\Http\Controllers;

use App\Filament\Resources\SaleResource;
use App\Models\Sale;
use App\Services\LocalDanfeService;
use Filament\Notifications\Notification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use RuntimeException;

class SaleLocalDanfeController extends Controller
{
    public function __invoke(Sale $sale, LocalDanfeService $service): Response|RedirectResponse
    {
        abort_unless(auth()->user()?->canAccessCompany((int) $sale->company_id, 'view'), 403);

        try {
            $pdf = $service->render($sale);
        } catch (RuntimeException $exception) {
            Notification::make()->warning()->title('Não foi possível abrir a DANFE com logo')
                ->body($exception->getMessage())->persistent()->send();

            return redirect(SaleResource::getUrl('edit', ['record' => $sale]));
        }

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="danfe-venda-'.$sale->id.'.pdf"',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
