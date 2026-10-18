<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\FiscalDocument;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use RuntimeException;

class CatalogCsvImportService
{
    public function __construct(private FocusMunicipioService $municipioService) {}

    public function import(string|int $companyName, string $clientsPath, string $productsPath, string $reportPath, bool $fillMissing = false): array
    {
        $clients = $this->read($clientsPath, ['Nome', 'CPF', 'CNPJ', 'Email principal']);
        $products = $this->read($productsPath, ['Codigo', 'Nome do Produto', 'Valor de Venda', 'Qt. Estoque']);
        $companies = User::query()
            ->where(is_int($companyName) ? 'id' : 'name', $companyName)
            ->where('role', 'enterprise')->get();
        if ($companies->count() !== 1) {
            throw new RuntimeException('A empresa de destino deve existir e ser unica: '.$companyName);
        }
        $user = $companies->first();
        $municipalities = [];
        $report = ['user_id' => $user->id, 'clients' => [], 'products' => []];

        // Resolve each municipality once, outside the database transaction.
        foreach ($clients as $row) {
            $city = $row['Cidade'] ?? '';
            $state = $row['Estado'] ?? '';
            $key = $city.'|'.$state;
            if (! array_key_exists($key, $municipalities)) {
                $municipalities[$key] = $this->municipioService->ibgeCodeFor($user, $city, $state);
            }
        }

        if ($fillMissing && in_array(null, $municipalities, true)) {
            $response = Http::acceptJson()->connectTimeout(10)->timeout(60)->retry(2, 500)
                ->get('https://servicodados.ibge.gov.br/api/v1/localidades/municipios', ['view' => 'nivelado'])->throw();
            $official = [];
            foreach ($response->json() as $city) {
                $key = Str::upper(Str::ascii($city['municipio-nome'].'|'.$city['UF-sigla']));
                $official[$key] = (string) $city['municipio-id'];
            }
            foreach ($municipalities as $key => $code) {
                $municipalities[$key] = $code ?? ($official[Str::upper(Str::ascii($key))] ?? null);
            }
        }

        DB::transaction(function () use ($user, $clients, $products, $municipalities, &$report, $reportPath, $fillMissing): void {
            foreach ($clients as $line => $row) {
                $report['clients'][] = $this->importClient($user, $row, $line, $municipalities, $fillMissing);
            }
            foreach ($products as $line => $row) {
                $report['products'][] = $this->importProduct($user, $row, $line);
            }
            File::ensureDirectoryExists(dirname($reportPath));
            if (File::put($reportPath, json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)) === false) {
                throw new RuntimeException('Nao foi possivel gravar o relatorio da importacao.');
            }
        });

        return $report;
    }

    private function read(string $path, array $required): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException('Arquivo CSV ausente ou ilegivel: '.$path);
        }
        $contents = File::get($path);
        if (! mb_check_encoding($contents, 'UTF-8')) {
            $contents = mb_convert_encoding($contents, 'UTF-8', 'Windows-1252');
        }
        $stream = fopen('php://temp', 'w+');
        try {
            fwrite($stream, preg_replace('/^\xEF\xBB\xBF/', '', $contents));
            rewind($stream);
            $headers = fgetcsv($stream, 0, ';', '"', '');
            if ($headers === false) {
                throw new RuntimeException('CSV vazio: '.$path);
            }
            $headers = array_map(fn (string $header): string => Str::ascii(trim($header)), $headers);
            if (array_diff($required, $headers) !== []) {
                throw new RuntimeException('Colunas obrigatorias ausentes no CSV: '.$path);
            }
            $rows = [];
            $line = 1;
            while (($values = fgetcsv($stream, 0, ';', '"', '')) !== false) {
                $line++;
                if ($values === [null]) {
                    continue;
                }
                if (count($values) > count($headers) && ($headers[2] ?? '') === 'Nome do Produto') {
                    $priceIndex = count($values) - (count($headers) - 3);
                    // Legacy products export has unquoted semicolons inside names.
                    if (str_starts_with(trim((string) ($values[$priceIndex] ?? '')), 'R$')) {
                        $values = [...array_slice($values, 0, 2), implode(';', array_slice($values, 2, $priceIndex - 2)), ...array_slice($values, $priceIndex)];
                    }
                }
                if (count($values) !== count($headers)) {
                    throw new RuntimeException("CSV com colunas desalinhadas na linha {$line}: {$path}");
                }
                $rows[$line] = array_combine($headers, array_map(fn (?string $value): string => trim((string) $value), $values));
            }

            return $rows;
        } finally {
            fclose($stream);
        }
    }

    private function importClient(User $user, array $row, int $line, array $municipalities, bool $fillMissing): array
    {
        $type = ! empty($row['CNPJ']) ? 'cnpj' : (! empty($row['CPF']) ? 'cpf' : 'nif');
        $document = $type === 'nif' ? Str::upper($row['ID Estrangeiro'] ?? '') : FiscalDocument::digits($row[$type === 'cpf' ? 'CPF' : 'CNPJ']);
        $data = [
            'user_id' => $user->id,
            'name' => ($row['Razao Social'] ?? '') ?: $row['Nome'],
            'email' => Str::lower(($row['Email principal'] ?? '') ?: ($row['E-mail Contato'] ?? '')),
            'phone' => $row['Telefone principal'] ?? '',
            'document_type' => $type,
            'document' => $document,
            'inscricao_estatual' => ($row['Inscricao Estadual'] ?? '') ?: null,
            'address' => $row['Endereco'] ?? '',
            'address_number' => ($row['Numero'] ?? '') ?: null,
            'address_complement' => ($row['Complemento'] ?? '') ?: null,
            'neighborhood' => ($row['Bairro'] ?? '') ?: null,
            'city' => $row['Cidade'] ?? '',
            'state' => Str::upper($row['Estado'] ?? '') ?: null,
            'zip_code' => ($row['CEP'] ?? '') ?: null,
            'country' => 'BR',
            'ibge_code' => $municipalities[($row['Cidade'] ?? '').'|'.($row['Estado'] ?? '')] ?? null,
            'is_active' => Str::lower($row['Status'] ?? '') === 'ativado',
        ];
        $provisional = [];
        if ($fillMissing) {
            $sourceKey = substr(hash('sha256', json_encode($row, JSON_THROW_ON_ERROR)), 0, 24);
            foreach ([
                'email' => "cliente-{$user->id}-{$sourceKey}@cadastro.invalid",
                'phone' => 'Nao informado',
                'address' => 'Endereco provisorio '.$sourceKey,
            ] as $field => $value) {
                if (blank($data[$field])) {
                    $data[$field] = $value;
                    $provisional[] = $field;
                }
            }
            if ($provisional !== []) {
                $data['is_active'] = false;
            }
        }
        $errors = Validator::make($data, [
            'name' => ['required', 'string', 'min:3', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['required', 'string', 'min:8', 'max:30'],
            'document' => ['required', 'string', 'min:3', 'max:255'],
            'inscricao_estatual' => ['nullable', 'string', 'min:3', 'max:15'],
            'address' => ['required', 'string', 'min:3', 'max:255'],
            'address_number' => ['nullable', 'string', 'max:50'],
            'address_complement' => ['nullable', 'string', 'max:255'],
            'neighborhood' => ['nullable', 'string', 'max:255'],
            'city' => ['required', 'string', 'min:2', 'max:255'],
            'state' => ['nullable', 'string', 'size:2'],
            'zip_code' => ['nullable', 'string', 'max:20'],
            'ibge_code' => ['required', 'regex:/^\d{7}$/'],
        ])->errors()->keys();
        $valid = match ($type) {
            'cpf' => FiscalDocument::cpfIsValid($document),
            'cnpj' => FiscalDocument::cnpjIsValid($document),
            default => (bool) preg_match('/^[A-Z0-9\-\.\/]{5,20}$/', $document),
        };
        if (! $valid) {
            $errors[] = 'document';
        }
        if ($errors !== []) {
            return ['line' => $line, 'status' => 'rejected', 'fields' => array_values(array_unique($errors))];
        }
        $existing = Client::query()->where('user_id', $user->id)->where(function ($query) use ($data): void {
            $query->where('email', $data['email'])->orWhere(function ($query) use ($data): void {
                $query->where('document_type', $data['document_type'])->where('document', $data['document']);
            });
        })->exists();
        if ($existing) {
            return ['line' => $line, 'status' => 'skipped', 'fields' => ['email_or_document']];
        }
        $client = new Client;
        // The database and form use this spelling; it is absent from the model fillable.
        $client->forceFill($data)->save();

        return ['line' => $line, 'status' => 'created', 'id' => $client->id, 'provisional_fields' => $provisional];
    }

    private function importProduct(User $user, array $row, int $line): array
    {
        $data = [
            'user_id' => $user->id,
            'sku' => Str::upper($row['Codigo']),
            'barcode' => ($row['EAN'] ?? '') ?: null,
            'name' => $row['Nome do Produto'],
            'unit' => 'UN',
            'sale_price' => $this->decimal($row['Valor de Venda']),
            'stock_quantity' => $this->decimal($row['Qt. Estoque']),
            'minimum_stock' => $this->decimal($row['Qt. Minima'] ?? ''),
            'is_active' => true,
            'notes' => ($row['Observacao'] ?? '') ?: null,
        ];
        $errors = Validator::make($data, [
            'sku' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9._\/-]+$/'],
            'name' => ['required', 'string', 'min:2', 'max:255'],
            'barcode' => ['nullable', 'regex:/^(?:\d{8}|\d{12}|\d{13}|\d{14})$/'],
            'sale_price' => ['required', 'numeric', 'min:0', 'max:99999999.99', 'decimal:0,2'],
            'stock_quantity' => ['required', 'numeric', 'min:0', 'max:999999999999.999', 'decimal:0,3'],
            'minimum_stock' => ['required', 'numeric', 'min:0', 'max:999999999999.999', 'decimal:0,3'],
            'notes' => ['nullable', 'string', 'max:65535'],
        ])->errors()->keys();
        if ($errors !== []) {
            return ['line' => $line, 'status' => 'rejected', 'fields' => $errors];
        }
        if (Product::query()->where('user_id', $user->id)->where('sku', $data['sku'])->exists()) {
            return ['line' => $line, 'status' => 'skipped', 'fields' => ['sku']];
        }
        $product = Product::query()->create($data);
        if ((float) $product->stock_quantity > 0) {
            StockMovement::query()->create([
                'user_id' => $user->id,
                'product_id' => $product->id,
                'movement_type' => 'entry',
                'source_type' => 'inventory_adjustment',
                'reference' => 'CSV-INITIAL-'.$product->id,
                'quantity' => $product->stock_quantity,
                'previous_stock' => 0,
                'current_stock' => $product->stock_quantity,
                'unit_cost' => 0,
                'notes' => 'Saldo inicial importado de products.csv.',
                'moved_at' => now(),
            ]);
        }

        return ['line' => $line, 'status' => 'created', 'id' => $product->id];
    }

    private function decimal(string $value): string
    {
        $value = str_replace(['R$', ' ', "\xc2\xa0"], '', $value);
        if (str_contains($value, ',')) {
            $value = str_replace(',', '.', str_replace('.', '', $value));
        }

        return $value === '' ? '0' : $value;
    }
}
