<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\Product;
use App\Models\Quote;
use App\Models\QuoteItem;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Service;
use App\Models\User;
use App\Services\FocusNfeConfigService;
use Illuminate\Database\Seeder;

/**
 * Orçamentos de exemplo e cenários de borda. Reexecutável (chaves naturais) e só para dev/teste.
 * Roda sob WithoutModelEvents: totais e vínculos são calculados aqui, não pelos observers.
 */
class QuoteSeeder extends Seeder
{
    public const STRESS_PREFIX = 'ORC-STRESS-';

    public function run(): void
    {
        if (app()->environment('production')) {
            return;
        }

        $user = User::query()->where('email', 'test@example.com')->first();

        if (! $user) {
            return;
        }

        $company = app(FocusNfeConfigService::class)->ensureCompanyForUser($user);

        $client = $this->client($user, $company->id, 'cliente.orcamento@example.com', 'Maria da Conceição Álvares Müller');
        $longClient = $this->client($user, $company->id, 'cliente.longo@example.com', 'Associação Beneficente e Cultural dos Amigos do Bairro São João D\'Água Limpa de Santa Rita do Passa Quatro Ltda', '12345678000195', 'cnpj');

        $caneta = $this->product($user, $company->id, 'ORC-PROD-001', 'Caneta esferográfica azul 1.0mm', '12.50', 500);
        $caixa = $this->product($user, $company->id, 'ORC-PROD-002', 'Caixa de papel sulfite A4 75g — 500 folhas (ção/ñ/ü)', '29.90', 40);
        $extremo = $this->product($user, $company->id, 'ORC-PROD-003', 'Lote industrial de parafusos', '0.01', 9999999);
        $descontinuado = $this->product($user, $company->id, 'ORC-PROD-004', 'Produto que será removido do catálogo', '45.00', 3);
        $consultoria = $this->service($user, $company->id, 'ORC-SERV-001', 'Consultoria técnica em instalação elétrica', '350.00');
        $instalacao = $this->service($user, $company->id, 'ORC-SERV-002', 'Instalação e configuração de equipamento', '1200.00');

        $this->quote($company->id, $user, $client, 'ORC-SEED-001', 'draft', null, '0', 'Somente produtos, sem validade.', [
            [$caneta, 10], [$caixa, 3],
        ]);
        $this->quote($company->id, $user, $client, 'ORC-SEED-002', 'sent', today()->addDays(15), '25.00', "Somente serviços.\nPagamento em 3x, garantia de 90 dias.", [
            [$consultoria, 2], [$instalacao, 1],
        ]);
        $this->quote($company->id, $user, $longClient, 'ORC-SEED-003', 'sent', today()->addMonth(), '10.00', null, [
            [$caneta, 100], [$consultoria, 1],
        ]);
        $this->quote($company->id, $user, $client, 'ORC-SEED-004', 'sent', today()->subDays(20), '0', 'Vencido e ainda não marcado como expirado.', [
            [$caixa, 1],
        ], today()->subDays(40));
        $this->quote($company->id, $user, $client, 'ORC-SEED-005', 'expired', today()->subDays(5), '0', null, [[$caneta, 1]], today()->subDays(30));
        $this->quote($company->id, $user, $client, 'ORC-SEED-006', 'rejected', today()->addDays(3), '0', 'Cliente recusou o preço.', [[$caixa, 2]]);
        $this->quote($company->id, $user, $longClient, 'ORC-SEED-007', 'approved', today()->addDays(10), '5.00', 'Já convertido em venda.', [
            [$caneta, 4], [$consultoria, 1],
        ], null, true);

        $orphan = $this->quote($company->id, $user, $client, 'ORC-SEED-008', 'sent', today()->addDays(7), '0', 'Produto removido do catálogo: não converte.', [
            [$descontinuado, 2], [$caneta, 1],
        ]);
        QuoteItem::query()->where('quote_id', $orphan->id)->where('name', $descontinuado->name)->update(['product_id' => null]);

        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        $this->quote($company->id, $user, $longClient, self::STRESS_PREFIX.'LONGO', 'draft', null, '0',
            str_repeat('Condições comerciais detalhadas com acentuação — ação, coração, açaí, pão. ', 40)
            .str_repeat('SemEspaçosPalavraExtremamenteLongaParaTestarQuebraDeTexto', 6), [
                [$extremo, '9999.999'],
            ]);

        $many = [];
        foreach (range(1, 40) as $number) {
            $many[] = [$number % 2 === 0 ? $caneta : $consultoria, $number];
        }
        $this->quote($company->id, $user, $client, self::STRESS_PREFIX.'MUITOS-ITENS', 'sent', today()->addYear(), '0', null, $many);
    }

    private function client(User $user, int $companyId, string $email, string $name, string $document = '52998224725', string $type = 'cpf'): Client
    {
        return Client::query()->updateOrCreate(['company_id' => $companyId, 'email' => $email], [
            'user_id' => $user->id, 'name' => $name, 'document_type' => $type, 'document' => $document,
            'phone' => '21967183356', 'address' => 'Rua das Acácias', 'address_number' => '120', 'neighborhood' => 'Jardim América',
            'city' => 'Rio de Janeiro', 'ibge_code' => '3304557', 'state' => 'RJ', 'zip_code' => '20040020', 'country' => 'BR', 'is_active' => true,
        ]);
    }

    private function product(User $user, int $companyId, string $sku, string $name, string $price, int|float $stock): Product
    {
        return Product::query()->updateOrCreate(['company_id' => $companyId, 'sku' => $sku], [
            'user_id' => $user->id, 'name' => $name, 'unit' => 'UN', 'ncm_code' => '48202000', 'cfop_code' => '5102',
            'cost_price' => 0, 'sale_price' => $price, 'stock_quantity' => $stock, 'minimum_stock' => 0,
            'icms_aliquot' => 0, 'ipi_aliquot' => 0, 'pis_aliquot' => 0, 'cofins_aliquot' => 0, 'is_active' => true,
        ]);
    }

    private function service(User $user, int $companyId, string $code, string $name, string $price): Service
    {
        return Service::query()->updateOrCreate(['company_id' => $companyId, 'code' => $code], [
            'user_id' => $user->id, 'name' => $name, 'municipal_service_code' => '0107', 'lc116_code' => '0107',
            'unit' => 'UN', 'unit_price' => $price, 'iss_aliquot' => 5, 'is_active' => true,
        ]);
    }

    /** @param array<int, array{0: Product|Service, 1: int|string}> $lines */
    private function quote(int $companyId, User $user, Client $client, string $number, string $status, $validUntil, string $discount, ?string $notes, array $lines, $date = null, bool $convert = false): Quote
    {
        $quote = Quote::query()->firstOrNew(['company_id' => $companyId, 'number' => $number]);
        $quote->fill([
            'user_id' => $user->id, 'client_id' => $client->id, 'quote_date' => $date ?? today(),
            'valid_until' => $validUntil, 'status' => $status, 'discount_amount' => $discount, 'notes' => $notes,
        ]);
        $quote->save();

        $quote->items()->delete();
        $subtotal = 0.0;
        $productTotal = 0.0;

        foreach ($lines as [$catalog, $quantity]) {
            $isProduct = $catalog instanceof Product;
            $price = (float) ($isProduct ? $catalog->sale_price : $catalog->unit_price);
            $total = round((float) $quantity * $price, 2);
            $subtotal += $total;
            $productTotal += $isProduct ? $total : 0;

            QuoteItem::query()->create([
                'quote_id' => $quote->id, 'item_type' => $isProduct ? 'product' : 'service',
                'product_id' => $isProduct ? $catalog->id : null, 'service_id' => $isProduct ? null : $catalog->id,
                'name' => $catalog->name, 'unit' => 'UN', 'quantity' => $quantity, 'unit_price' => $price, 'total_amount' => $total,
            ]);
        }

        $quote->forceFill(['total_amount' => max(round($subtotal - (float) $discount, 2), 0)])->saveQuietly();

        if ($convert && ! $quote->sale_id) {
            $sale = Sale::query()->firstOrCreate(['company_id' => $companyId, 'number' => 'SALE-'.$number], [
                'user_id' => $user->id, 'client_id' => $client->id, 'sale_date' => today()->toDateString(),
                'status' => 'pending', 'payment_status' => 'pending', 'issue_invoice' => false,
                'discount_amount' => 0, 'tax_amount' => 0, 'subtotal_amount' => $productTotal, 'total_amount' => $productTotal,
                'notes' => 'Gerada a partir do orçamento '.$number.'.',
            ]);

            foreach ($quote->items()->where('item_type', 'product')->get() as $item) {
                SaleItem::query()->firstOrCreate(['sale_id' => $sale->id, 'product_id' => $item->product_id], [
                    'product_name' => $item->name, 'product_code' => (string) $item->product_id, 'unit' => $item->unit,
                    'quantity' => $item->quantity, 'unit_price' => $item->unit_price, 'discount_amount' => 0,
                    'tax_amount' => 0, 'total_amount' => $item->total_amount,
                ]);
            }

            $quote->forceFill(['sale_id' => $sale->id])->saveQuietly();
        }

        return $quote;
    }
}
