<?php

namespace App\Services;

use App\Models\Quote;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

class QuotePdfService
{
    public function download(Quote $quote): Response
    {
        $quote->load(['company', 'client', 'items']);

        $logoDataUri = null;
        if (filled($quote->company->logo_path) && Storage::disk('public')->exists($quote->company->logo_path)) {
            $path = Storage::disk('public')->path($quote->company->logo_path);
            $mime = mime_content_type($path) ?: 'image/png';
            $logoDataUri = 'data:'.$mime.';base64,'.base64_encode((string) file_get_contents($path));
        }

        $pdf = Pdf::loadView('quotes.pdf', compact('quote', 'logoDataUri'))
            ->setPaper('a4');

        return $pdf->download('orcamento-'.$quote->number.'.pdf');
    }
}
