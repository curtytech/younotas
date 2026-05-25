<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ProductResource\Pages;
use App\Models\Product;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ProductResource extends Resource
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

        if (auth()->user()->role !== 'admin') {
            $query->where('user_id', auth()->id());
        }

        return $query;
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                auth()->user()->role === 'admin'
                    ? Forms\Components\Select::make('user_id')
                        ->relationship('user', 'name')
                        ->searchable()
                        ->preload()
                        ->default(auth()->id())
                        ->required()
                        ->label('Usuário')
                    : Forms\Components\Hidden::make('user_id')
                        ->default(auth()->id()),
                // Forms\Components\TextInput::make('code')
                //     ->required()
                //     ->string()
                //     ->minLength(2)
                //     ->maxLength(50)
                //     ->rule('regex:/^[A-Za-z0-9._\\/-]+$/')
                //     ->dehydrateStateUsing(fn (?string $state): ?string => filled($state) ? strtoupper(trim($state)) : null)
                //     ->unique(
                //         ignoreRecord: true,
                //         modifyRuleUsing: fn ($rule, callable $get) => $rule->where('user_id', $get('user_id') ?: auth()->id()),
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
                Forms\Components\TextInput::make('cost_price')
                    ->numeric()
                    ->default(0)
                    ->minValue(0)
                    ->rule('decimal:0,2')
                    ->inputMode('decimal')
                    ->step('0.01')
                    ->label('Preço de custo'),
                Forms\Components\TextInput::make('sale_price')
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
                Tables\Columns\TextColumn::make('code')
                    ->searchable()
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
        return auth()->check();
    }

    public static function canEdit(Model $record): bool
    {
        return auth()->user()->role === 'admin' || $record->user_id === auth()->id();
    }

    public static function canDelete(Model $record): bool
    {
        return auth()->user()->role === 'admin' || $record->user_id === auth()->id();
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
