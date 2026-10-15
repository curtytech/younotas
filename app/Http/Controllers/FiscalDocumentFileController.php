<?php

namespace App\Http\Controllers;

use App\Models\FiscalDocument;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FiscalDocumentFileController extends Controller
{
    public function __invoke(FiscalDocument $fiscalDocument, string $artifact): StreamedResponse
    {
        abort_unless(auth()->user()?->canAccessCompany((int) $fiscalDocument->company_id, 'view'), 403);
        $path = match ($artifact) {
            'xml' => $fiscalDocument->xml_path,
            'cancelamento' => $fiscalDocument->cancelation_xml_path,
            default => null,
        };
        abort_unless(is_string($path) && $path !== '' && ! str_contains($path, '..') && ! str_starts_with($path, '/'), 404);
        abort_unless(Storage::disk('local')->exists($path), 404);
        return Storage::disk('local')->download($path, 'documento-'.$fiscalDocument->id.'-'.$artifact.'.xml', ['Content-Type' => 'application/xml', 'Cache-Control' => 'private, no-store']);
    }
}
