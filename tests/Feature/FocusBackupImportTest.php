<?php

use App\Models\Client;
use App\Models\FiscalDocument;
use App\Models\ServiceOrder;
use App\Models\User;
use App\Services\FocusNfeBackupService;
use App\Services\FocusNfseImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function focusUser(): User
{
    $user = User::create([
        'name' => 'Empresa Import', 'email' => uniqid().'@import.local',
        'password' => bcrypt('password'), 'role' => 'enterprise',
        'cnpj' => '28480405000193',
    ]);
    $user->focusNfeSetting()->create(['settings' => [
        'api_key' => 'token-import', 'base_url' => 'https://homologacao.focusnfe.com.br',
        'prestador' => ['cnpj' => '28480405000193'],
    ]]);

    return $user;
}

function nfeXml(string $accessKey, string $issuerCnpj = '28480405000193'): string
{
    return <<<XML
    <nfeProc xmlns="http://www.portalfiscal.inf.br/nfe" versao="4.00">
      <NFe>
        <infNFe Id="NFe{$accessKey}" versao="4.00">
          <ide>
            <cUF>35</cUF><nNF>1504</nNF><serie>1</serie>
            <dhEmi>2026-01-10T10:00:00-03:00</dhEmi><tpAmb>1</tpAmb>
          </ide>
          <emit><CNPJ>{$issuerCnpj}</CNPJ><xNome>EMITENTE LTDA</xNome></emit>
          <dest><CNPJ>04252011000110</CNPJ><xNome>DESTINATARIO LTDA</xNome></dest>
          <total><ICMSTot><vNF>1500.00</vNF></ICMSTot></total>
        </infNFe>
      </NFe>
      <protNFe versao="4.00">
        <infProt><dhRecbto>2026-01-10T10:01:00-03:00</dhRecbto><nProt>135260000000001</nProt><cStat>100</cStat></infProt>
      </protNFe>
    </nfeProc>
    XML;
}

function zipWithNfeXmls(array $files): string
{
    $zipPath = tempnam(sys_get_temp_dir(), 'focus-test-');
    $zip = new ZipArchive;
    $zip->open($zipPath, ZipArchive::OVERWRITE);

    foreach ($files as $name => $content) {
        $zip->addFromString($name, $content);
    }

    $zip->close();
    $content = (string) file_get_contents($zipPath);
    @unlink($zipPath);

    return $content;
}

test('importa backup mensal de NF-e criando documentos persistentes', function (): void {
    $user = focusUser();
    $accessKey = '35260123456789000123550010000000011000000012';
    $zipContent = zipWithNfeXmls(['NFe'.$accessKey.'-nfe.xml' => nfeXml($accessKey)]);

    Http::fake([
        'https://homologacao.focusnfe.com.br/v2/backups/*' => Http::response([
            ['mes' => '202601', 'xmls' => 'https://focusnfe.s3.amazonaws.com/202601/xmls.zip'],
        ], 200),
        'https://focusnfe.s3.amazonaws.com/*' => Http::response($zipContent, 200),
    ]);

    $stats = app(FocusNfeBackupService::class)->importMonth($user, '202601');

    expect($stats['imported'])->toBe(1)
        ->and($stats['ignored'])->toBe(0);

    $document = FiscalDocument::query()->first();

    expect($document->document_type)->toBe('NF-e')
        ->and($document->access_key)->toBe($accessKey)
        ->and($document->document_number)->toBe('1504')
        ->and($document->series)->toBe('1')
        ->and($document->status)->toBe('autorizado')
        ->and($document->total_amount)->toBe('1500.00')
        ->and($document->metadata['origin'])->toBe('focus_backup')
        ->and($document->payload_hash)->toBe(hash('sha256', nfeXml($accessKey)))
        ->and($document->xml_path)->not->toBeNull()
        ->and($document->isLinked())->toBeFalse();
});

test('importação repetida do mesmo backup atualiza em vez de duplicar', function (): void {
    $user = focusUser();
    $accessKey = '35260123456789000123550010000000011000000012';
    $zipContent = zipWithNfeXmls(['NFe'.$accessKey.'-nfe.xml' => nfeXml($accessKey)]);

    Http::fake([
        'https://homologacao.focusnfe.com.br/v2/backups/*' => Http::response([
            ['mes' => '202601', 'xmls' => 'https://focusnfe.s3.amazonaws.com/202601/xmls.zip'],
        ], 200),
        'https://focusnfe.s3.amazonaws.com/*' => Http::response($zipContent, 200),
    ]);

    $service = app(FocusNfeBackupService::class);
    $service->importMonth($user, '202601');
    $stats = $service->importMonth($user, '202601');

    expect($stats['updated'])->toBe(1)
        ->and(FiscalDocument::query()->count())->toBe(1);
});

test('ignora XML de emissor diferente do prestador configurado', function (): void {
    $user = focusUser();
    $accessKey = '35260123456789000123550010000000011000000012';
    $zipContent = zipWithNfeXmls(['NFe'.$accessKey.'-nfe.xml' => nfeXml($accessKey, '11111111000111')]);

    Http::fake([
        'https://homologacao.focusnfe.com.br/v2/backups/*' => Http::response([
            ['mes' => '202601', 'xmls' => 'https://focusnfe.s3.amazonaws.com/202601/xmls.zip'],
        ], 200),
        'https://focusnfe.s3.amazonaws.com/*' => Http::response($zipContent, 200),
    ]);

    $stats = app(FocusNfeBackupService::class)->importMonth($user, '202601');

    expect($stats['ignored'])->toBe(1)
        ->and(FiscalDocument::query()->count())->toBe(0);
});

test('importa NFS-e por referência e vincula à ordem de serviço', function (): void {
    $user = focusUser();
    $client = Client::create([
        'user_id' => $user->id, 'name' => 'Tomador', 'email' => uniqid().'@tomador.test',
        'phone' => '11987654321', 'document_type' => 'cnpj', 'document' => '04252011000110',
        'address' => 'Rua A', 'address_number' => '10', 'city' => 'São Paulo', 'state' => 'SP',
    ]);
    $serviceOrder = ServiceOrder::create([
        'user_id' => $user->id, 'client_id' => $client->id, 'number' => 'OS-0001',
        'status' => 'completed', 'focus_nfse_ref' => 'nfse-ref-123',
        'focus_nfse_status' => 'autorizado', 'total_amount' => 500,
    ]);

    Http::fake([
        'https://homologacao.focusnfe.com.br/v2/nfse/nfse-ref-123*' => Http::response([
            'status' => 'autorizado',
            'numero' => '202600001',
            'url' => 'https://homologacao.focusnfe.com.br/danfse/202600001.pdf',
            'data_emissao' => '2026-01-10T10:00:00-03:00',
            'cnpj_prestador' => '28480405000193',
        ], 200),
    ]);

    $data = app(FocusNfseImportService::class)->importByReference($user, 'nfse-ref-123');

    expect($data['numero'])->toBe('202600001');

    $document = FiscalDocument::query()->first();

    expect($document->document_type)->toBe('NFS-e')
        ->and($document->focus_reference)->toBe('nfse-ref-123')
        ->and($document->source_type)->toBe(ServiceOrder::class)
        ->and($document->source_id)->toBe($serviceOrder->id)
        ->and($document->metadata['origin'])->toBe('focus_individual')
        ->and($document->isLinked())->toBeTrue();
});
