<?php

namespace App\Filament\Resources\SaleResource\Pages;

use App\Filament\Resources\SaleResource;
use App\Jobs\CancelSaleNfeJob;
use App\Jobs\ConsultSaleNfeJob;
use App\Jobs\EmitSaleNfeJob;
use App\Support\NfeStatus;
use Filament\Actions;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Pages\EditRecord;

class EditSale extends EditRecord
{
    protected static string $resource = SaleResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (auth()->user()->role !== 'admin') {
            $data['user_id'] = auth()->id();
        }

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('previsualizar_danfe')
                ->label('Pré-visualizar DANFe')
                ->icon('heroicon-o-document')
                ->color('gray')
                ->url(fn (): string => route('sales.danfe-preview', $this->record))
                ->openUrlInNewTab(),
            Actions\Action::make('emitir_nfe')
                ->label('Emitir NF-e')->icon('heroicon-o-paper-airplane')
                ->visible(fn (): bool => NfeStatus::canEmit($this->record->focus_nfe_status))
                ->requiresConfirmation()
                ->action(fn (): mixed => EmitSaleNfeJob::dispatch($this->record->id))
                ->successNotificationTitle('Emissão da NF-e enfileirada.'),
            Actions\Action::make('consultar_nfe')
                ->label('Consultar NF-e')->icon('heroicon-o-arrow-path')
                ->visible(fn (): bool => filled($this->record->focus_nfe_ref) && $this->record->focus_nfe_status === NfeStatus::PROCESSING)
                ->action(fn (): mixed => ConsultSaleNfeJob::dispatch($this->record->id))
                ->successNotificationTitle('Consulta da NF-e enfileirada.'),
            Actions\Action::make('cancelar_nfe')
                ->label('Cancelar NF-e')->icon('heroicon-o-x-circle')->color('danger')
                ->visible(fn (): bool => NfeStatus::canCancel($this->record->focus_nfe_status))
                ->requiresConfirmation()
                ->form([Textarea::make('justification')->label('Justificativa')->required()->minLength(15)->maxLength(255)])
                ->action(fn (array $data): mixed => CancelSaleNfeJob::dispatch($this->record->id, $data['justification']))
                ->successNotificationTitle('Cancelamento da NF-e enfileirado.'),
            Actions\DeleteAction::make(),
        ];
    }
}
