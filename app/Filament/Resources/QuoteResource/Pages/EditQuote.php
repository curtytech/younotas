<?php

namespace App\Filament\Resources\QuoteResource\Pages;

use App\Filament\Resources\Concerns\InteractsWithCompanyOwnership;
use App\Filament\Resources\QuoteResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditQuote extends EditRecord
{
    use InteractsWithCompanyOwnership;

    protected static string $resource = QuoteResource::class;

    protected function afterSave(): void
    {
        $this->record->recalculateTotal();
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('pdf')
                ->label('Baixar PDF')->icon('heroicon-o-arrow-down-tray')->color('gray')
                ->url(fn (): string => route('quotes.pdf', $this->record))
                ->openUrlInNewTab(),
            Actions\Action::make('gerar_venda')
                ->label('Gerar venda')->icon('heroicon-o-shopping-cart')->color('success')
                ->visible(fn (): bool => blank($this->record->sale_id) && ! in_array($this->record->status, ['rejected', 'expired'], true))
                ->requiresConfirmation()
                ->modalDescription('Cria uma venda pendente com os produtos do orçamento e baixa o estoque. Salve as alterações antes.')
                ->action(function (): void {
                    QuoteResource::convertToSale($this->record);
                    $this->refreshFormData(['status']);
                }),
            Actions\DeleteAction::make(),
        ];
    }
}
