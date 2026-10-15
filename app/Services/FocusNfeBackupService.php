<?php

namespace App\Services;

use App\Models\FiscalDocument;
use App\Models\FiscalConnection;
use App\Models\User;
use DOMDocument;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Auth\Access\AuthorizationException;
use RuntimeException;
use Throwable;
use ZipArchive;

class FocusNfeBackupService
{
    public function __construct(
        protected FocusApiClient $apiClient,
        protected FocusNfeConfigService $configService,
    ) {}

    public function listBackups(User $user, ?int $connectionId = null): array
    {
        $config = $this->configurationFor($user, $connectionId);
        $cnpj = $this->prestadorCnpj($user, $config);

        $response = $this->apiClient->get($config, '/v2/backups/'.$cnpj.'.json');

        if ($response->failed()) {
            throw new RuntimeException('Falha ao listar backups da Focus (HTTP '.$response->status().').');
        }

        return $response->json() ?? [];
    }

    public function importMonth(User $user, string $month, ?int $connectionId = null): array
    {
        if (! preg_match('/^\d{6}$/', $month)) {
            throw new RuntimeException('O mês do backup deve estar no formato AAAAMM.');
        }

        $config = $this->configurationFor($user, $connectionId);
        $cnpj = $this->prestadorCnpj($user, $config);

        $backup = collect($this->listBackups($user, $connectionId))
            ->first(fn (array $item): bool => ($item['mes'] ?? null) === $month);

        if (! $backup || blank($backup['xmls'] ?? null)) {
            throw new RuntimeException("Backup de XMLs não encontrado para {$month}.");
        }

        $zipPath = tempnam(sys_get_temp_dir(), 'focus-nfe-backup-');
        file_put_contents($zipPath, $this->apiClient->download($config, $backup['xmls']));

        $stats = ['imported' => 0, 'updated' => 0, 'ignored' => 0, 'failed' => 0, 'failed_messages' => []];

        try {
            $zip = new ZipArchive;

            if ($zip->open($zipPath) !== true) {
                throw new RuntimeException('ZIP de backup inválido.');
            }

            for ($index = 0; $index < $zip->numFiles; $index++) {
                $name = (string) $zip->getNameIndex($index);

                if (! str_ends_with(strtolower($name), '.xml')) {
                    continue;
                }

                try {
                    $xml = $zip->getFromIndex($index);

                    if ($xml === false) {
                        $stats['failed']++;

                        continue;
                    }

                    $stats[$this->importNfeXml($user, $config, $xml, $month)]++;
                } catch (Throwable $exception) {
                    $stats['failed']++;
                    $stats['failed_messages'][] = 'Não foi possível processar um XML do backup ('.$exception::class.').';
                }
            }

            $zip->close();
        } finally {
            @unlink($zipPath);
        }

        return $stats;
    }

    protected function importNfeXml(User $user, array $config, string $xml, string $month): string
    {
        $data = $this->parseNfeXml($xml);

        if (! $data) {
            return 'ignored';
        }

        $issuerCnpj = preg_replace('/\D/', '', (string) ($data['issuer_document'] ?? ''));
        $expectedCnpj = $this->prestadorCnpj($user, $config);

        if ($issuerCnpj !== '' && $issuerCnpj !== $expectedCnpj) {
            return 'ignored';
        }

        $environment = ($data['tp_amb'] ?? null) === '1' ? 'producao' : (($data['tp_amb'] ?? null) === '2' ? 'homologacao' : null);
        if (! $environment || $environment !== $config['environment']) {
            return 'ignored';
        }

        $isCancellation = ! empty($data['is_cancellation']);
        $xmlPath = 'fiscal/xmls/'.$config['company_id'].'/'.$environment.'/'.$data['access_key'].($isCancellation ? '-cancelamento' : '').'.xml';
        Storage::disk('local')->put($xmlPath, $xml);

        $attributes = [
            'user_id' => $user->id,
            'company_id' => $config['company_id'],
            'fiscal_connection_id' => $config['fiscal_connection_id'],
            'environment' => $environment,
            'configuration_snapshot' => FiscalEmissionPreparation::snapshot($config),
            'document_type' => 'NF-e',
            'provider' => 'focus',
            'source_label' => $data['number'] ?? null,
            'access_key' => $data['access_key'],
            'document_number' => $data['number'] ?? null,
            'series' => $data['series'] ?? null,
            'status' => $isCancellation ? 'cancelado' : 'autorizado',
            'issued_at' => $data['issued_at'] ?? null,
            'authorized_at' => $data['authorized_at'] ?? null,
            'issuer_document' => $data['issuer_document'],
            'recipient_document' => $data['recipient_document'] ?? null,
            'total_amount' => $data['total_amount'] ?? null,
            $isCancellation ? 'cancelation_xml_path' : 'xml_path' => $xmlPath,
            'payload_hash' => hash('sha256', $xml),
            'raw_response' => [$isCancellation ? 'cancelation_xml_path' : 'xml_path' => $xmlPath, 'month' => $month],
            'metadata' => ['origin' => 'focus_backup', 'month' => $month, 'tp_amb' => $data['tp_amb'], 'xml_sha256' => hash('sha256', $xml)],
            'imported_at' => now(),
            'last_synced_at' => now(),
        ];

        return DB::transaction(function () use ($attributes, $config, $environment, $data, $isCancellation): string {
            $existing = FiscalDocument::query()->where('company_id', $config['company_id'])->where('environment', $environment)
                ->where('access_key', $data['access_key'])->lockForUpdate()->first();
            $updates = $attributes;
            if ($existing) {
                if (in_array($existing->status, ['cancelado', 'denegado'], true) && ! $isCancellation) {
                    $updates['status'] = $existing->status;
                }
                $updates = array_filter($updates, static fn ($value): bool => $value !== null);
                // Import enriches an existing issuance; its payload, origin and connection remain intact.
                $updates['configuration_snapshot'] = $existing->configuration_snapshot ?: $updates['configuration_snapshot'];
                $updates['fiscal_connection_id'] = $existing->fiscal_connection_id ?: $updates['fiscal_connection_id'];
                $updates['metadata'] = array_replace($updates['metadata'], $existing->metadata ?? [], ['month' => $updates['metadata']['month'], 'xml_sha256' => $updates['metadata']['xml_sha256']]);
                $updates['raw_response'] = array_replace($existing->raw_response ?? [], $updates['raw_response']);
                $updates['payload_hash'] = $existing->payload_hash ?: $updates['payload_hash'];
                $existing->update($updates);
                app(FiscalDocumentWorkflow::class)->syncSource($existing);
                app(FiscalAudit::class)->record($existing, 'document_imported', ['origin' => 'focus_backup'], auth()->id());

                return 'updated';
            }
            $document = FiscalDocument::create($updates);
            app(FiscalAudit::class)->record($document, 'document_imported', ['origin' => 'focus_backup'], auth()->id());

            return 'imported';
        });
    }

    protected function parseNfeXml(string $xml): ?array
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadXML($xml, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded || ! $document->documentElement) {
            return null;
        }

        $root = $document->documentElement;
        $eventType = $this->text($root, 'tpEvento');
        if ($eventType === '110111') {
            $return = $this->firstByTag($root, 'retEvento');
            $accessKey = preg_replace('/\D/', '', (string) $this->text($root, 'chNFe'));
            if (! $return || ! in_array($this->text($return, 'cStat'), ['135', '155'], true) || strlen($accessKey) !== 44) {
                return null;
            }

            return ['access_key' => $accessKey, 'issuer_document' => substr($accessKey, 6, 14), 'tp_amb' => $this->text($root, 'tpAmb'), 'is_cancellation' => true];
        }
        $infNfe = $this->firstByTag($root, 'infNFe');
        $ide = $this->firstByTag($root, 'ide');
        $emit = $this->firstByTag($root, 'emit');
        $dest = $this->firstByTag($root, 'dest');
        $icmsTot = $this->firstByTag($this->firstByTag($root, 'total'), 'ICMSTot');

        $accessKey = preg_replace('/\D/', '', (string) ($infNfe?->getAttribute('Id') ?? ''));

        if (strlen($accessKey) !== 44) {
            return null;
        }

        $protocol = $this->firstByTag($root, 'protNFe');
        if (! $protocol || ! in_array($this->text($protocol, 'cStat'), ['100', '150'], true)) {
            return null;
        }

        return [
            'access_key' => $accessKey,
            'number' => $ide ? $this->text($ide, 'nNF') : null,
            'series' => $ide ? $this->text($ide, 'serie') : null,
            'tp_amb' => $ide ? $this->text($ide, 'tpAmb') : null,
            'issued_at' => $this->parseDateTime($ide ? $this->text($ide, 'dhEmi') ?: $this->text($ide, 'dEmi') : null),
            'authorized_at' => $this->parseDateTime($this->text($this->firstByTag($root, 'protNFe'), 'dhRecbto')),
            'issuer_document' => $emit ? ($this->text($emit, 'CNPJ') ?: $this->text($emit, 'CPF')) : null,
            'recipient_document' => $dest ? ($this->text($dest, 'CNPJ') ?: $this->text($dest, 'CPF')) : null,
            'total_amount' => $icmsTot ? $this->text($icmsTot, 'vNF') : null,
        ];
    }

    protected function firstByTag(?\DOMElement $parent, string $tag): ?\DOMElement
    {
        $node = $parent?->getElementsByTagName($tag)->item(0);

        return $node instanceof \DOMElement ? $node : null;
    }

    protected function text(?\DOMElement $parent, string $tag): ?string
    {
        $node = $parent?->getElementsByTagName($tag)->item(0);

        if (! $node) {
            return null;
        }

        $value = trim((string) $node->textContent);

        return $value !== '' ? $value : null;
    }

    protected function parseDateTime(?string $value): ?\DateTimeInterface
    {
        if (blank($value)) {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (Throwable) {
            return null;
        }
    }

    protected function prestadorCnpj(User $user, array $config): string
    {
        $cnpj = preg_replace('/\D/', '', (string) data_get($config, 'prestador.cnpj', $user->cnpj ?? ''));

        if (blank($cnpj)) {
            throw new RuntimeException('Configure o CNPJ do prestador na Configuração Fiscal antes de importar backups.');
        }

        return $cnpj;
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
