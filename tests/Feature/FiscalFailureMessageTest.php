<?php

use App\Models\FiscalDocument;
use App\Services\FiscalIssueDiagnosis;
use Illuminate\Database\QueryException;

test('falha de banco possui orientação pública sem SQL nem valores enviados', function () {
    $exception = new QueryException('sqlite', 'update fiscal_documents set access_key = ?', ['dado-confidencial'], new PDOException('SQLSTATE[23000]: falha'));
    $message = FiscalIssueDiagnosis::failureMessage($exception);
    expect($message)->toContain('Consulte a situação da nota')
        ->not->toContain('SQLSTATE', 'fiscal_documents', 'dado-confidencial');
    expect(FiscalIssueDiagnosis::failureMessage(new RuntimeException('Informe o NCM.')))->toBe('Informe o NCM.');
});

test('diagnóstico de falha antiga de banco não mostra SQL e orienta consultar antes de reenviar', function () {
    $document = new FiscalDocument([
        'fiscal_connection_id' => 1, 'environment' => 'homologacao', 'status' => 'erro_emissao',
        'last_error' => ['message' => 'SQLSTATE[23000] update fiscal_documents dado-confidencial'],
    ]);
    $diagnosis = app(FiscalIssueDiagnosis::class)->explain($document);
    expect($diagnosis['category'])->toBe('persistence')
        ->and($diagnosis['action'])->toContain('Consulte a situação da nota')
        ->and(json_encode($diagnosis))->not->toContain('SQLSTATE', 'fiscal_documents', 'dado-confidencial');
});
