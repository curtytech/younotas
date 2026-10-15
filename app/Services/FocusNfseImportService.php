<?php

namespace App\Services;

use App\Exceptions\FocusNfseRequestException;
use App\Models\FiscalConnection;
use App\Models\FiscalDocument;
use App\Models\ServiceOrder;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class FocusNfseImportService
{
    public function __construct(
        protected FocusApiClient $apiClient,
        protected FocusNfeConfigService $configService,
        protected FiscalDocumentWorkflow $workflow,
        protected FiscalAudit $audit,
    ) {}

    public function importByReference(User $user, string $reference, ?int $connectionId = null): array
    {
        $config = $this->configurationFor($user, $connectionId);
        $response = $this->apiClient->get($config, '/v2/nfse/'.urlencode($reference));
        if ($response->failed()) {
            throw new FocusNfseRequestException('Falha ao consultar NFS-e na Focus (HTTP '.$response->status().').', $response->status());
        }
        $data = FocusResponse::document($response);
        $issuer = preg_replace('/\D/', '', (string) ($data['cnpj_prestador'] ?? data_get($data, 'prestador.cnpj', '')));
        $expected = preg_replace('/\D/', '', (string) data_get($config, 'prestador.cnpj'));
        if ($issuer !== '' && $issuer !== $expected) {
            throw new RuntimeException('A NFS-e consultada pertence a outro emitente.');
        }

        DB::transaction(function () use ($user, $reference, $config, $data, $issuer, $expected): void {
            $serviceOrder = ServiceOrder::query()->where('company_id', $config['company_id'])
                ->where('focus_nfse_connection_id', $config['fiscal_connection_id'])
                ->where('focus_nfse_environment', $config['environment'])
                ->where('focus_nfse_ref', $reference)->first();
            $document = FiscalDocument::query()->firstOrCreate([
                'fiscal_connection_id' => $config['fiscal_connection_id'],
                'focus_reference' => $reference,
            ], [
                'user_id' => $user->id,
                'company_id' => $config['company_id'],
                'document_type' => 'NFS-e',
                'provider' => 'focus',
                'source_type' => $serviceOrder ? ServiceOrder::class : null,
                'source_id' => $serviceOrder?->id,
                'source_label' => $serviceOrder?->number ?? (filled($data['numero'] ?? null) ? 'NFS-e '.$data['numero'] : 'NFS-e importada'),
                'environment' => $config['environment'],
                'configuration_snapshot' => FiscalEmissionPreparation::snapshot($config),
                'issuer_document' => $issuer ?: $expected,
                'issued_at' => $data['data_emissao'] ?? null,
                'metadata' => ['origin' => 'focus_individual'],
                'imported_at' => now(),
                'issued_by' => auth()->id() ?? $user->id,
            ]);
            if ($document->company_id !== $config['company_id'] || $document->document_type !== 'NFS-e' || $document->environment !== $config['environment']) {
                throw new RuntimeException('A referência já está vinculada a outra identidade fiscal.');
            }
            $document = $this->workflow->applyResponse($document, $data, 'consulta');
            $document->update(['last_synced_at' => now()]);
            $this->audit->record($document, 'document_imported', ['origin' => 'focus_individual'], auth()->id() ?? $user->id);
        });

        return $data;
    }

    public function configurationFor(User $user, ?int $connectionId = null): array
    {
        if (! $connectionId) {
            return $this->configService->forUser($user);
        }
        $connection = FiscalConnection::findOrFail($connectionId);
        if (! $user->canAccessCompany($connection->company, 'issue')) {
            throw new AuthorizationException('Você não pode importar documentos desta empresa.');
        }

        return $this->configService->forConnection($connection);
    }
}
