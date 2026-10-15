<?php

namespace App\Filament\Widgets;

use App\Models\Client;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Service;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class StatsOverview extends BaseWidget
{
    protected function getStats(): array
    {
        $user = auth()->user();
        $clientCount = Client::query()->visibleTo($user)->count();
        $productCount = Product::query()->visibleTo($user)->count();
        $serviceCount = Service::query()->visibleTo($user)->count();
        $saleCount = Sale::query()->visibleTo($user)->count();

        return [
            Stat::make('Clientes', $clientCount)
                ->description('Clientes cadastrados')
                ->descriptionIcon('heroicon-m-users')
                ->color('success'),

            Stat::make('Produtos', $productCount)
                ->description('Produtos cadastrados')
                ->descriptionIcon('heroicon-m-cube')
                ->color('primary'),

            Stat::make('Serviços', $serviceCount)
                ->description('Serviços cadastrados')
                ->descriptionIcon('heroicon-m-wrench-screwdriver')
                ->color('warning'),

            Stat::make('Vendas', $saleCount)
                ->description('Vendas registradas')
                ->descriptionIcon('heroicon-m-shopping-cart')
                ->color('warning'),
        ];
    }
}
