<?php

namespace App\Services;

use App\Models\FiscalDocument;
use App\Models\User;
use DOMDocument;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;
use ZipArchive;

class FocusNfeBackupService
{
    public function __construct(
        protected FocusApiClient $apiClient,
        protected FocusNfeConfigService $configService,
    ) {}

    public function listBackups(User $user): array
    {
        $config = $this->configService->forUser($user);
        $cnpj = $this->prestadorCnpj($user, $config);

        $response = $this->apiClient->get($config, '/v2/backups/'.$cnpj.'.json');

        if ($response->failed()) {
            throw new RuntimeException($response->json('mensagem') ?? 'Falha ao listar backups da Focus.');
        }

        return $response->json() ?? [];
    }

    public function importMonth(User $user, string $month): array
    {
        if (! preg_match('/^\d{6}$/', $month)) {
            throw new RuntimeException('O mês do backup deve estar no formato AAAAMM.');
        }

        $config = $this->configService->forUser($user);
        $cnpj = $this->prestadorCnpj($user, $config);

        $backup = collect($this->listBackups($user))
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

                if (! str_ends_with($name, '-nfe.xml')) {
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
                    $stats['failed_messages'][] = $name.': '.$exception->getMessage();
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

        $xmlPath = 'fiscal/xmls/'.$user->id.'/'.$data['access_key'].'.xml';
        Storage::disk('local')->put($xmlPath, $xml);

        $attributes = [
            'user_id' => $user->id,
            'document_type' => 'NF-e',
            'provider' => 'focus',
            'source_label' => $data['number'],
            'access_key' => $data['access_key'],
            'document_number' => $data['number'],
            'series' => $data['series'],
            'status' => 'autorizado',
            'issued_at' => $data['issued_at'],
            'authorized_at' => $data['authorized_at'],
            'issuer_document' => $data['issuer_document'],
            'recipient_document' => $data['recipient_document'],
            'total_amount' => $data['total_amount'],
            'xml_path' => $xmlPath,
            'payload_hash' => hash('sha256', $xml),
            'raw_response' => ['xml_path' => $xmlPath, 'month' => $month],
            'metadata' => ['origin' => 'focus_backup', 'month' => $month, 'tp_amb' => $data['tp_amb']],
            'imported_at' => now(),
            'last_synced_at' => now(),
        ];

        $existing = FiscalDocument::withTrashed()
            ->where('user_id', $user->id)
            ->where('access_key', $data['access_key'])
            ->first();

        if ($existing) {
            $existing->update($attributes);

            return 'updated';
        }

        FiscalDocument::create($attributes);

        return 'imported';
    }

    protected function parseNfeXml(string $xml): ?array
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadXML($xml);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded || ! $document->documentElement) {
            return null;
        }

        $root = $document->documentElement;
        $infNfe = $this->firstByTag($root, 'infNFe');
        $ide = $this->firstByTag($root, 'ide');
        $emit = $this->firstByTag($root, 'emit');
        $dest = $this->firstByTag($root, 'dest');
        $icmsTot = $this->firstByTag($this->firstByTag($root, 'total'), 'ICMSTot');

        $accessKey = preg_replace('/\D/', '', (string) ($infNfe?->getAttribute('Id') ?? ''));

        if (strlen($accessKey) !== 44) {
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

    protected function firstByTag(\DOMElement $parent, string $tag): ?\DOMElement
    {
        $node = $parent->getElementsByTagName($tag)->item(0);

        return $node instanceof \DOMElement ? $node : null;
    }

    protected function text(\DOMElement $parent, string $tag): ?string
    {
        $node = $parent->getElementsByTagName($tag)->item(0);

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
}
