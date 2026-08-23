<?php

use App\Filament\Resources\FiscalDocumentResource\Pages\ListFiscalDocuments;
use App\Models\Client;
use App\Models\FiscalDocument;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\ServiceOrder;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function makeUser(): User
{
    return User::create([
        'name' => 'Empresa Teste', 'email' => uniqid().'@test.local',
        'password' => bcrypt('password'), 'role' => 'enterprise',
    ]);
}

function makeSaleWithNfe(User $user): Sale
{
    $client = Client::create([
        'user_id' => $user->id, 'name' => 'Cliente Teste', 'email' => uniqid().'@cliente.test',
        'phone' => '11987654321', 'document_type' => 'cpf', 'document' => '52998224725',
        'address' => 'Rua A', 'address_number' => '10', 'city' => 'São Paulo', 'state' => 'SP',
    ]);
    $product = Product::create([
        'user_id' => $user->id, 'name' => 'Produto Teste', 'ncm_code' => '61091000',
        'cfop_code' => '5102', 'unit' => 'UN', 'sale_price' => 100, 'stock_quantity' => 10,
        'is_active' => true,
    ]);
    $sale = Sale::create([
        'user_id' => $user->id, 'client_id' => $client->id, 'number' => 'V-'.uniqid(),
        'sale_date' => now(), 'status' => 'completed', 'payment_status' => 'paid',
        'focus_nfe_ref' => 'sale-'.uniqid(), 'focus_nfe_status' => 'processando',
        'total_amount' => 100,
    ]);
    SaleItem::create([
        'sale_id' => $sale->id, 'product_id' => $product->id, 'product_name' => $product->name,
        'product_code' => (string) $product->id, 'unit' => 'UN', 'quantity' => 1,
        'unit_price' => 100, 'total_amount' => 100,
    ]);

    return $sale;
}

function makeServiceOrderWithNfse(User $user): ServiceOrder
{
    $client = Client::create([
        'user_id' => $user->id, 'name' => 'Cliente Serviço', 'email' => uniqid().'@cliente.test',
        'phone' => '11987654321', 'document_type' => 'cnpj', 'document' => '04252011000110',
        'address' => 'Rua A', 'address_number' => '10', 'city' => 'São Paulo', 'state' => 'SP',
    ]);

    return ServiceOrder::create([
        'user_id' => $user->id, 'client_id' => $client->id, 'number' => 'OS-'.uniqid(),
        'status' => 'completed', 'focus_nfse_ref' => 'nfse-'.uniqid(),
        'focus_nfse_status' => 'autorizado', 'focus_nfse_number' => '202600001',
        'total_amount' => 1500,
    ]);
}

test('backfill cria documentos e é idempotente', function (): void {
    $user = makeUser();
    makeSaleWithNfe($user);
    makeServiceOrderWithNfse($user);

    $this->artisan('fiscal:backfill')->assertSuccessful();
    $this->artisan('fiscal:backfill')->assertSuccessful();

    expect(FiscalDocument::query()->count())->toBe(2)
        ->and(FiscalDocument::query()->where('document_type', 'NF-e')->first()->source_label)->not->toBeNull()
        ->and(FiscalDocument::query()->where('document_type', 'NFS-e')->first()->focus_reference)->not->toBeNull();
});

test('documento importado sem origem é persistido e isolado por empresa', function (): void {
    $userA = makeUser();
    $userB = makeUser();

    FiscalDocument::factory()->create([
        'user_id' => $userA->id,
        'focus_reference' => 'imp-A',
        'metadata' => ['origin' => 'focus_backup'],
    ]);
    FiscalDocument::factory()->nfse()->create([
        'user_id' => $userB->id,
        'focus_reference' => 'imp-B',
    ]);

    $docsA = FiscalDocument::query()->where('user_id', $userA->id)->get();
    $docsB = FiscalDocument::query()->where('user_id', $userB->id)->get();

    expect($docsA)->toHaveCount(1)
        ->and($docsA->first()->isLinked())->toBeFalse()
        ->and($docsA->first()->metadata['origin'])->toBe('focus_backup')
        ->and($docsB)->toHaveCount(1)
        ->and($docsB->first()->document_type)->toBe('NFS-e');
});

test('chave de acesso única por empresa impede duplicidade', function (): void {
    $user = makeUser();

    FiscalDocument::factory()->create([
        'user_id' => $user->id,
        'access_key' => 'NFe35191234567890000123550010000000011000000001',
        'focus_reference' => null,
    ]);

    expect(fn (): mixed => FiscalDocument::factory()->create([
        'user_id' => $user->id,
        'access_key' => 'NFe35191234567890000123550010000000011000000001',
        'focus_reference' => null,
    ]))->toThrow(QueryException::class);
});

test('documento fiscal pode ser arquivado e restaurado sem perder os dados', function (): void {
    $document = FiscalDocument::factory()->create([
        'focus_reference' => 'archive-reference-001',
    ]);

    $document->delete();

    expect(FiscalDocument::query()->find($document->id))->toBeNull()
        ->and(FiscalDocument::withTrashed()->find($document->id)?->trashed())->toBeTrue()
        ->and(FiscalDocument::withTrashed()->find($document->id)?->focus_reference)->toBe('archive-reference-001');

    $document->restore();

    expect(FiscalDocument::query()->find($document->id))->not->toBeNull()
        ->and(FiscalDocument::withTrashed()->find($document->id)?->trashed())->toBeFalse();
});

test('sincronização mantém o mesmo documento quando ele está arquivado', function (): void {
    $user = makeUser();
    $serviceOrder = makeServiceOrderWithNfse($user);
    $document = FiscalDocument::syncFromServiceOrder($serviceOrder);

    $document->delete();

    $synced = FiscalDocument::syncFromServiceOrder($serviceOrder->fresh());

    expect($synced->id)->toBe($document->id)
        ->and(FiscalDocument::withTrashed()->whereKey($document->id)->count())->toBe(1)
        ->and($synced->trashed())->toBeTrue();
});

test('histórico fiscal permite arquivar um documento pela tabela', function (): void {
    $user = makeUser();
    $document = FiscalDocument::factory()->create([
        'user_id' => $user->id,
        'focus_reference' => 'archive-ui-reference-001',
        'status' => 'autorizado',
    ]);

    $this->actingAs($user);

    Livewire::test(ListFiscalDocuments::class)
        ->assertTableActionVisible('arquivar', $document)
        ->callTableAction('arquivar', $document);

    expect(FiscalDocument::query()->find($document->id))->toBeNull()
        ->and(FiscalDocument::withTrashed()->find($document->id)?->trashed())->toBeTrue();

    $trashedDocument = FiscalDocument::withTrashed()->findOrFail($document->id);

    Livewire::test(ListFiscalDocuments::class)
        ->filterTable('trashed', false)
        ->assertTableActionVisible('restaurar', $trashedDocument)
        ->callTableAction('restaurar', $trashedDocument);

    expect(FiscalDocument::query()->find($document->id))->not->toBeNull();
});

test('menu de contexto permanece disponível para documento em processamento', function (): void {
    $user = makeUser();
    $serviceOrder = ServiceOrder::factory()->create([
        'user_id' => $user->id,
        'focus_nfse_ref' => 'nfse-processing-menu-001',
        'focus_nfse_status' => 'processando',
    ]);
    $document = FiscalDocument::factory()->nfse()->create([
        'user_id' => $user->id,
        'source_type' => ServiceOrder::class,
        'source_id' => $serviceOrder->id,
        'focus_reference' => $serviceOrder->focus_nfse_ref,
        'status' => 'processando',
    ]);

    $this->actingAs($user);

    Livewire::test(ListFiscalDocuments::class)
        ->assertTableActionVisible('consultar', $document)
        ->assertTableActionVisible('arquivar', $document)
        ->assertTableActionHidden('cancelar_nota', $document)
        ->assertTableActionHidden('reenviar', $document);
});
