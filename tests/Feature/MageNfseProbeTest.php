<?php

use App\Services\Mage\MageNfseProbe;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
    config(['mage_nfse.certificate' => __FILE__, 'mage_nfse.certificate_password' => 'test']);
});

function mageTestXml(): string
{
    return '<GerarNfseEnvio xmlns="http://www.abrasf.org.br/nfse.xsd"><Signature xmlns="http://www.w3.org/2000/09/xmldsig#"/></GerarNfseEnvio>';
}

test('diagnóstico exige WSDL e lista operações', function () {
    Http::fake(['*' => Http::response('<definitions xmlns="http://schemas.xmlsoap.org/wsdl/"><portType><operation name="GerarNfse"/></portType></definitions>')]);
    expect(app(MageNfseProbe::class)->diagnose('producao'))->toBe(['GerarNfse']);
    Http::assertSentCount(1);
});

test('bloqueia emissão em produção e HTTP sem opção explícita', function () {
    $probe = app(MageNfseProbe::class);
    expect(fn () => $probe->send('producao', 'GerarNfse', mageTestXml(), '3.02', false))->toThrow(RuntimeException::class, 'não emite em produção');
    expect(fn () => $probe->send('homologacao', 'GerarNfse', mageTestXml(), '3.02', false))->toThrow(RuntimeException::class, 'usa HTTP');
    Http::assertNothingSent();
});

test('monta SOAP RPC encoded do WSDL e extrai retorno XML', function () {
    $reply = '<GerarNfseResposta xmlns="http://www.abrasf.org.br/nfse.xsd"><ListaMensagemRetorno/></GerarNfseResposta>';
    Http::fake(['*' => Http::response('<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/"><s:Body><GerarNfseResponse><GerarNfseReturn>'.htmlspecialchars($reply, ENT_XML1).'</GerarNfseReturn></GerarNfseResponse></s:Body></s:Envelope>')]);
    expect(app(MageNfseProbe::class)->send('homologacao', 'GerarNfse', mageTestXml(), '3.02', true))->toBe($reply);
    Http::assertSent(function ($request) {
        $doc = new DOMDocument;
        $doc->loadXML($request->body());

        return $request->method() === 'POST'
            && $request->hasHeader('SOAPAction', '""')
            && $doc->getElementsByTagNameNS('http://DefaultNamespace', 'GerarNfse')->length === 1
            && $doc->getElementsByTagName('Nfsedadosmsg')->item(0)->textContent === mageTestXml()
            && str_contains($doc->getElementsByTagName('Nfsecabecmsg')->item(0)->textContent, '<versaoDados>3.02</versaoDados>');
    });
});

test('não repete erros HTTP nem expõe corpo fiscal', function ($status) {
    Http::fake(['*' => Http::response('SEGREDO-FISCAL', $status)]);
    expect(fn () => app(MageNfseProbe::class)->send('homologacao', 'GerarNfse', mageTestXml(), '3.02', true))
        ->toThrow(RuntimeException::class, 'HTTP '.$status);
    Http::assertSentCount(1);
})->with([400, 401, 404, 422, 429, 500, 503]);

test('rejeita resposta inválida e SOAP Fault', function ($body) {
    Http::fake(['*' => Http::response($body)]);
    expect(fn () => app(MageNfseProbe::class)->send('homologacao', 'GerarNfse', mageTestXml(), '3.02', true))->toThrow(RuntimeException::class);
    Http::assertSentCount(1);
})->with(['not xml', '<html/>', '<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/"><s:Body><s:Fault/></s:Body></s:Envelope>']);

test('rejeita entidades externas e tamanho excessivo', function ($xml) {
    expect(fn () => app(MageNfseProbe::class)->parse($xml))->toThrow(RuntimeException::class);
})->with(['<!DOCTYPE x [<!ENTITY secret SYSTEM "file:///etc/passwd">]><x>&secret;</x>', str_repeat('x', 524289)]);

test('comando trata timeout sem vazar detalhes', function () {
    Http::fake(['*' => Http::failedConnection()]);
    $this->artisan('mage:nfse-teste')->expectsOutputToContain('Falha de conexão/timeout')->assertFailed();
});

test('validação XSD verifica conteúdo além da raiz', function () {
    $schema = tempnam(sys_get_temp_dir(), 'mage-xsd');
    file_put_contents($schema, '<xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema" targetNamespace="http://www.abrasf.org.br/nfse.xsd"><xs:element name="GerarNfseEnvio" type="xs:integer"/></xs:schema>');
    try {
        $probe = app(MageNfseProbe::class);
        $probe->validate('<GerarNfseEnvio xmlns="http://www.abrasf.org.br/nfse.xsd">1</GerarNfseEnvio>', 'GerarNfse', $schema);
        expect(fn () => $probe->validate(mageTestXml(), 'GerarNfse', $schema))->toThrow(RuntimeException::class, 'não atende ao XSD');
    } finally {
        unlink($schema);
    }
    Http::assertNothingSent();
});
