<?php

use App\Http\Controllers\SaleDanfePreviewController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect('/admin');
});

Route::middleware('auth')->group(function (): void {
    Route::get('/sales/{sale}/danfe-preview', SaleDanfePreviewController::class)
        ->name('sales.danfe-preview');
});
