<?php

namespace App\Filament\Resources\FiscalDocumentResource\Pages;

use App\Filament\Resources\FiscalDocumentResource;
use App\Jobs\ImportFocusNfeBackupJob;
use App\Jobs\ImportFocusNfseByReferenceJob;
use App\Models\User;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Collection;

class ListFiscalDocuments extends ListRecords
{
    protected static string $resource = FiscalDocumentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('importar_focus_nfe')
                ->label('Importar NF-e da Focus')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('primary')
                ->form($this->userField()->merge([
                    Forms\Components\TextInput::make('month')
                        ->label('Mês (AAAAMM)')
                        ->placeholder('202601')
                        ->required()
                        ->regex('/^\d{6}$/')
                        ->default(now()->format('Ym')),
                ])->all())
                ->action(function (array $data): void {
                    ImportFocusNfeBackupJob::dispatch(
                        (int) ($data['user_id'] ?? auth()->id()),
                        $data['month'],
                    );
                })
                ->successNotificationTitle('Importação de NF-e enfileirada.'),

            Actions\Action::make('importar_focus_nfse')
                ->label('Importar NFS-e por referência')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('info')
                ->form($this->userField()->merge([
                    Forms\Components\TextInput::make('reference')
                        ->label('Referência Focus da NFS-e')
                        ->required()
                        ->maxLength(255),
                ])->all())
                ->action(function (array $data): void {
                    ImportFocusNfseByReferenceJob::dispatch(
                        (int) ($data['user_id'] ?? auth()->id()),
                        $data['reference'],
                    );
                })
                ->successNotificationTitle('Importação de NFS-e enfileirada.'),
        ];
    }

    protected function userField(): Collection
    {
        return collect([
            Forms\Components\Select::make('user_id')
                ->label('Empresa')
                ->options(fn (): array => User::query()->orderBy('name')->pluck('name', 'id')->all())
                ->default(auth()->id())
                ->required()
                ->hidden(fn (): bool => auth()->user()->role !== 'admin'),
        ]);
    }
}
