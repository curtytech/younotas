<?php

namespace App\Filament\Resources;

use App\Filament\Forms\Components\MoneyInput;

use App\Filament\Resources\ProductResource\Pages;
use App\Models\Product;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ProductResource extends CompanyOwnedResource
{
    protected static ?string $model = Product::class;

    protected static ?string $navigationIcon = 'heroicon-o-cube';

    protected static ?string $modelLabel = 'Produto';

    protected static ?string $pluralModelLabel = 'Produtos';

    protected static ?string $navigationLabel = 'Produtos';

    protected static ?string $navigationGroup = 'Cadastros Fiscais';

    protected static ?int $navigationSort = 20;

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();


        return $query;
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                static::companyField(),
                // Forms\Components\TextInput::make('code')
                //     ->required()
                //     ->string()
                //     ->minLength(2)
                //     ->maxLength(50)
                //     ->rule('regex:/^[A-Za-z0-9._\\/-]+$/')
                //     ->dehydrateStateUsing(fn (?string $state): ?string => filled($state) ? strtoupper(trim($state)) : null)
                //     ->unique(
                //         ignoreRecord: true,
                //         modifyRuleUsing: fn ($rule, callable $get) => $rule->where('company_id', $get('company_id')),
                //     )
                //     ->validationMessages([
                //         'regex' => 'O codigo deve conter apenas letras, numeros, ponto, barra, underscore ou hifen.',
                //     ])
                //     ->label('Código'),
                Forms\Components\TextInput::make('sku')
                    ->string()
                    ->maxLength(100)
                    ->rule('regex:/^[A-Za-z0-9._\\/-]+$/')
                    ->dehydrateStateUsing(fn (?string $state): ?string => filled($state) ? strtoupper(trim($state)) : null)
                    ->validationMessages([
                        'regex' => 'O SKU deve conter apenas letras, numeros, ponto, barra, underscore ou hifen.',
                    ])
                    ->label('SKU'),
                Forms\Components\TextInput::make('barcode')
                    ->string()
                    ->maxLength(14)
                    ->minLength(8)
                    ->rule('regex:/^(?:\\d{8}|\\d{12}|\\d{13}|\\d{14})$/')
                    ->dehydrateStateUsing(fn (?string $state): ?string => filled($state) ? preg_replace('/\D/', '', $state) : null)
                    ->helperText('Aceita EAN/GTIN com 8, 12, 13 ou 14 digitos.')
                    ->validationMessages([
                        'regex' => 'O codigo de barras deve conter 8, 12, 13 ou 14 numeros.',
                    ])
                    ->label('Código de barras'),
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->string()
                    ->minLength(2)
                    ->maxLength(255)
                    ->label('Nome'),
                Forms\Components\Textarea::make('description')
                    ->rows(3)
                    ->maxLength(65535)
                    ->label('Descrição'),
                Forms\Components\TextInput::make('ncm_code')
                    ->string()
                    ->length(8)
                    ->rule('regex:/^\\d{8}$/')
                    ->dehydrateStateUsing(fn (?string $state): ?string => filled($state) ? preg_replace('/\D/', '', $state) : null)
                    ->helperText('Informe os 8 digitos do NCM.')
                    ->validationMessages([
                        'regex' => 'O NCM deve conter exatamente 8 numeros.',
                    ])
                    ->label('NCM'),
                Forms\Components\TextInput::make('cfop_code')
                    ->string()
                    ->length(4)
                    ->rule('regex:/^[56]\\d{3}$/')
                    ->dehydrateStateUsing(fn (?string $state): ?string => filled($state) ? preg_replace('/\D/', '', $state) : null)
                    ->helperText('CFOP para operações internas (ex.: 5102). Para outra UF, configure o campo de CFOP interestadual.')
                    ->validationMessages([
                        'regex' => 'O CFOP deve ter 4 números e começar com 5 ou 6.',
                    ])
                    ->label('CFOP'),
                Forms\Components\TextInput::make('cest_code')
                    ->string()
                    ->length(7)
                    ->rule('regex:/^\\d{7}$/')
                    ->dehydrateStateUsing(fn (?string $state): ?string => filled($state) ? preg_replace('/\D/', '', $state) : null)
                    ->helperText('Informe os 7 digitos do CEST.')
                    ->validationMessages([
                        'regex' => 'O CEST deve conter exatamente 7 numeros.',
                    ])
                    ->label('CEST'),
                Forms\Components\TextInput::make('gtin')
                    ->string()
                    ->maxLength(14)
                    ->minLength(8)
                    ->rule('regex:/^(?:\\d{8}|\\d{12}|\\d{13}|\\d{14})$/')
                    ->dehydrateStateUsing(fn (?string $state): ?string => filled($state) ? preg_replace('/\D/', '', $state) : null)
                    ->helperText('Aceita GTIN-8, GTIN-12, GTIN-13 ou GTIN-14.')
                    ->validationMessages([
                        'regex' => 'O GTIN deve conter 8, 12, 13 ou 14 numeros.',
                    ])
                    ->label('GTIN'),
                Forms\Components\TextInput::make('cfop_interestadual')->label('CFOP interestadual')->regex('/^6[0-9]{3}$/')->maxLength(4)
                    ->helperText('Informe o código aprovado para a operação. O sistema não altera automaticamente o prefixo do CFOP.'),
                Forms\Components\Select::make('icms_origem')->label('Origem da mercadoria')->options([
                    '0' => '0 — Nacional', '1' => '1 — Estrangeira, importação direta', '2' => '2 — Estrangeira, adquirida no mercado interno',
                    '3' => '3 — Nacional, conteúdo importado acima de 40%', '4' => '4 — Nacional, processos produtivos básicos',
                    '5' => '5 — Nacional, conteúdo importado até 40%', '6' => '6 — Estrangeira, importação direta sem similar nacional',
                    '7' => '7 — Estrangeira, mercado interno sem similar nacional', '8' => '8 — Nacional, conteúdo importado acima de 70%',
                ]),
                Forms\Components\TextInput::make('pis_cst')->label('CST PIS do produto')->regex('/^[0-9]{2}$/')->maxLength(2),
                Forms\Components\TextInput::make('cofins_cst')->label('CST COFINS do produto')->regex('/^[0-9]{2}$/')->maxLength(2),
                Forms\Components\TextInput::make('unit')
                    ->default('UN')
                    ->required()
                    ->string()
                    ->minLength(1)
                    ->maxLength(20)
                    ->rule('regex:/^[A-Za-z]{1,20}$/')
                    ->dehydrateStateUsing(fn (?string $state): ?string => filled($state) ? strtoupper(trim($state)) : null)
                    ->validationMessages([
                        'regex' => 'A unidade deve conter apenas letras.',
                    ])
                    ->label('Unidade'),
                MoneyInput::make('cost_price')
                    ->numeric()
                    ->default(0)
                    ->minValue(0)
                    ->rule('decimal:0,2')
                    ->inputMode('decimal')
                    ->step('0.01')
                    ->label('Preço de custo'),
                MoneyInput::make('sale_price')
                    ->numeric()
                    ->default(0)
                    ->minValue(0)
                    ->rule('decimal:0,2')
                    ->inputMode('decimal')
                    ->step('0.01')
                    ->label('Preço de venda'),
                Forms\Components\TextInput::make('stock_quantity')
                    ->numeric()
                    ->default(0)
                    ->minValue(0)
                    ->rule('decimal:0,3')
                    ->inputMode('decimal')
                    ->step('0.001')
                    ->label('Saldo em estoque'),
                Forms\Components\TextInput::make('minimum_stock')
                    ->numeric()
                    ->default(0)
                    ->minValue(0)
                    ->rule('decimal:0,3')
                    ->inputMode('decimal')
                    ->step('0.001')
                    ->label('Estoque mínimo'),
                Forms\Components\TextInput::make('icms_aliquot')
                    ->numeric()
                    ->default(0)
                    ->minValue(0)
                    ->maxValue(100)
                    ->rule('decimal:0,2')
                    ->inputMode('decimal')
                    ->step('0.01')
                    ->label('Alíquota ICMS'),
                Forms\Components\TextInput::make('ipi_aliquot')
                    ->numeric()
                    ->default(0)
                    ->minValue(0)
                    ->maxValue(100)
                    ->rule('decimal:0,2')
                    ->inputMode('decimal')
                    ->step('0.01')
                    ->label('Alíquota IPI'),
                Forms\Components\TextInput::make('pis_aliquot')
                    ->numeric()
                    ->default(0)
                    ->minValue(0)
                    ->maxValue(100)
                    ->rule('decimal:0,2')
                    ->inputMode('decimal')
                    ->step('0.01')
                    ->label('Alíquota PIS'),
                Forms\Components\TextInput::make('cofins_aliquot')
                    ->numeric()
                    ->default(0)
                    ->minValue(0)
                    ->maxValue(100)
                    ->rule('decimal:0,2')
                    ->inputMode('decimal')
                    ->step('0.01')
                    ->label('Alíquota COFINS'),
                Forms\Components\Toggle::make('is_active')
                    ->default(true)
                    ->label('Ativo'),
                Forms\Components\Textarea::make('notes')
                    ->rows(3)
                    ->maxLength(65535)
                    ->label('Observações'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('user.name')
                    ->label('Usuário')
                    ->sortable()
                    ->visible(auth()->user()->role === 'admin'),
                Tables\Columns\TextColumn::make('id')
                    ->searchable()
                    ->sortable()
                    ->label('Código'),
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->label('Produto'),
                Tables\Columns\TextColumn::make('barcode')
                    ->searchable()
                    ->label('Código de barras'),
                Tables\Columns\TextColumn::make('stock_quantity')
                    ->numeric(decimalPlaces: 3)
                    ->label('Estoque'),
                Tables\Columns\TextColumn::make('sale_price')
                    ->money('BRL')
                    ->label('Preço'),
                Tables\Columns\IconColumn::make('is_active')
                    ->boolean()
                    ->label('Ativo'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('user_id')
                    ->relationship('user', 'name')
                    ->label('Usuário')
                    ->visible(fn (): bool => auth()->user()->role === 'admin'),
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Ativo'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function canCreate(): bool
    {
        return parent::canCreate();
    }

    public static function canEdit(Model $record): bool
    {
        return static::canManageCompanyRecord($record);
    }

    public static function canDelete(Model $record): bool
    {
        return static::canManageCompanyRecord($record);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProducts::route('/'),
            'create' => Pages\CreateProduct::route('/create'),
            'edit' => Pages\EditProduct::route('/{record}/edit'),
        ];
    }
}
