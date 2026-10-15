<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\FiscalDocumentResource;
use App\Models\Sale;
use App\Models\FiscalDocument;
use App\Models\ServiceOrder;
use App\Support\NfeStatus;
use App\Support\NfseStatus;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class FiscalStatusOverview extends BaseWidget
{
    protected static ?string $pollingInterval = '10s';

    protected function getStats(): array
    {
        $base = FiscalDocument::query()->visibleTo(auth()->user())->where('environment', 'producao');
        $count = fn (string $type, array $statuses): int => (clone $base)->where('document_type', $type)->whereIn('status', $statuses)->count();
        $nfseProcessing = $count('NFS-e', [NfseStatus::SENDING, NfseStatus::PROCESSING]);
        $nfseAuthorized = $count('NFS-e', [NfseStatus::AUTHORIZED]);
        $nfseErrors = $count('NFS-e', [NfseStatus::AUTHORIZATION_ERROR, NfseStatus::TRANSPORT_ERROR]);
        $nfeProcessing = $count('NF-e', [NfeStatus::SENDING, NfeStatus::PROCESSING]);
        $nfeAuthorized = $count('NF-e', [NfeStatus::AUTHORIZED]);
        $nfeErrors = $count('NF-e', [NfeStatus::AUTHORIZATION_ERROR, NfeStatus::TRANSPORT_ERROR]);

        $attention = FiscalDocument::query()->visibleTo(auth()->user())->needingAttention()->count();

        return [
            Stat::make('Pendências fiscais', $attention)
                ->description($attention > 0 ? 'Erros, notas paradas ou origem a reconciliar' : 'Nenhuma pendência')
                ->descriptionIcon($attention > 0 ? 'heroicon-m-exclamation-triangle' : 'heroicon-m-check-circle')
                ->color($attention > 0 ? 'danger' : 'success')
                ->url(FiscalDocumentResource::getUrl('index', ['activeTab' => 'attention'])),
            Stat::make('NFS-e em processamento', $nfseProcessing)
                ->description('Produção · aguardando prefeitura')
                ->descriptionIcon('heroicon-m-arrow-path')
                ->color('warning')
                ->url(route('filament.admin.resources.service-orders.index')),
            Stat::make('NFS-e autorizadas', $nfseAuthorized)
                ->description('Produção · emissões confirmadas')
                ->descriptionIcon('heroicon-m-check-circle')
                ->color('success')
                ->url(route('filament.admin.resources.service-orders.index')),
            Stat::make('Erros de NFS-e', $nfseErrors)
                ->description('Produção · requerem análise')
                ->descriptionIcon('heroicon-m-exclamation-triangle')
                ->color('danger')
                ->url(route('filament.admin.resources.service-orders.index')),
            Stat::make('NF-e em processamento', $nfeProcessing)
                ->description('Produção · aguardando SEFAZ')
                ->descriptionIcon('heroicon-m-arrow-path')
                ->color('warning')
                ->url(route('filament.admin.resources.sales.index')),
            Stat::make('NF-e autorizadas', $nfeAuthorized)
                ->description('Produção · emissões confirmadas')
                ->descriptionIcon('heroicon-m-check-circle')
                ->color('success')
                ->url(route('filament.admin.resources.sales.index')),
            Stat::make('Erros de NF-e', $nfeErrors)
                ->description('Produção · requerem análise')
                ->descriptionIcon('heroicon-m-exclamation-triangle')
                ->color('danger')
                ->url(route('filament.admin.resources.sales.index')),
        ];
    }
}
