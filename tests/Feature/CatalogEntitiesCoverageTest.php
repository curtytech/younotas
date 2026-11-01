<?php

use App\Models\Appeal;
use App\Models\AppealStatus;
use App\Models\Company;
use App\Models\FiscalDocument;
use App\Models\FiscalEvent;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderAttachment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('status de recurso agrupa recursos e a fábrica gera nomes em português', function (): void {
    $status = AppealStatus::factory()->create(['name' => 'Em análise']);
    $appeal = Appeal::factory()->create(['appeal_status_id' => $status->id]);

    expect($status->appeals->pluck('id')->all())->toBe([$appeal->id])
        ->and($status->name)->toBe('Em análise');
});

test('anexo de ordem de serviço liga ordem e quem enviou', function (): void {
    $attachment = ServiceOrderAttachment::factory()->create(['original_name' => 'foto-instalação-ção.jpg']);

    expect($attachment->order)->toBeInstanceOf(ServiceOrder::class)
        ->and($attachment->uploader)->toBeInstanceOf(User::class)
        ->and($attachment->original_name)->toBe('foto-instalação-ção.jpg')
        ->and($attachment->size)->toBe(0);
});

test('evento fiscal guarda o contexto criptografado e devolve como array', function (): void {
    $company = Company::factory()->create();
    $document = FiscalDocument::factory()->create(['company_id' => $company->id]);
    $event = FiscalEvent::query()->create([
        'company_id' => $company->id, 'fiscal_document_id' => $document->id, 'event' => 'consulta',
        'context' => ['mensagem' => 'Situação: autorizada — ação concluída'],
    ]);

    expect($event->fresh()->context)->toBe(['mensagem' => 'Situação: autorizada — ação concluída'])
        ->and(\DB::table('fiscal_events')->where('id', $event->id)->value('context'))->not->toContain('autorizada');
});

test('evento fiscal aceita contexto vazio', function (): void {
    $company = Company::factory()->create();
    $document = FiscalDocument::factory()->create(['company_id' => $company->id]);
    $event = FiscalEvent::query()->create(['company_id' => $company->id, 'fiscal_document_id' => $document->id, 'event' => 'webhook', 'context' => []]);

    expect($event->fresh()->context)->toBe([]);
});
