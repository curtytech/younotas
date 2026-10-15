<?php

namespace App\Filament\Widgets;

use App\Models\Sale;
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
        $queryScope = static function ($query): void {
            if (auth()->user()->role !== 'admin') {
                $query->where('user_id', auth()->id());
            }
        };

        $nfseProcessing = ServiceOrder::query()->tap($queryScope)->where('focus_nfse_status', NfseStatus::PROCESSING)->count();
        $nfseAuthorized = ServiceOrder::query()->tap($queryScope)->where('focus_nfse_status', NfseStatus::AUTHORIZED)->count();
        $nfseErrors = ServiceOrder::query()->tap($queryScope)->whereIn('focus_nfse_status', [NfseStatus::AUTHORIZATION_ERROR, NfseStatus::TRANSPORT_ERROR])->count();

        $nfeProcessing = Sale::query()->tap($queryScope)->whereIn('focus_nfe_status', [NfeStatus::SENDING, NfeStatus::PROCESSING])->count();
        $nfeAuthorized = Sale::query()->tap($queryScope)->where('focus_nfe_status', NfeStatus::AUTHORIZED)->count();
        $nfeErrors = Sale::query()->tap($queryScope)->whereIn('focus_nfe_status', [NfeStatus::AUTHORIZATION_ERROR, NfeStatus::TRANSPORT_ERROR])->count();

        return [
            Stat::make('NFS-e em processamento', $nfseProcessing)
                ->description('Aguardando retorno da prefeitura')
                ->descriptionIcon('heroicon-m-arrow-path')
                ->color('warning')
                ->url(route('filament.admin.resources.service-orders.index')),
            Stat::make('NFS-e autorizadas', $nfseAuthorized)
                ->description('Emissões confirmadas')
                ->descriptionIcon('heroicon-m-check-circle')
                ->color('success')
                ->url(route('filament.admin.resources.service-orders.index')),
            Stat::make('Erros de NFS-e', $nfseErrors)
                ->description('Requerem análise ou reenvio')
                ->descriptionIcon('heroicon-m-exclamation-triangle')
                ->color('danger')
                ->url(route('filament.admin.resources.service-orders.index')),
            Stat::make('NF-e em processamento', $nfeProcessing)
                ->description('Aguardando retorno da SEFAZ')
                ->descriptionIcon('heroicon-m-arrow-path')
                ->color('warning')
                ->url(route('filament.admin.resources.sales.index')),
            Stat::make('NF-e autorizadas', $nfeAuthorized)
                ->description('Emissões confirmadas')
                ->descriptionIcon('heroicon-m-check-circle')
                ->color('success')
                ->url(route('filament.admin.resources.sales.index')),
            Stat::make('Erros de NF-e', $nfeErrors)
                ->description('Requerem análise ou reenvio')
                ->descriptionIcon('heroicon-m-exclamation-triangle')
                ->color('danger')
                ->url(route('filament.admin.resources.sales.index')),
        ];
    }
}
