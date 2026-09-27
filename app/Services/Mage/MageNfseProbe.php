<?php

namespace App\Services\Mage;

use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class MageNfseProbe
{
    public const OPERATIONS = [
        'GerarNfse' => 'GerarNfseEnvio',
        'ConsultarLoteRps' => 'ConsultarLoteRpsEnvio',
        'ConsultarNfsePorRps' => 'ConsultarNfseRpsEnvio',
    ];

    public function parse(string $xml): DOMDocument
    {
        if (strlen($xml) > 512 * 1024 || preg_match('/<!DOCTYPE|<!ENTITY/i', $xml)) {
            throw new RuntimeException('XML excede 512 KB ou contém DTD/entidades proibidas.');
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $doc = new DOMDocument;
            if (! $doc->loadXML($xml, LIBXML_NONET)) {
                throw new RuntimeException('XML inválido. Conteúdo omitido para proteger os dados fiscais.');
            }

            return $doc;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    public function validate(string $xml, string $operation, string $schema): void
    {
        $doc = $this->parse($xml);
        if (! isset(self::OPERATIONS[$operation]) || $doc->documentElement->localName !== self::OPERATIONS[$operation]
            || $doc->documentElement->namespaceURI !== 'http://www.abrasf.org.br/nfse.xsd') {
            throw new RuntimeException('Raiz/namespace XML incompatível com a operação ABRASF selecionada.');
        }
        if (! is_file($schema) || ! is_readable($schema)) {
            throw new RuntimeException('Informe o XSD municipal local e mantenha os imports na mesma estrutura.');
        }
        $previous = libxml_use_internal_errors(true);
        try {
            if (! $doc->schemaValidate($schema)) {
                $lines = array_unique(array_map(fn ($error) => $error->line, libxml_get_errors()));
                throw new RuntimeException('XML não atende ao XSD. Linhas: '.implode(', ', $lines));
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    public function diagnose(string $environment): array
    {
        $response = Http::connectTimeout(5)->timeout(15)->withoutRedirecting()->get($this->endpoint($environment).'?wsdl');
        if (! $response->successful()) {
            throw new RuntimeException('WSDL indisponível: HTTP '.$response->status());
        }
        $doc = $this->parse($response->body());
        if ($doc->documentElement->namespaceURI !== 'http://schemas.xmlsoap.org/wsdl/') {
            throw new RuntimeException('Endpoint não retornou WSDL.');
        }
        $xpath = new DOMXPath($doc);
        $xpath->registerNamespace('w', 'http://schemas.xmlsoap.org/wsdl/');

        return array_values(array_unique(array_map(fn ($node) => $node->getAttribute('name'), iterator_to_array($xpath->query('//w:portType/w:operation')))));
    }

    public function send(string $environment, string $operation, string $xml, string $version, bool $allowHttp): string
    {
        if (! isset(self::OPERATIONS[$operation]) || ! in_array($version, ['2.02', '3.02'], true)) {
            throw new RuntimeException('Operação ou versão não suportada.');
        }
        if ($environment === 'producao' && $operation === 'GerarNfse') {
            throw new RuntimeException('Este comando de teste não emite em produção.');
        }
        $endpoint = $this->endpoint($environment);
        if (str_starts_with($endpoint, 'http:') && ! $allowHttp) {
            throw new RuntimeException('Homologação publicada pelo município usa HTTP. Use --permitir-http somente com dados de teste.');
        }
        $doc = $this->parse($xml);
        if ($doc->getElementsByTagNameNS('http://www.w3.org/2000/09/xmldsig#', 'Signature')->length === 0) {
            throw new RuntimeException('Forneça XML assinado digitalmente conforme o manual municipal.');
        }
        $certificate = config('mage_nfse.certificate');
        if (! $certificate || ! is_readable($certificate)) {
            throw new RuntimeException('Configure MAGE_NFSE_CERTIFICATE com certificado e chave privada em PEM.');
        }
        $escape = fn (string $value) => htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $header = '<cabecalho xmlns="http://www.abrasf.org.br/nfse.xsd" versao="'.$version.'"><versaoDados>'.$version.'</versaoDados></cabecalho>';
        // The live Axis WSDL declares RPC/encoded, DefaultNamespace and an empty SOAPAction.
        $body = '<?xml version="1.0" encoding="UTF-8"?>'
            .'<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema">'
            .'<soap:Body><m:'.$operation.' xmlns:m="http://DefaultNamespace" soap:encodingStyle="http://schemas.xmlsoap.org/soap/encoding/">'
            .'<Nfsecabecmsg xsi:type="xsd:string">'.$escape($header).'</Nfsecabecmsg>'
            .'<Nfsedadosmsg xsi:type="xsd:string">'.$escape($xml).'</Nfsedadosmsg>'
            .'</m:'.$operation.'></soap:Body></soap:Envelope>';
        // No retry: a timeout may happen after the municipality has issued the invoice.
        $response = Http::connectTimeout(5)->timeout(30)->withoutRedirecting()
            ->withOptions(['cert' => [$certificate, config('mage_nfse.certificate_password')]])
            ->withHeaders(['SOAPAction' => '""'])->withBody($body, 'text/xml; charset=UTF-8')->post($endpoint);
        if (! $response->successful()) {
            throw new RuntimeException('Município retornou HTTP '.$response->status().'. Sem reenvio automático; consulte antes de repetir.');
        }
        $reply = $this->parse($response->body());
        if ($reply->getElementsByTagNameNS('http://schemas.xmlsoap.org/soap/envelope/', 'Fault')->length) {
            throw new RuntimeException('Município retornou SOAP Fault. Conteúdo omitido.');
        }
        $nodes = (new DOMXPath($reply))->query('//*[local-name()="'.$operation.'Return"]');
        if ($nodes->length !== 1) {
            throw new RuntimeException('Resposta SOAP sem retorno da operação esperada.');
        }
        $node = $nodes->item(0);
        $result = $node->firstElementChild ? $reply->saveXML($node->firstElementChild) : $node->textContent;
        $this->parse($result);

        return $result;
    }

    private function endpoint(string $environment): string
    {
        if (! in_array($environment, ['homologacao', 'producao'], true)) {
            throw new RuntimeException('Ambiente deve ser homologacao ou producao.');
        }

        return config('mage_nfse.'.$environment);
    }
}
