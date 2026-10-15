<?php

namespace App\Services;

use App\Models\Company;
use App\Models\FiscalDocument;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use ZipArchive;

/** Monthly package for the accountant: an index of the documents plus the stored authorization and cancellation XMLs. */
class FiscalExportService
{
    private const HEADER = ['tipo', 'ambiente', 'numero', 'serie', 'chave_de_acesso', 'situacao', 'emissao', 'autorizacao', 'valor_total', 'referencia', 'xml', 'xml_cancelamento'];

    /** @return string path of a temporary ZIP the caller must delete */
    public function build(Company $company, string $environment, string $month): string
    {
        if (! preg_match('/^\d{4}(0[1-9]|1[0-2])$/', $month) || ! isset(FocusNfeConfigService::ENVIRONMENT_URLS[$environment])) {
            throw new RuntimeException('Período ou ambiente inválido.');
        }

        $start = now()->setDate((int) substr($month, 0, 4), (int) substr($month, 4, 2), 1)->startOfDay();
        $documents = FiscalDocument::query()->where('company_id', $company->id)->where('environment', $environment)
            ->whereIn('status', ['autorizado', 'cancelado'])
            ->whereBetween('issued_at', [$start, $start->copy()->endOfMonth()])
            ->orderBy('issued_at')->orderBy('id')->get();

        $path = tempnam(sys_get_temp_dir(), 'fiscal-export-');
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Não foi possível gerar o arquivo de exportação.');
        }

        $index = fopen('php://temp', 'w+');
        fwrite($index, "\xEF\xBB\xBF");
        fputcsv($index, self::HEADER, ';');
        $disk = Storage::disk('local');

        foreach ($documents as $document) {
            $files = [];

            foreach (['xml' => ['xml_path', 'xml'], 'cancelamento' => ['cancelation_xml_path', 'cancelamento']] as $key => [$column, $folder]) {
                $source = $document->{$column};

                if (is_string($source) && $source !== '' && ! str_contains($source, '..') && ! str_starts_with($source, '/') && $disk->exists($source)) {
                    $files[$key] = $folder.'/'.$document->document_type.'-'.($document->access_key ?: $document->id).'.xml';
                    $zip->addFile($disk->path($source), $files[$key]);
                }
            }

            fputcsv($index, array_map(self::safeCell(...), [
                $document->document_type, $document->environment, $document->document_number, $document->series, $document->access_key,
                $document->status, $document->issued_at?->format('Y-m-d H:i:s'), $document->authorized_at?->format('Y-m-d H:i:s'),
                number_format((float) $document->total_amount, 2, ',', ''), $document->focus_reference,
                $files['xml'] ?? 'sem XML armazenado', $files['cancelamento'] ?? '',
            ]), ';');
        }

        rewind($index);
        $zip->addFromString('indice.csv', stream_get_contents($index));
        fclose($index);
        $zip->close();

        return $path;
    }

    /** Neutralizes spreadsheet formulas in values that originate from provider or user data. */
    private static function safeCell(mixed $value): string
    {
        $value = (string) $value;

        return $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
    }
}
