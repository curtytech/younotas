<?php

namespace App\Services;

use App\Models\FiscalDocument;
use App\Models\Sale;
use App\Models\ServiceOrder;

/**
 * Mantém um registro em fiscal_documents por referência Focus. Cada reemissão com
 * nova referência gera um novo registro, preservando o histórico da nota anterior.
 */
class FiscalDocumentRecorder
{
    public function recordSale(Sale $sale, array $extra = []): ?FiscalDocument
    {
        if (blank($sale->focus_nfe_ref)) {
            return null;
        }

        return FiscalDocument::updateOrCreate(
            ['user_id' => $sale->user_id, 'focus_reference' => $sale->focus_nfe_ref],
            array_replace([
                'document_type' => 'NF-e',
                'provider' => 'focus',
                'source_type' => Sale::class,
                'source_id' => $sale->id,
                'source_label' => $sale->number,
                'document_number' => $sale->focus_nfe_number,
                'status' => $sale->focus_nfe_status,
                'issued_at' => $sale->sale_date,
                'total_amount' => $sale->total_amount,
                'document_url' => $sale->focus_nfe_url,
                'raw_response' => $sale->focus_nfe_response_secure,
                'metadata' => array_replace($sale->focus_nfe_payload ?? [], ['origin' => 'emitted']),
                'last_sent_at' => $sale->focus_nfe_last_sent_at,
                'last_checked_at' => $sale->focus_nfe_last_checked_at,
                'last_webhook_at' => $sale->focus_nfe_last_webhook_at,
                'last_error' => $sale->focus_nfe_error,
            ], $extra),
        );
    }

    public function recordServiceOrder(ServiceOrder $serviceOrder, array $extra = []): ?FiscalDocument
    {
        if (blank($serviceOrder->focus_nfse_ref)) {
            return null;
        }

        return FiscalDocument::updateOrCreate(
            ['user_id' => $serviceOrder->user_id, 'focus_reference' => $serviceOrder->focus_nfse_ref],
            array_replace([
                'document_type' => 'NFS-e',
                'provider' => 'focus',
                'source_type' => ServiceOrder::class,
                'source_id' => $serviceOrder->id,
                'source_label' => $serviceOrder->number,
                'document_number' => $serviceOrder->focus_nfse_number,
                'status' => $serviceOrder->focus_nfse_status,
                'issued_at' => $serviceOrder->completed_at,
                'total_amount' => $serviceOrder->total_amount,
                'document_url' => $serviceOrder->focus_nfse_url,
                'raw_response' => $serviceOrder->focus_nfse_response_secure,
                'metadata' => array_replace($serviceOrder->focus_nfse_payload ?? [], ['origin' => 'emitted']),
                'last_sent_at' => $serviceOrder->focus_nfse_last_sent_at,
                'last_checked_at' => $serviceOrder->focus_nfse_last_checked_at,
                'last_webhook_at' => $serviceOrder->focus_nfse_last_webhook_at,
                'last_error' => $serviceOrder->focus_nfse_error,
            ], $extra),
        );
    }
}
