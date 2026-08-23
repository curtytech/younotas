<?php

namespace App\Services;

use App\Models\FiscalDocument;
use App\Models\ServiceOrder;
use App\Models\User;
use Carbon\Carbon;
use RuntimeException;

class FocusNfseImportService
{
    public function __construct(
        protected FocusApiClient $apiClient,
        protected FocusNfeConfigService $configService,
    ) {}

    public function importByReference(User $user, string $reference): array
    {
        $config = $this->configService->forUser($user);

        $response = $this->apiClient->get($config, '/v2/nfse/'.urlencode($reference));

        if ($response->failed()) {
            throw new RuntimeException($response->json('mensagem') ?? 'Falha ao consultar NFS-e na Focus.');
        }

        $data = $response->json() ?? [];

        if (($data['status'] ?? null) !== 'autorizado') {
            throw new RuntimeException('A NFS-e ainda não está autorizada para importação.');
        }

        $serviceOrder = ServiceOrder::query()->where('focus_nfse_ref', $reference)->first();

        $attributes = [
            'user_id' => $user->id,
            'document_type' => 'NFS-e',
            'provider' => 'focus',
            'source_type' => $serviceOrder ? ServiceOrder::class : null,
            'source_id' => $serviceOrder?->id,
            'source_label' => $serviceOrder?->number ?? ($data['numero'] ? 'NFS-e '.$data['numero'] : 'NFS-e importada'),
            'focus_reference' => $reference,
            'document_number' => $data['numero'] ?? null,
            'status' => 'autorizado',
            'issued_at' => isset($data['data_emissao']) ? Carbon::parse($data['data_emissao']) : now(),
            'issuer_document' => preg_replace('/\D/', '', (string) ($data['cnpj_prestador'] ?? '')),
            'document_url' => $data['url'] ?? $data['url_danfse'] ?? null,
            'raw_response' => $data,
            'metadata' => ['origin' => 'focus_individual'],
            'imported_at' => now(),
            'last_synced_at' => now(),
        ];

        FiscalDocument::withTrashed()->updateOrCreate(
            ['user_id' => $user->id, 'focus_reference' => $reference],
            $attributes,
        );

        return $data;
    }
}
