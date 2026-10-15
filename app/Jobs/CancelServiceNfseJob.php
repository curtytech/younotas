<?php

namespace App\Jobs;

use App\Actions\CancelServiceNfseAction;
use App\Models\FiscalDocument;
use App\Models\ServiceOrder;
use App\Services\FiscalDocumentWorkflow;
use App\Services\FiscalEmissionPreparation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class CancelServiceNfseJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [30, 120];
    public int $uniqueFor = 600;
    public int $documentId;
    public ?int $actorId;

    public function __construct(public int $serviceId, public string $justification, ?int $documentId = null)
    {
        $this->documentId = $documentId ?? app(FiscalDocumentWorkflow::class)->resolve(ServiceOrder::findOrFail($serviceId))->id;
        $this->actorId = auth()->id();
        app(FiscalEmissionPreparation::class)->authorize(FiscalDocument::findOrFail($this->documentId)->company_id, $this->actorId);
    }

    public function uniqueId(): string
    {
        return 'document:'.$this->documentId;
    }

    public function handle(CancelServiceNfseAction $action): void
    {
        $document = FiscalDocument::findOrFail($this->documentId);
        app(FiscalEmissionPreparation::class)->authorize($document->company_id, $this->actorId);
        $action->execute($document, $this->justification);
    }

    public function failed(Throwable $exception): void
    {
        if ($document = FiscalDocument::find($this->documentId)) {
            app(FiscalDocumentWorkflow::class)->recordFailure($document, $exception, 'cancelamento');
        }
    }
}
