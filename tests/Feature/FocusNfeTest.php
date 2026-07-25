<?php

use App\Actions\CancelSaleNfeAction;
use App\Actions\ConsultSaleNfeAction;
use App\Actions\EmitSaleNfeAction;
use App\Models\Client;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->user = User::create([
        'name' => 'Empresa Teste', 'email' => 'nfe@test.local', 'password' => bcrypt('password'), 'role' => 'admin',
        'cnpj' => '12345678000195', 'razao_social' => 'Empresa Teste LTDA', 'inscricao_estatual' => '123456789',
        'address' => 'Rua A', 'address_number' => '10', 'city' => 'São Paulo', 'state' => 'SP', 'zip_code' => '01001000',
    ]);
    $this->user->focusNfeSetting()->create(['settings' => [
        'api_key' => 'token_test_focus_123', 'base_url' => 'https://homologacao.focusnfe.com.br',
        'prestador' => ['cnpj' => '12345678000195'],
        'nfe' => ['codigo_ncm_padrao' => '61091000', 'emitente' => [
            'nome' => 'Empresa Teste LTDA', 'nome_fantasia' => 'Empresa Teste', 'logradouro' => 'Rua A',
            'numero' => '10', 'bairro' => 'Centro', 'municipio' => 'São Paulo', 'uf' => 'SP',
            'cep' => '01001000', 'inscricao_estadual' => '123456789', 'regime_tributario' => 1,
        ]],
    ]]);
    $client = Client::create([
        'user_id' => $this->user->id, 'name' => 'Cliente Teste', 'email' => 'cliente@nfe.test', 'phone' => '11999999999',
        'document_type' => 'cpf', 'document' => '52998224725',
        'address' => 'Rua B', 'address_number' => '20', 'neighborhood' => 'Centro', 'city' => 'São Paulo',
        'state' => 'SP', 'zip_code' => '01002000', 'country' => 'Brasil', 'is_active' => true,
    ]);
    $product = Product::create([
        'user_id' => $this->user->id, 'name' => 'Produto Teste', 'ncm_code' => '61091000', 'cfop_code' => '5102',
        'unit' => 'UN', 'sale_price' => 100, 'stock_quantity' => 10, 'is_active' => true,
    ]);
    $sale = Sale::create([
        'user_id' => $this->user->id, 'client_id' => $client->id, 'number' => 'V-001', 'sale_date' => now(),
        'status' => 'completed', 'payment_status' => 'paid', 'issue_invoice' => true,
        'subtotal_amount' => 100, 'total_amount' => 100,
    ]);
    SaleItem::create([
        'sale_id' => $sale->id, 'product_id' => $product->id, 'product_name' => $product->name, 'product_code' => (string) $product->id,
        'unit' => 'UN', 'quantity' => 1, 'unit_price' => 100, 'total_amount' => 100,
    ]);
    $this->sale = $sale->fresh();
});

test('emite uma NF-e e agenda o acompanhamento assíncrono', function (): void {
    Http::fake(['https://homologacao.focusnfe.com.br/v2/nfe*' => Http::response(['status' => 'processando'], 202)]);

    $response = app(EmitSaleNfeAction::class)->execute($this->sale);

    expect($response['status'])->toBe('processando');
    $this->sale->refresh();
    expect($this->sale->focus_nfe_status)->toBe('processando')
        ->and($this->sale->focus_nfe_ref)->toMatch('/^sale\d+[a-z0-9]+$/')
        ->and($this->sale->focus_nfe_payload['items'][0]['codigo_ncm'])->toBe('61091000');
    Http::assertSent(fn ($request): bool => str_contains($request->url(), '/v2/nfe?ref='));
});

test('consulta e cancela uma NF-e autorizada', function (): void {
    $this->sale->update(['focus_nfe_ref' => 'sale1abc', 'focus_nfe_status' => 'processando']);
    Http::fake([
        'https://homologacao.focusnfe.com.br/v2/nfe/sale1abc' => Http::sequence()
            ->push(['status' => 'autorizado', 'numero' => '100'], 200)
            ->push(['status' => 'cancelado'], 200),
    ]);

    app(ConsultSaleNfeAction::class)->execute($this->sale);
    $this->sale->refresh();
    expect($this->sale->focus_nfe_status)->toBe('autorizado');

    app(CancelSaleNfeAction::class)->execute($this->sale, 'Cancelamento por erro de emissão.');
    expect($this->sale->refresh()->focus_nfe_status)->toBe('cancelado');
});
