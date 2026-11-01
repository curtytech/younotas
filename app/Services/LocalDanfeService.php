<?php

namespace App\Services;

use App\Models\FiscalDocument;
use App\Models\Sale;
use App\Support\NfeStatus;
use DOMDocument;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Storage;
use NFePHP\DA\NFe\Danfe;
use RuntimeException;
use Throwable;

/**
 * Gera a DANFE no próprio sistema a partir do XML autorizado, com a logo da empresa.
 * A DANFE gerada pela Focus continua disponível em paralelo.
 */
class LocalDanfeService
{
    public function __construct(
        protected FocusNfeConfigService $configService,
        protected FocusApiClient $apiClient,
    ) {}

    public function render(Sale $sale): string
    {
        $document = $this->authorizedDocument($sale);
        $xml = $this->authorizedXml($document);

        try {
            $danfe = new Danfe($xml);
            $danfe->creditsIntegratorFooter('Gerada pelo younNotas', false);
            $logo = $this->logoDataUri($sale);
            if ($logo !== null) {
                $danfe->logoParameters($logo, 'C');
            }

            return $danfe->render();
        } catch (Throwable) {
            throw new RuntimeException('Não foi possível montar a DANFE com logo a partir do XML da nota. Use a DANFE da Focus.');
        }
    }

    protected function authorizedDocument(Sale $sale): FiscalDocument
    {
        if ($sale->focus_nfe_status !== NfeStatus::AUTHORIZED || blank($sale->focus_nfe_ref)) {
            throw new RuntimeException('A DANFE com logo só fica disponível depois que a NF-e é autorizada.');
        }

        return $sale->fiscalDocuments()->where('focus_reference', $sale->focus_nfe_ref)->latest('id')->first()
            ?? throw new RuntimeException('O registro fiscal desta NF-e não foi encontrado. Atualize a situação da nota e tente de novo.');
    }

    protected function authorizedXml(FiscalDocument $document): string
    {
        $disk = Storage::disk('local');
        if (filled($document->xml_path) && $disk->exists($document->xml_path)) {
            $xml = (string) $disk->get($document->xml_path);
            $this->guardXmlBelongsTo($xml, $document);

            return $xml;
        }

        try {
            $xml = $this->downloadXml($document);
        } catch (ConnectionException) {
            throw new RuntimeException('Não foi possível falar com a Focus para baixar o XML da nota. Tente de novo em alguns minutos.');
        }
        $this->guardXmlBelongsTo($xml, $document);

        $xmlPath = 'fiscal/xml/'.$document->company_id.'/'.$document->id.'.xml';
        $disk->put($xmlPath, $xml);
        $document->forceFill(['xml_path' => $xmlPath])->save();

        return $xml;
    }

    protected function downloadXml(FiscalDocument $document): string
    {
        $config = $this->configService->forDocument($document);
        $path = data_get($document->raw_response, 'caminho_xml_nota_fiscal');
        if (blank($path)) {
            $response = $this->apiClient->get($config, '/v2/nfe/'.rawurlencode($document->focus_reference));
            $path = $response->successful() ? $response->json('caminho_xml_nota_fiscal') : null;
        }
        if (blank($path)) {
            throw new RuntimeException('A Focus ainda não disponibilizou o XML desta NF-e. Tente de novo em alguns minutos.');
        }

        return $this->apiClient->download($config, (string) $path);
    }

    /** Garante que o XML é a NF-e autorizada deste documento, no mesmo ambiente. */
    protected function guardXmlBelongsTo(string $xml, FiscalDocument $document): void
    {
        $dom = new DOMDocument;
        $loaded = $xml !== '' && @$dom->loadXML($xml, LIBXML_NONET);
        $text = fn (string $tag): string => $loaded ? trim((string) $dom->getElementsByTagName($tag)->item(0)?->textContent) : '';
        $key = $text('chNFe');
        $expectedKey = preg_replace('/\D/', '', (string) ($document->access_key ?: data_get($document->raw_response, 'chave_nfe')));
        $expectedEnvironment = $document->environment === 'producao' ? '1' : '2';

        if (! $loaded || $key === '' || ! in_array($text('cStat'), ['100', '150'], true)) {
            throw new RuntimeException('O XML recebido da Focus não é de uma NF-e autorizada.');
        }
        if ($expectedKey === '' || $key !== $expectedKey || $text('tpAmb') !== $expectedEnvironment) {
            throw new RuntimeException('O XML recebido não corresponde a esta NF-e. Atualize a situação da nota e tente de novo.');
        }
    }

    /** Converte a logo para JPEG, formato aceito pela biblioteca, sem depender do formato enviado. */
    protected function logoDataUri(Sale $sale): ?string
    {
        $path = $sale->company?->logo_path;
        if (blank($path) || ! Storage::disk('public')->exists($path)) {
            return null;
        }

        $contents = (string) Storage::disk('public')->get($path);
        $size = @getimagesizefromstring($contents);
        // Evita decodificar imagens gigantes que esgotariam a memória do processo.
        if ($size === false || $size[0] * $size[1] > 16_000_000) {
            return null;
        }

        $image = @imagecreatefromstring($contents);
        if ($image === false) {
            return null;
        }

        $scale = min(1, 600 / max(imagesx($image), imagesy($image)));
        $width = max(1, (int) round(imagesx($image) * $scale));
        $height = max(1, (int) round(imagesy($image) * $scale));
        $canvas = imagecreatetruecolor($width, $height);
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        imagecopyresampled($canvas, $image, 0, 0, 0, 0, $width, $height, imagesx($image), imagesy($image));
        ob_start();
        imagejpeg($canvas, null, 90);
        $jpeg = (string) ob_get_clean();

        return 'data://text/plain;base64,'.base64_encode($jpeg);
    }
}
