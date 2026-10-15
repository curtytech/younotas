<?php

namespace App\Console\Commands;

use App\Models\Sale;
use App\Models\ServiceOrder;
use App\Services\FiscalDocumentRecorder;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class BackfillFiscalDocuments extends Command
{
    protected $signature = 'fiscal:backfill';

    protected $description = 'Cria registros em fiscal_documents a partir de vendas e ordens de serviço já emitidas.';

    public function handle(): int
    {
        $this->backfillSales();
        $this->backfillServiceOrders();

        return self::SUCCESS;
    }

    private function backfillSales(): void
    {
        $count = 0;
        $recorder = app(FiscalDocumentRecorder::class);

        Sale::query()
            ->whereNotNull('focus_nfe_ref')
            ->each(function (Sale $sale) use (&$count, $recorder): void {
                $recorder->recordSale($sale, ['imported_at' => Carbon::now()]);
                $count++;
            });

        $this->info("Backfill NF-e: {$count} documentos.");
    }

    private function backfillServiceOrders(): void
    {
        $count = 0;
        $recorder = app(FiscalDocumentRecorder::class);

        ServiceOrder::query()
            ->whereNotNull('focus_nfse_ref')
            ->each(function (ServiceOrder $serviceOrder) use (&$count, $recorder): void {
                $recorder->recordServiceOrder($serviceOrder, ['imported_at' => Carbon::now()]);
                $count++;
            });

        $this->info("Backfill NFS-e: {$count} documentos.");
    }
}
