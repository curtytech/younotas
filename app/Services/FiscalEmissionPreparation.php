<?php

namespace App\Services;

use App\Models\Company;
use App\Models\FiscalDocument;
use App\Models\FiscalConnection;
use App\Models\Sale;
use App\Models\ServiceOrder;
use App\Models\User;
use App\Support\NfeStatus;
use App\Support\NfseStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use RuntimeException;

/** Reserves the fiscal identity before enqueueing; never calls the provider. */
class FiscalEmissionPreparation
{
    public function __construct(protected FocusNfeConfigService $configService, protected FiscalAudit $audit) {}

    public function canReserve(Sale|ServiceOrder $source): bool
    {
        if (! $source->company_id) {
            return false;
        }
        $prefix = $source instanceof Sale ? 'focus_nfe_' : 'focus_nfse_';
        if (filled($source->{$prefix.'ref'}) && (blank($source->{$prefix.'connection_id'}) || blank($source->{$prefix.'environment'}))) {
            return false;
        }
        try {
            $config = $source->company_id
                ? $this->configService->forCompany(Company::findOrFail($source->company_id))
                : $this->configService->forUser($source->user);
        } catch (\Throwable) {
            return false;
        }
        if (blank($config['fiscal_connection_id'] ?? null) || blank($config['api_key'] ?? null)) {
            return false;
        }
        $document = FiscalDocument::query()->where('source_type', $source::class)->where('source_id', $source->id)
            ->where('fiscal_connection_id', $config['fiscal_connection_id'])->where('environment', $config['environment'])->latest('id')->first();
        $statusClass = $source instanceof Sale ? NfeStatus::class : NfseStatus::class;

        return ! $document || $statusClass::canEmit($document->status);
    }

    public function reserve(Sale|ServiceOrder $source, ?int $actorId = null): FiscalDocument
    {
        return DB::transaction(function () use ($source, $actorId): FiscalDocument {
            $source = $source->newQuery()->lockForUpdate()->findOrFail($source->id);
            $prefix = $source instanceof Sale ? 'focus_nfe_' : 'focus_nfse_';
            $statusClass = $source instanceof Sale ? NfeStatus::class : NfseStatus::class;
            $config = $source->company_id
                ? $this->configService->forCompany(Company::findOrFail($source->company_id))
                : $this->configService->forUser($source->user);

            if (blank($config['fiscal_connection_id'] ?? null) || blank($config['api_key'] ?? null)) {
                throw new RuntimeException('Configure e habilite a conexão fiscal da empresa para este ambiente antes de emitir.');
            }
            $this->authorize($config['company_id'], $actorId);

            if (filled($source->{$prefix.'ref'}) && (blank($source->{$prefix.'connection_id'}) || blank($source->{$prefix.'environment'}))) {
                throw new RuntimeException('A emissão anterior não possui ambiente confirmado. Reconcilie sua origem antes de emitir novamente.');
            }

            $document = FiscalDocument::query()
                ->where('source_type', $source::class)->where('source_id', $source->id)
                ->where('fiscal_connection_id', $config['fiscal_connection_id'])
                ->where('environment', $config['environment'])
                ->lockForUpdate()->latest('id')->first();

            // Repeated clicks share the reserved intent rather than generating a new reference.
            if ($document?->status === NfeStatus::SENDING && ! $document->last_sent_at) {
                return $document;
            }

            if ($document && ! $statusClass::canEmit($document->status)) {
                throw new RuntimeException('A nota deste ambiente não pode ser emitida no estado atual.');
            }

            $newReference = ! $document || $statusClass::requiresNewReference($document->status);
            $reusePayload = $document && $document->status === NfeStatus::TRANSPORT_ERROR
                && $statusClass::isAmbiguousFailure($document->last_error);
            $snapshot = $reusePayload ? $document->configuration_snapshot : self::snapshot($config);
            $reference = $newReference
                ? ($source instanceof Sale ? 'sale' : 'nfse').$source->id.Str::lower(Str::random(24))
                : $document->focus_reference;
            $metadata = $newReference ? ['origin' => 'emitted'] : ($document->metadata ?? []);
            $metadata['focus_attempts'] = (int) ($metadata['focus_attempts'] ?? 0);
            if (! $reusePayload) {
                unset($metadata['focus_payload']);
            }

            $source->forceFill([
                $prefix.'connection_id' => $config['fiscal_connection_id'],
                $prefix.'environment' => $config['environment'],
                $prefix.'configuration_snapshot' => $snapshot,
                $prefix.'ref' => $reference,
                $prefix.'status' => NfeStatus::SENDING,
                $prefix.'number' => $newReference ? null : $document->document_number,
                $prefix.'url' => $newReference ? null : $document->document_url,
                $prefix.'response_secure' => $newReference ? null : $document->raw_response,
                $prefix.'payload' => $reusePayload ? ($metadata['focus_payload'] ?? null) : null,
                $prefix.'error' => $reusePayload ? $document->last_error : null,
                $prefix.'last_sent_at' => $newReference ? null : $document->last_sent_at,
                $prefix.'last_checked_at' => $newReference ? null : $document->last_checked_at,
                $prefix.'last_webhook_at' => $newReference ? null : $document->last_webhook_at,
            ])->save();

            $reserved = FiscalDocument::updateOrCreate([
                'fiscal_connection_id' => $config['fiscal_connection_id'],
                'focus_reference' => $reference,
            ], [
                'user_id' => $source->user_id,
                'company_id' => $config['company_id'],
                'document_type' => $source instanceof Sale ? 'NF-e' : 'NFS-e',
                'provider' => 'focus',
                'source_type' => $source::class,
                'source_id' => $source->id,
                'source_label' => $source->number,
                'environment' => $config['environment'],
                'configuration_snapshot' => $snapshot,
                'status' => NfeStatus::SENDING,
                'total_amount' => $source->total_amount,
                'metadata' => $metadata,
                'last_error' => $reusePayload ? $document->last_error : null,
                'next_check_at' => now()->addMinutes(5),
                'issued_by' => $actorId ?? $document?->issued_by,
            ]);
            $this->audit->record($reserved, 'emission_queued', ['reference' => $reference, 'environment' => $config['environment']], $actorId);

            return $reserved;
        });
    }

    public static function snapshot(array $config): array
    {
        return FocusNfeConfigService::sanitizeSnapshot($config);
    }

    public function reserveRetry(FiscalDocument $document, ?int $actorId = null): FiscalDocument
    {
        return DB::transaction(function () use ($document, $actorId): FiscalDocument {
            $document = app(FiscalDocumentWorkflow::class)->resolve($document);
            $document = FiscalDocument::query()->lockForUpdate()->findOrFail($document->id);
            $this->authorize($document->company_id, $actorId);
            if (! in_array($document->status, [NfeStatus::AUTHORIZATION_ERROR, NfeStatus::TRANSPORT_ERROR], true)
                || ! in_array($document->source_type, [Sale::class, ServiceOrder::class], true)) {
                throw new RuntimeException('Este documento não permite reenvio.');
            }
            $connection = FiscalConnection::findOrFail($document->fiscal_connection_id);
            $config = $this->configService->forConnection($connection);
            if ((int) $config['company_id'] !== (int) $document->company_id || $config['environment'] !== $document->environment) {
                throw new RuntimeException('A conexão não corresponde à identidade original do documento.');
            }
            $ambiguous = $document->status === NfeStatus::TRANSPORT_ERROR && NfseStatus::isAmbiguousFailure($document->last_error);
            $metadata = $document->metadata ?? [];
            if (! $ambiguous) {
                unset($metadata['focus_payload']);
            }
            $document->update([
                'status' => NfeStatus::SENDING,
                'configuration_snapshot' => $ambiguous ? $document->configuration_snapshot : self::snapshot($config),
                'metadata' => $metadata,
                'last_error' => $ambiguous ? $document->last_error : null,
                'next_check_at' => now()->addMinutes(5),
                'issued_by' => $actorId ?? $document->issued_by,
            ]);
            $source = $document->source_type::query()->find($document->source_id);
            $prefix = $source instanceof Sale ? 'focus_nfe_' : 'focus_nfse_';
            if ($source && $source->{$prefix.'ref'} === $document->focus_reference
                && (int) $source->{$prefix.'connection_id'} === (int) $document->fiscal_connection_id) {
                $source->forceFill([$prefix.'configuration_snapshot' => $document->configuration_snapshot])->save();
            }
            app(FiscalDocumentWorkflow::class)->syncSource($document);
            $this->audit->record($document, 'emission_queued', ['reference' => $document->focus_reference, 'environment' => $document->environment], $actorId);

            return $document->fresh();
        });
    }

    public function authorize(int $companyId, ?int $actorId): void
    {
        if ($actorId && ! User::findOrFail($actorId)->canAccessCompany(Company::findOrFail($companyId), 'issue')) {
            throw new AuthorizationException('Você não pode emitir documentos desta empresa.');
        }
    }
}
