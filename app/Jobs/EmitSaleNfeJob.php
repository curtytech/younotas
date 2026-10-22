<?php

namespace App\Jobs;

use App\Actions\EmitSaleNfeAction;
use App\Models\FiscalDocument;
use App\Models\Sale;
use App\Services\FiscalEmissionPreparation;
use App\Services\FiscalDocumentWorkflow;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class EmitSaleNfeJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [30, 120];
    public int $uniqueFor = 600;
    public int $documentId;
    public ?int $actorId;

    public function __construct(public int $saleId, ?int $documentId = null, bool $reserved = false)
    {
        $this->documentId = $documentId ? ($reserved ? app(FiscalDocumentWorkflow::class)->resolve(FiscalDocument::findOrFail($documentId))->id : app(FiscalEmissionPreparation::class)->reserveRetry(FiscalDocument::findOrFail($documentId), auth()->id())->id) : app(FiscalEmissionPreparation::class)
            ->reserve(Sale::findOrFail($saleId), auth()->id())->id;
        $this->actorId = auth()->id() ?? ($reserved ? FiscalDocument::findOrFail($this->documentId)->issued_by : null);
    }

    public function uniqueId(): string
    {
        return 'document:'.$this->documentId;
    }

    public function handle(EmitSaleNfeAction $action): void
    {
        $document = FiscalDocument::findOrFail($this->documentId);
        app(FiscalEmissionPreparation::class)->authorize($document->company_id, $this->actorId);
        $action->execute($document);
    }

    public function failed(Throwable $exception): void
    {
        if ($document = FiscalDocument::find($this->documentId)) {
            app(FiscalDocumentWorkflow::class)->recordFailure($document, $exception, 'emissao');
        }
    }
}
