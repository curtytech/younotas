<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Services\FiscalExportService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class FiscalExportController extends Controller
{
    public function __invoke(Request $request, FiscalExportService $export): BinaryFileResponse
    {
        $data = $request->validate([
            'company_id' => ['required', 'integer'],
            'environment' => ['required', 'in:homologacao,producao'],
            'month' => ['required', 'regex:/^\d{4}(0[1-9]|1[0-2])$/'],
        ]);
        $company = Company::findOrFail($data['company_id']);
        abort_unless($request->user()?->canAccessCompany($company, 'view'), 403);

        $path = $export->build($company, $data['environment'], $data['month']);

        return response()->download($path, 'fiscal-'.$company->id.'-'.$data['environment'].'-'.$data['month'].'.zip', ['Cache-Control' => 'private, no-store'])->deleteFileAfterSend();
    }
}
