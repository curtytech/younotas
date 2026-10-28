<?php

use App\Http\Controllers\SaleDanfePreviewController;
use App\Http\Controllers\FiscalDocumentFileController;
use App\Http\Controllers\FiscalExportController;
use App\Http\Controllers\QuotePdfController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect('/admin');
});

Route::middleware('auth')->group(function (): void {
    Route::get('/fiscal-documents/{fiscalDocument}/files/{artifact}', FiscalDocumentFileController::class)->whereIn('artifact', ['xml', 'cancelamento'])->name('fiscal-documents.files');
    Route::get('/fiscal-documents-export', FiscalExportController::class)->name('fiscal-documents.export');
    Route::get('/sales/{sale}/danfe-preview', SaleDanfePreviewController::class)
        ->name('sales.danfe-preview');
    Route::get('/quotes/{quote}/pdf', QuotePdfController::class)
        ->name('quotes.pdf');
});
