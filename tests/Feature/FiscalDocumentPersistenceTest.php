<?php

use App\Models\Client;
use App\Models\FiscalDocument;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\ServiceOrder;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

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
