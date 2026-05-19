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
        $isAdmin = auth()->user()->role === 'admin';
        $userId = auth()->id();

        $clientCount = $isAdmin ? Client::count() : Client::where('user_id', $userId)->count();
        $productCount = $isAdmin ? Product::count() : Product::where('user_id', $userId)->count();
        $serviceCount = $isAdmin ? Service::count() : Service::where('user_id', $userId)->count();
        $saleCount = $isAdmin ? Sale::count() : Sale::where('user_id', $userId)->count();

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
