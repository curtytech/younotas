<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SaleResource\Pages;
use App\Filament\Resources\SaleResource\RelationManagers\SaleItemsRelationManager;
use App\Models\Product;
use App\Models\Sale;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class SaleResource extends Resource
{
    protected static ?string $model = Sale::class;

    protected static ?string $navigationIcon = 'heroicon-o-shopping-cart';

    protected static ?string $modelLabel = 'Venda';

    protected static ?string $pluralModelLabel = 'Vendas';

    protected static ?string $navigationLabel = 'Vendas';

    protected static ?string $navigationGroup = 'Comercial';

    protected static ?int $navigationSort = 10;

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
            ->columns(12)
            ->schema([
                auth()->user()->role === 'admin'
                    ? Forms\Components\Select::make('user_id')
                        ->relationship('user', 'name')
                        ->searchable()
                        ->preload()
                        ->default(auth()->id())
                        ->required()
                        ->columnSpan(3)
                        ->label('Usuário')
                    : Forms\Components\Hidden::make('user_id')
                        ->default(auth()->id()),
                Forms\Components\Select::make('client_id')
                    ->relationship('client', 'name', function (Builder $query) {
                        if (auth()->user()->role !== 'admin') {
                            $query->where('user_id', auth()->id());
                        }
                    })
                    ->searchable()
                    ->preload()
                    ->native(false)
                    ->columnSpan(9)
                    ->label('Cliente'),
                Forms\Components\DatePicker::make('sale_date')
                    ->required()
                    ->default(now())
                    ->maxDate(now())
                    ->columnSpan(3)
                    ->label('Data da venda'),
                Forms\Components\Select::make('status')
                    ->options([
                        'draft' => 'Rascunho',
                        'pending' => 'Pendente',
                        'completed' => 'Concluída',
                        'canceled' => 'Cancelada',
                    ])
                    ->default('draft')
                    ->required()
                    ->native(false)
                    ->columnSpan(3)
                    ->label('Status'),
                Forms\Components\Select::make('payment_status')
                    ->options([
                        'pending' => 'Pendente',
                        'partial' => 'Parcial',
                        'paid' => 'Pago',
                        'refunded' => 'Estornado',
                        'canceled' => 'Cancelado',
                    ])
                    ->default('pending')
                    ->required()
                    ->native(false)
                    ->columnSpan(3)
                    ->label('Status do pagamento'),
                Forms\Components\Toggle::make('issue_invoice')
                    ->default(false)
                    ->inline(false)
                    ->columnSpan(3)
                    ->label('Emitir nota fiscal'),
                Forms\Components\Repeater::make('saleItems')
                    ->relationship()
                    ->label('Produtos da venda')
                    ->visible(fn (string $operation): bool => $operation === 'create')
                    ->defaultItems(1)
                    ->minItems(1)
                    ->reorderable(false)
                    ->collapsible()
                    ->live()
                    ->afterStateUpdated(function (?array $state, callable $get, callable $set): void {
                        static::updateSaleTotals($state ?? [], $get, $set);
                    })
                    ->addActionLabel('Adicionar produto')
                    ->columnSpanFull()
                    ->schema([
                        Forms\Components\Select::make('product_id')
                            ->options(fn (): array => Product::query()
                                ->when(
                                    auth()->user()->role !== 'admin',
                                    fn (Builder $query) => $query->where('user_id', auth()->id()),
                                )
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all())
                            ->searchable()
                            ->preload()
                            ->required()
                            ->native(false)
                            ->columnSpan(6)
                            ->label('Produto')
                            ->live()
                            ->afterStateUpdated(function ($state, callable $get, callable $set): void {
                                $product = Product::query()->find($state);

                                if (! $product) {
                                    return;
                                }

                                $set('product_name', $product->name);
                                $set('product_code', $product->code);
                                $set('unit', $product->unit);
                                $set('unit_price', $product->sale_price);

                                static::updateSaleItemTotal($get, $set);
                            }),
                        Forms\Components\TextInput::make('product_name')
                            ->required()
                            ->string()
                            ->minLength(2)
                            ->maxLength(255)
                            ->readOnly()
                            ->columnSpan(3)
                            ->label('Nome do produto'),
                        Forms\Components\TextInput::make('product_code')
                            ->required()
                            ->string()
                            ->minLength(1)
                            ->maxLength(255)
                            ->readOnly()
                            ->columnSpan(3)
                            ->label('Código do produto'),
                        Forms\Components\TextInput::make('unit')
                            ->required()
                            ->string()
                            ->minLength(1)
                            ->maxLength(20)
                            ->default('UN')
                            ->readOnly()
                            ->columnSpan(2)
                            ->label('Unidade'),
                        Forms\Components\TextInput::make('quantity')
                            ->numeric()
                            ->required()
                            ->default(1)
                            ->minValue(0.001)
                            ->live(onBlur: true)
                            ->columnSpan(2)
                            ->label('Quantidade')
                            ->afterStateUpdated(function ($state, callable $get, callable $set): void {
                                static::updateSaleItemTotal($get, $set);
                            }),
                        Forms\Components\TextInput::make('unit_price')
                            ->numeric()
                            ->required()
                            ->default(0)
                            ->minValue(0)
                            ->live(onBlur: true)
                            ->columnSpan(2)
                            ->label('Valor unitário')
                            ->afterStateUpdated(function ($state, callable $get, callable $set): void {
                                static::updateSaleItemTotal($get, $set);
                            }),
                        Forms\Components\TextInput::make('discount_amount')
                            ->numeric()
                            ->default(0)
                            ->minValue(0)
                            ->live(onBlur: true)
                            ->columnSpan(2)
                            ->label('Desconto do item')
                            ->afterStateUpdated(function ($state, callable $get, callable $set): void {
                                static::updateSaleItemTotal($get, $set);
                            }),
                        Forms\Components\TextInput::make('tax_amount')
                            ->numeric()
                            ->default(0)
                            ->minValue(0)
                            ->live(onBlur: true)
                            ->columnSpan(2)
                            ->label('Impostos do item')
                            ->afterStateUpdated(function ($state, callable $get, callable $set): void {
                                static::updateSaleItemTotal($get, $set);
                            }),
                        Forms\Components\TextInput::make('total_amount')
                            ->numeric()
                            ->required()
                            ->default(0)
                            ->minValue(0)
                            ->readOnly()
                            ->columnSpan(2)
                            ->label('Total do item'),
                    ])
                    ->columns(12),
                Forms\Components\TextInput::make('subtotal_amount')
                    ->numeric()
                    ->default(0)
                    ->minValue(0)
                    ->readOnly()
                    ->live(onBlur: true)
                    ->columnSpan(3)
                    ->label('Subtotal'),
                Forms\Components\TextInput::make('discount_amount')
                    ->numeric()
                    ->default(0)
                    ->minValue(0)
                    ->live(onBlur: true)
                    ->afterStateUpdated(function ($state, callable $get, callable $set): void {
                        static::updateSaleTotals($get('saleItems') ?? [], $get, $set);
                    })
                    ->columnSpan(3)
                    ->label('Desconto'),
                Forms\Components\TextInput::make('tax_amount')
                    ->numeric()
                    ->default(0)
                    ->minValue(0)
                    ->live(onBlur: true)
                    ->afterStateUpdated(function ($state, callable $get, callable $set): void {
                        static::updateSaleTotals($get('saleItems') ?? [], $get, $set);
                    })
                    ->columnSpan(3)
                    ->label('Impostos'),
                Forms\Components\TextInput::make('total_amount')
                    ->numeric()
                    ->default(0)
                    ->minValue(0)
                    ->readOnly()
                    ->columnSpan(3)
                    ->label('Total'),
                Forms\Components\Textarea::make('notes')
                    ->rows(3)
                    ->maxLength(65535)
                    ->columnSpanFull()
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
                    ->label('ID'),
                Tables\Columns\TextColumn::make('client.name')
                    ->searchable()
                    ->label('Cliente'),
                Tables\Columns\TextColumn::make('sale_date')
                    ->date('d/m/Y')
                    ->sortable()
                    ->label('Data'),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'draft' => 'gray',
                        'pending' => 'warning',
                        'completed' => 'success',
                        'canceled' => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'draft' => 'Rascunho',
                        'pending' => 'Pendente',
                        'completed' => 'Concluída',
                        'canceled' => 'Cancelada',
                        default => $state,
                    })
                    ->label('Status'),
                Tables\Columns\TextColumn::make('payment_status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'pending' => 'warning',
                        'partial' => 'info',
                        'paid' => 'success',
                        'refunded' => 'gray',
                        'canceled' => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'pending' => 'Pendente',
                        'partial' => 'Parcial',
                        'paid' => 'Pago',
                        'refunded' => 'Estornado',
                        'canceled' => 'Cancelado',
                        default => $state,
                    })
                    ->label('Pagamento'),
                Tables\Columns\IconColumn::make('issue_invoice')
                    ->boolean()
                    ->label('NF'),
                Tables\Columns\TextColumn::make('total_amount')
                    ->money('BRL')
                    ->label('Total'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('user_id')
                    ->relationship('user', 'name')
                    ->label('Usuário')
                    ->visible(fn (): bool => auth()->user()->role === 'admin'),
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'draft' => 'Rascunho',
                        'pending' => 'Pendente',
                        'completed' => 'Concluída',
                        'canceled' => 'Cancelada',
                    ])
                    ->label('Status'),
                Tables\Filters\SelectFilter::make('payment_status')
                    ->options([
                        'pending' => 'Pendente',
                        'partial' => 'Parcial',
                        'paid' => 'Pago',
                        'refunded' => 'Estornado',
                        'canceled' => 'Cancelado',
                    ])
                    ->label('Pagamento'),
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
        return [
            SaleItemsRelationManager::class,
        ];
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

    protected static function updateSaleItemTotal(callable $get, callable $set): void
    {
        $quantity = (float) ($get('quantity') ?: 0);
        $unitPrice = (float) ($get('unit_price') ?: 0);
        $discount = (float) ($get('discount_amount') ?: 0);
        $tax = (float) ($get('tax_amount') ?: 0);

        $set('total_amount', max(round(($quantity * $unitPrice) - $discount + $tax, 2), 0));
    }

    protected static function updateSaleTotals(array $items, callable $get, callable $set): void
    {
        $subtotal = collect($items)->sum(function (array $item): float {
            $quantity = (float) ($item['quantity'] ?? 0);
            $unitPrice = (float) ($item['unit_price'] ?? 0);
            $discount = (float) ($item['discount_amount'] ?? 0);
            $tax = (float) ($item['tax_amount'] ?? 0);

            return max(round(($quantity * $unitPrice) - $discount + $tax, 2), 0);
        });

        $saleDiscount = (float) ($get('discount_amount') ?: 0);
        $saleTax = (float) ($get('tax_amount') ?: 0);

        $set('subtotal_amount', round($subtotal, 2));
        $set('total_amount', max(round($subtotal - $saleDiscount + $saleTax, 2), 0));
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSales::route('/'),
            'create' => Pages\CreateSale::route('/create'),
            'edit' => Pages\EditSale::route('/{record}/edit'),
        ];
    }
}
