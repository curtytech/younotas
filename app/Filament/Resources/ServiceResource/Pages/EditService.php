<?php

namespace App\Filament\Resources\ServiceResource\Pages;

use App\Filament\Resources\ServiceResource;
use App\Jobs\EmitServiceNfseJob;
use App\Support\NfseStatus;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditService extends EditRecord
{
    protected static string $resource = ServiceResource::class;

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
            Actions\Action::make('emitir_nfse')
                ->label('Emitir NFS-e')
                ->icon('heroicon-o-document-text')
                ->color('success')
                ->visible(fn (): bool => NfseStatus::canEmit($this->record->focus_nfse_status))
                ->requiresConfirmation()
                ->action(function (): void {
                    EmitServiceNfseJob::dispatch($this->record->id);

                    Notification::make()
                        ->title('Emissão da NFS-e agendada')
                        ->body('O processamento será acompanhado automaticamente.')
                        ->success()
                        ->send();
                }),
            Actions\DeleteAction::make(),
        ];
    }
}
