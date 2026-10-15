<?php

use App\Models\Client;
use App\Models\Company;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderItem;
use App\Models\User;
use App\Services\FocusDanfePreviewService;
use App\Services\FocusNfseService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Http;

function fiscalPayloadFixture(): array
{
    $company = new Company(['name' => 'Empresa de Teste']);
    $company->id = 1;
    $user = new User(['name' => 'Operador', 'role' => 'enterprise']);
    $user->id = 1;
    $client = new Client([
        'company_id' => 1, 'user_id' => 1, 'name' => 'Cliente', 'document_type' => 'cnpj',
        'document' => '04252011000110', 'address' => 'Rua B', 'address_number' => '10',
        'neighborhood' => 'Centro', 'city' => 'São Paulo', 'state' => 'SP', 'ibge_code' => '3550308',
        'zip_code' => '01001000', 'country' => 'BR', 'is_active' => true,
    ]);
    $product = new Product([
        'company_id' => 1, 'user_id' => 1, 'name' => 'Produto', 'ncm_code' => '61091000',
        'cfop_code' => '5102', 'unit' => 'UN', 'icms_aliquot' => 0, 'pis_aliquot' => 0, 'cofins_aliquot' => 0,
    ]);
    $product->id = 1;
    $item = new SaleItem(['product_name' => 'Produto', 'unit' => 'UN', 'quantity' => 1, 'unit_price' => 100, 'total_amount' => 100]);
    $item->setRelation('product', $product);
    $sale = new Sale(['company_id' => 1, 'user_id' => 1, 'subtotal_amount' => 100, 'total_amount' => 100]);
    $sale->setRelation('company', $company)->setRelation('user', $user)->setRelation('client', $client)
        ->setRelation('saleItems', new Collection([$item]));
    $serviceItem = new ServiceOrderItem([
        'service_name' => 'Serviço', 'quantity' => 1, 'unit_price' => 100, 'total_amount' => 100,
        'lc116_code' => '0107', 'municipal_service_code' => '0107', 'cnae_code' => '6201501',
        'nbs_code' => '115012000', 'iss_aliquot' => 5,
    ]);
    $order = new ServiceOrder(['company_id' => 1, 'user_id' => 1, 'total_amount' => 100]);
    $order->setRelation('client', $client)->setRelation('items', new Collection([$serviceItem]));
    $config = [
        'api_key' => 'test-token', 'base_url' => 'https://homologacao.focusnfe.com.br', 'company_id' => 1,
        'prestador' => ['cnpj' => '12345678000195', 'inscricao_municipal' => '12345', 'codigo_municipio' => '3550308'],
        'nfe' => ['icms_situacao_tributaria' => '102', 'cfop_padrao' => '5102', 'emitente' => [
            'nome' => 'Empresa de Teste', 'logradouro' => 'Rua A', 'numero' => '1', 'bairro' => 'Centro',
            'municipio' => 'São Paulo', 'uf' => 'SP', 'cep' => '01001000',
            'inscricao_estadual' => '123456789', 'regime_tributario' => 1,
        ]],
    ];

    return [$sale, $order, $config, $product, $client];
}

beforeEach(function (): void {
    Http::preventStrayRequests();
});

test('NF-e preserva IE e indicador fiscal do destinatário contribuinte', function (): void {
    [$sale, , $config, , $client] = fiscalPayloadFixture();
    $client->fill(['inscricao_estatual' => '123.456.789', 'indicador_ie' => '1']);

    $payload = app(FocusDanfePreviewService::class)->buildPayload($sale, $config);

    expect($payload['indicador_inscricao_estadual_destinatario'])->toBe('1')
        ->and($payload['inscricao_estadual_destinatario'])->toBe('123456789');
});

test('NF-e recusa indicador contribuinte sem IE', function (): void {
    [$sale, , $config, , $client] = fiscalPayloadFixture();
    $client->indicador_ie = '1';

    expect(fn () => app(FocusDanfePreviewService::class)->buildPayload($sale, $config))
        ->toThrow(RuntimeException::class, 'IE do destinatário');
});

test('NF-e não converte CFOP interno para interestadual implicitamente', function (): void {
    [$sale, , $config, $product, $client] = fiscalPayloadFixture();
    $product->cfop_code = '5405';
    $client->state = 'RJ';

    expect(fn () => app(FocusDanfePreviewService::class)->buildPayload($sale, $config))
        ->toThrow(RuntimeException::class, 'CFOP interestadual explícito');

    $product->cfop_interestadual = '6404';
    $payload = app(FocusDanfePreviewService::class)->buildPayload($sale, $config);
    expect($payload['items'][0]['cfop'])->toBe('6404')->and($payload['local_destino'])->toBe(2);
});

test('NF-e usa situações e alíquotas fiscais por produto', function (): void {
    [$sale, , $config, $product] = fiscalPayloadFixture();
    $product->fill(['cst_code' => '400', 'icms_origem' => '2', 'pis_cst' => '01', 'pis_aliquot' => '1.65', 'cofins_cst' => '01', 'cofins_aliquot' => '7.60']);
    $sale->saleItems->first()->fill(['pis_base_calculo' => 100, 'cofins_base_calculo' => 80]);

    $item = app(FocusDanfePreviewService::class)->buildPayload($sale, $config)['items'][0];

    expect($item['icms_situacao_tributaria'])->toBe('400')->and($item['icms_origem'])->toBe('2')
        ->and($item['pis_aliquota_porcentual'])->toBe('1.65')->and($item['pis_valor'])->toBe('1.65')
        ->and($item['cofins_valor'])->toBe('6.08');
});

test('NF-e recusa CSOSN incompatível com CRT normal', function (): void {
    [$sale, , $config] = fiscalPayloadFixture();
    $config['nfe']['emitente']['regime_tributario'] = 3;

    expect(fn () => app(FocusDanfePreviewService::class)->buildPayload($sale, $config))
        ->toThrow(RuntimeException::class, 'incompatível com o CRT');
});

test('NF-e recusa combinações incompatíveis com CRT MEI', function (string $cst, string $cfop): void {
    [$sale, , $config, $product] = fiscalPayloadFixture();
    $config['nfe']['emitente']['regime_tributario'] = 4;
    $product->fill(['cst_code' => $cst, 'cfop_code' => $cfop]);

    expect(fn () => app(FocusDanfePreviewService::class)->buildPayload($sale, $config))
        ->toThrow(RuntimeException::class, 'MEI');
})->with([['103', '5102'], ['102', '5101']]);

test('NF-e usa alíquota ICMS do produto em CST 00 suportado', function (): void {
    [$sale, , $config, $product] = fiscalPayloadFixture();
    $config['nfe']['emitente']['regime_tributario'] = 3;
    $product->fill(['cst_code' => '00', 'icms_aliquot' => 18]);
    $sale->saleItems->first()->icms_base_calculo = 100;

    $item = app(FocusDanfePreviewService::class)->buildPayload($sale, $config)['items'][0];

    expect($item['icms_situacao_tributaria'])->toBe('00')->and($item['icms_aliquota'])->toBe('18.00')
        ->and($item['icms_valor'])->toBe('18.00');
});

test('NF-e bloqueia perfil tributário que exigiria campos ainda não suportados', function (): void {
    [$sale, , $config, $product] = fiscalPayloadFixture();
    $product->cst_code = '201';

    expect(fn () => app(FocusDanfePreviewService::class)->buildPayload($sale, $config))
        ->toThrow(RuntimeException::class, 'ainda não suportado');
});

test('NF-e exige base explícita quando há destaque positivo', function (string $tax): void {
    [$sale, , $config, $product] = fiscalPayloadFixture();
    if ($tax === 'icms') {
        $config['nfe']['emitente']['regime_tributario'] = 3;
        $product->cst_code = '00';
    } else {
        $product->{$tax.'_cst'} = '01';
    }
    $product->{$tax.'_aliquot'} = 1;

    expect(fn () => app(FocusDanfePreviewService::class)->buildPayload($sale, $config))
        ->toThrow(RuntimeException::class, 'base de cálculo de '.strtoupper($tax));
})->with(['icms', 'pis', 'cofins']);

test('NF-e recusa base tributária negativa ou superior ao item', function (float $base): void {
    [$sale, , $config, $product] = fiscalPayloadFixture();
    $product->fill(['pis_cst' => '01', 'pis_aliquot' => 1]);
    $sale->saleItems->first()->pis_base_calculo = $base;

    expect(fn () => app(FocusDanfePreviewService::class)->buildPayload($sale, $config))
        ->toThrow(RuntimeException::class, 'valor líquido do item');
})->with([-1.0, 100.01]);

test('NF-e valida empresa do cliente e dos produtos mesmo fora da interface', function (string $target): void {
    [$sale, , $config, $product, $client] = fiscalPayloadFixture();
    $model = $target === 'client' ? $client : $product;
    $model->company_id = 2;

    expect(fn () => app(FocusDanfePreviewService::class)->buildPayload($sale, $config))
        ->toThrow(RuntimeException::class, 'empresa da venda');
})->with(['client', 'product']);

test('NFS-e usa retenção e município da prestação definidos na O.S.', function (): void {
    [, $order, $config] = fiscalPayloadFixture();
    $order->fill(['iss_retido' => true, 'codigo_municipio_prestacao' => '3304557']);

    $payload = app(FocusNfseService::class)->buildPayload($order, $config);

    expect($payload['servico']['iss_retido'])->toBeTrue()
        ->and($payload['servico']['codigo_municipio'])->toBe('3304557')
        ->and($payload['servico']['valor_iss'])->toBe(5.0);
});

test('NFS-e recusa itens com atributos incompatíveis para um único grupo de serviço', function (string $field, mixed $value): void {
    [, $order, $config] = fiscalPayloadFixture();
    $other = clone $order->items->first();
    $other->{$field} = $value;
    $order->items->push($other);

    expect(fn () => app(FocusNfseService::class)->buildPayload($order, $config))
        ->toThrow(RuntimeException::class, 'compatíveis');
})->with([['iss_aliquot', 3], ['cnae_code', '6202300'], ['nbs_code', null], ['municipal_service_code', '0201']]);

test('NFS-e recusa retenção sem alíquota ISS', function (): void {
    [, $order, $config] = fiscalPayloadFixture();
    $order->iss_retido = true;
    $order->items->first()->iss_aliquot = 0;

    expect(fn () => app(FocusNfseService::class)->buildPayload($order, $config))
        ->toThrow(RuntimeException::class, 'alíquota ISS');
});
