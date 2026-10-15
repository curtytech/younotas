<?php

namespace App\Jobs;

use App\Actions\ConsultSaleNfeAction;
use App\Models\FiscalDocument;
use App\Models\Sale;
use App\Services\FiscalDocumentWorkflow;
use App\Services\FiscalEmissionPreparation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ConsultSaleNfeJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 10;
    public array $backoff = [60, 120, 300, 600];
    public int $uniqueFor = 7200;
    public int $documentId;
    public ?int $actorId;

    public function __construct(public int $saleId, ?int $documentId = null)
    {
        $this->documentId = $documentId ?? app(FiscalDocumentWorkflow::class)->resolve(Sale::findOrFail($saleId))->id;
        $this->actorId = auth()->id();
        app(FiscalEmissionPreparation::class)->authorize(FiscalDocument::findOrFail($this->documentId)->company_id, $this->actorId);
    }

    public function uniqueId(): string
    {
        return 'document:'.$this->documentId;
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('fiscal-consult:'.$this->documentId))->releaseAfter(60)->expireAfter(180)];
    }

    public function handle(ConsultSaleNfeAction $action): void
    {
        $document = FiscalDocument::findOrFail($this->documentId);
        app(FiscalEmissionPreparation::class)->authorize($document->company_id, $this->actorId);
        $action->execute($document);
        if (FiscalDocument::find($this->documentId)?->next_check_at) {
            $this->release(300);
        }
    }

    public function failed(Throwable $exception): void
    {
        if ($document = FiscalDocument::find($this->documentId)) {
            app(FiscalDocumentWorkflow::class)->recordFailure($document, $exception, 'consulta');
        }
    }
}
