<?php

namespace App\Services;

use App\Jobs\ConsultSaleNfeJob;
use App\Jobs\ConsultServiceNfseJob;
use App\Models\FiscalDocument;
use App\Models\Sale;
use App\Models\ServiceOrder;
use App\Support\NfeStatus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class FiscalEmissionService
{
    public function __construct(
        protected FiscalEmissionPreparation $preparation,
        protected FiscalDocumentWorkflow $workflow,
        protected FocusNfeConfigService $configService,
        protected FocusNfeService $nfe,
        protected FocusNfseService $nfse,
        protected FiscalAudit $audit,
    ) {}

    public function execute(Sale|ServiceOrder|FiscalDocument $subject): array
    {
        $document = $subject instanceof FiscalDocument ? $this->workflow->resolve($subject) : $this->preparation->reserve($subject, auth()->id());
        $this->preparation->authorize($document->company_id, auth()->id());
        $lock = Cache::lock('fiscal-emission:'.$document->id, 180);
        if (! $lock->get()) {
            throw new RuntimeException('Esta emissão já está sendo processada. Aguarde a consulta automática.');
        }

        try {
            $document->refresh();
            if (in_array($document->status, [NfeStatus::PROCESSING, NfeStatus::AUTHORIZED, NfeStatus::CANCELED, NfeStatus::DENIED], true)) {
                $this->scheduleConsult($document);

                return $document->raw_response ?? [];
            }
            $service = $document->document_type === 'NF-e' ? $this->nfe : $this->nfse;
            if ($document->last_sent_at) {
                try {
                    $response = $service->consult($document);
                    $document = $this->workflow->applyResponse($document, $response, 'consulta');
                    if (! in_array($document->status, [NfeStatus::AUTHORIZATION_ERROR, NfeStatus::TRANSPORT_ERROR], true)) {
                        $this->scheduleConsult($document);

                        return $response;
                    }
                } catch (Throwable $exception) {
                    if (! property_exists($exception, 'status') || $exception->status !== 404) {
                        $this->workflow->recordFailure($document, $exception, 'consulta');
                        throw $exception;
                    }
                }
            }

            try {
                $config = $this->configService->forDocument($document);
                $source = $document->source_type::query()->findOrFail($document->source_id);
                $metadata = $document->metadata ?? [];
                $payload = $metadata['focus_payload'] ?? $service->buildPayload($source, $config);
            } catch (Throwable $exception) {
                $this->workflow->recordFailure($document, $exception, 'emissao');
                throw $exception;
            }

            $document = DB::transaction(function () use ($document, $payload): FiscalDocument {
                $document = FiscalDocument::query()->lockForUpdate()->findOrFail($document->id);
                if (in_array($document->status, [NfeStatus::PROCESSING, NfeStatus::AUTHORIZED, NfeStatus::CANCELED, NfeStatus::DENIED], true)) {
                    return $document;
                }
                $metadata = $document->metadata ?? [];
                $metadata['focus_payload'] = $payload;
                $metadata['focus_attempts'] = (int) ($metadata['focus_attempts'] ?? 0) + 1;
                $document->update([
                    'status' => NfeStatus::SENDING,
                    'metadata' => $metadata,
                    'payload_hash' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)),
                    'last_sent_at' => now(),
                    'next_check_at' => now()->addMinutes(5),
                ]);
                $this->workflow->syncSource($document);
                $this->audit->record($document, 'emission_started', ['attempt' => $metadata['focus_attempts']]);

                return $document;
            });

            if ($document->status !== NfeStatus::SENDING) {
                $this->scheduleConsult($document);

                return $document->raw_response ?? ['status' => $document->status];
            }

            try {
                $response = $service->emit($config, $document->focus_reference, $payload);
            } catch (Throwable $exception) {
                $document->refresh();
                if (in_array($document->status, [NfeStatus::AUTHORIZED, NfeStatus::CANCELED, NfeStatus::DENIED], true)) {
                    return $document->raw_response ?? ['status' => $document->status];
                }
                if (FiscalDocumentWorkflow::isAmbiguous($exception) || method_exists($exception, 'mayHaveBeenProcessed') && $exception->mayHaveBeenProcessed()) {
                    try {
                        $response = $service->consult($document);
                        $document = $this->workflow->applyResponse($document, $response, 'consulta');
                        $this->scheduleConsult($document);

                        return $response;
                    } catch (Throwable) {
                        // The original identity and payload remain available for reconciliation.
                    }
                }
                $this->workflow->recordFailure($document, $exception, 'emissao');
                throw $exception;
            }

            $document = $this->workflow->applyResponse($document, $response, 'emissao');
            $this->scheduleConsult($document);

            return $response;
        } finally {
            $lock->release();
        }
    }

    protected function scheduleConsult(FiscalDocument $document): void
    {
        if (! in_array($document->status, [NfeStatus::SENDING, NfeStatus::PROCESSING], true)) {
            return;
        }
        if ($document->document_type === 'NF-e') {
            ConsultSaleNfeJob::dispatch((int) $document->source_id, $document->id)->delay(now()->addMinute());
        } else {
            ConsultServiceNfseJob::dispatch((int) $document->source_id, $document->id)->delay(now()->addMinute());
        }
    }
}
