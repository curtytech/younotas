<?php

namespace App\Console\Commands;

use App\Models\FiscalDocument;
use App\Models\Sale;
use App\Models\ServiceOrder;
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

        Sale::query()
            ->where(fn ($query) => $query->whereNotNull('focus_nfe_ref')->orWhereNotNull('focus_nfe_status'))
            ->each(function (Sale $sale) use (&$count): void {
                FiscalDocument::updateOrCreate(
                    ['user_id' => $sale->user_id, 'source_type' => Sale::class, 'source_id' => $sale->id],
                    [
                        'document_type' => 'NF-e',
                        'provider' => 'focus',
                        'source_label' => $sale->number,
                        'focus_reference' => $sale->focus_nfe_ref,
                        'document_number' => $sale->focus_nfe_number,
                        'status' => $sale->focus_nfe_status,
                        'issued_at' => $sale->sale_date,
                        'document_url' => $sale->focus_nfe_url,
                        'raw_response' => $sale->focus_nfe_response_secure,
                        'metadata' => array_replace($sale->focus_nfe_payload ?? [], ['origin' => 'emitted']),
                        'last_sent_at' => $sale->focus_nfe_last_sent_at,
                        'last_checked_at' => $sale->focus_nfe_last_checked_at,
                        'last_webhook_at' => $sale->focus_nfe_last_webhook_at,
                        'imported_at' => Carbon::now(),
                    ],
                );
                $count++;
            });

        $this->info("Backfill NF-e: {$count} documentos.");
    }

    private function backfillServiceOrders(): void
    {
        $count = 0;

        ServiceOrder::query()
            ->where(fn ($query) => $query->whereNotNull('focus_nfse_ref')->orWhereNotNull('focus_nfse_status'))
            ->each(function (ServiceOrder $serviceOrder) use (&$count): void {
                FiscalDocument::updateOrCreate(
                    ['user_id' => $serviceOrder->user_id, 'source_type' => ServiceOrder::class, 'source_id' => $serviceOrder->id],
                    [
                        'document_type' => 'NFS-e',
                        'provider' => 'focus',
                        'source_label' => $serviceOrder->number,
                        'focus_reference' => $serviceOrder->focus_nfse_ref,
                        'document_number' => $serviceOrder->focus_nfse_number,
                        'status' => $serviceOrder->focus_nfse_status,
                        'issued_at' => $serviceOrder->completed_at,
                        'document_url' => $serviceOrder->focus_nfse_url,
                        'raw_response' => $serviceOrder->focus_nfse_response_secure,
                        'metadata' => array_replace($serviceOrder->focus_nfse_payload ?? [], ['origin' => 'emitted']),
                        'last_sent_at' => $serviceOrder->focus_nfse_last_sent_at,
                        'last_checked_at' => $serviceOrder->focus_nfse_last_checked_at,
                        'last_webhook_at' => $serviceOrder->focus_nfse_last_webhook_at,
                        'imported_at' => Carbon::now(),
                    ],
                );
                $count++;
            });

        $this->info("Backfill NFS-e: {$count} documentos.");
    }
}
