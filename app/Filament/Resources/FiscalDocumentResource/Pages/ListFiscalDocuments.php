<?php

namespace App\Filament\Resources\FiscalDocumentResource\Pages;

use App\Filament\Resources\CompanyOwnedResource;
use App\Filament\Resources\FiscalDocumentResource;
use App\Jobs\ImportFocusNfeBackupJob;
use App\Jobs\ImportFocusNfseByReferenceJob;
use App\Models\Company;
use App\Models\FiscalConnection;
use App\Services\FocusNfeConfigService;
use Filament\Actions;
use Filament\Forms;
use Filament\Forms\Get;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListFiscalDocuments extends ListRecords
{
    protected static string $resource = FiscalDocumentResource::class;

    public function getTabs(): array
    {
        return [
            'all' => Tab::make('Todos'),
            'attention' => Tab::make('Pendências')
                ->icon('heroicon-o-exclamation-triangle')
                ->badge(fn (): int => FiscalDocumentResource::attentionCount())
                ->badgeColor('danger')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->needingAttention()),
        ];
    }

    protected function getHeaderActions(): array
    {
        $canImport = fn (): bool => CompanyOwnedResource::companyOptions('issue') !== [];

        return [
            Actions\Action::make('exportar_contabilidade')->label('Exportar mês para a contabilidade')->icon('heroicon-o-archive-box-arrow-down')
                ->visible(fn (): bool => CompanyOwnedResource::companyOptions('view') !== [])
                ->form([
                    CompanyOwnedResource::companyField('view'),
                    Forms\Components\Select::make('environment')->label('Ambiente')->required()->options(['producao' => 'Produção', 'homologacao' => 'Homologação'])->default('producao'),
                    Forms\Components\TextInput::make('month')->label('Mês (AAAAMM)')->required()->regex('/^\d{4}(0[1-9]|1[0-2])$/')->default(now()->subMonth()->format('Ym')),
                ])
                ->action(fn (array $data) => redirect()->route('fiscal-documents.export', $data)),
            Actions\Action::make('importar_focus_nfe')->label('Importar NF-e da Focus')->icon('heroicon-o-arrow-down-tray')->visible($canImport)
                ->form([...$this->connectionFields(), Forms\Components\TextInput::make('month')->label('Mês (AAAAMM)')->required()->regex('/^\d{4}(0[1-9]|1[0-2])$/')->default(now()->format('Ym'))])
                ->action(function (array $data): void {
                    $connection = $this->connectionFor($data);
                    ImportFocusNfeBackupJob::dispatch(auth()->id(), $data['month'], $connection->id);
                })->successNotificationTitle('Importação de NF-e enfileirada.'),
            Actions\Action::make('importar_focus_nfse')->label('Importar NFS-e por referência')->icon('heroicon-o-arrow-down-tray')->visible($canImport)
                ->form([...$this->connectionFields(), Forms\Components\TextInput::make('reference')->label('Referência Focus')->required()->maxLength(255)])
                ->action(function (array $data): void {
                    $connection = $this->connectionFor($data);
                    ImportFocusNfseByReferenceJob::dispatch(auth()->id(), $data['reference'], $connection->id);
                })->successNotificationTitle('Importação de NFS-e enfileirada.'),
        ];
    }

    private function connectionFields(): array
    {
        return [
            CompanyOwnedResource::companyField(),
            Forms\Components\Select::make('environment')->label('Ambiente da importação')->required()->options(['homologacao' => 'Homologação', 'producao' => 'Produção'])
                ->default(fn (Get $get): string => Company::find($get('company_id'))?->default_environment ?? 'homologacao'),
        ];
    }

    private function connectionFor(array $data): FiscalConnection
    {
        $company = Company::findOrFail($data['company_id']);
        abort_unless(auth()->user()->canAccessCompany($company, 'issue'), 403);
        $config = app(FocusNfeConfigService::class)->forCompany($company, $data['environment']);
        return FiscalConnection::findOrFail($config['fiscal_connection_id']);
    }
}
