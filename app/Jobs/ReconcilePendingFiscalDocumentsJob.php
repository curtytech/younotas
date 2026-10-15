<?php

namespace App\Jobs;

use App\Models\FiscalDocument;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ReconcilePendingFiscalDocumentsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $uniqueFor = 240;

    public function uniqueId(): string
    {
        return 'pending-fiscal-documents';
    }

    public function handle(): void
    {
        FiscalDocument::query()->whereNotNull('fiscal_connection_id')->whereNotNull('environment')
            ->whereNotNull('next_check_at')->where('next_check_at', '<=', now())
            ->orderBy('next_check_at')->limit(100)->get()->each(function (FiscalDocument $document): void {
                // Advance the lease before enqueueing, so older unresolved documents cannot starve newer ones.
                $document->update(['next_check_at' => now()->addMinutes(5)]);
                if ($document->status === 'enviando' && ! $document->last_sent_at && $document->isLinked()) {
                    if ($document->document_type === 'NF-e') {
                        EmitSaleNfeJob::dispatch((int) $document->source_id, $document->id, true);
                    } elseif ($document->document_type === 'NFS-e') {
                        EmitServiceNfseJob::dispatch((int) $document->source_id, $document->id, true);
                    }

                    return;
                }
                if ($document->document_type === 'NF-e') {
                    ConsultSaleNfeJob::dispatch((int) $document->source_id, $document->id);
                } elseif ($document->document_type === 'NFS-e') {
                    ConsultServiceNfseJob::dispatch((int) $document->source_id, $document->id);
                }
            });
    }
}
