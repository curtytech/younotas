<?php

namespace App\Filament\Resources\ServiceResource\Pages;

use App\Actions\EmitServiceNfseAction;
use App\Filament\Resources\ServiceResource;
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
                ->requiresConfirmation()
                ->action(function (): void {
                    $response = app(EmitServiceNfseAction::class)->execute($this->record);

                    Notification::make()
                        ->title('NFS-e enviada para a Focus')
                        ->body($response['status'] ?? 'Requisicao enviada com sucesso.')
                        ->success()
                        ->send();
                }),
            Actions\DeleteAction::make(),
        ];
    }
}
