<?php

namespace App\Filament\Resources\SaleResource\RelationManagers;

use App\Models\Product;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class SaleItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'saleItems';

    protected static ?string $title = 'Itens da Venda';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('product_id')
                    ->options(fn (): array => Product::query()
                        ->when(
                            auth()->user()->role !== 'admin',
                            fn ($query) => $query->where('user_id', auth()->id()),
                        )
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->searchable()
                    ->preload()
                    ->required()
                    ->native(false)
                    ->label('Produto')
                    ->live()
                    ->afterStateUpdated(function ($state, callable $set): void {
                        $product = Product::find($state);

                        if (! $product) {
                            return;
                        }

                        $set('product_name', $product->name);
                        $set('product_code', $product->code);
                        $set('unit', $product->unit);
                        $set('unit_price', $product->sale_price);
                    }),
                Forms\Components\TextInput::make('product_name')
                    ->required()
                    ->string()
                    ->minLength(2)
                    ->maxLength(255)
                    ->label('Nome do produto'),
                Forms\Components\TextInput::make('product_code')
                    ->required()
                    ->string()
                    ->minLength(1)
                    ->maxLength(255)
                    ->label('Código do produto'),
                Forms\Components\TextInput::make('unit')
                    ->required()
                    ->string()
                    ->minLength(1)
                    ->maxLength(20)
                    ->default('UN')
                    ->label('Unidade'),
                Forms\Components\TextInput::make('quantity')
                    ->numeric()
                    ->required()
                    ->default(1)
                    ->minValue(0.001)
                    ->live(onBlur: true)
                    ->label('Quantidade')
                    ->afterStateUpdated(function ($state, callable $get, callable $set): void {
                        $quantity = (float) ($state ?: 0);
                        $unitPrice = (float) ($get('unit_price') ?: 0);
                        $discount = (float) ($get('discount_amount') ?: 0);
                        $tax = (float) ($get('tax_amount') ?: 0);

                        $set('total_amount', ($quantity * $unitPrice) - $discount + $tax);
                    }),
                Forms\Components\TextInput::make('unit_price')
                    ->numeric()
                    ->required()
                    ->default(0)
                    ->minValue(0)
                    ->live(onBlur: true)
                    ->label('Valor unitário')
                    ->afterStateUpdated(function ($state, callable $get, callable $set): void {
                        $quantity = (float) ($get('quantity') ?: 0);
                        $unitPrice = (float) ($state ?: 0);
                        $discount = (float) ($get('discount_amount') ?: 0);
                        $tax = (float) ($get('tax_amount') ?: 0);

                        $set('total_amount', ($quantity * $unitPrice) - $discount + $tax);
                    }),
                Forms\Components\TextInput::make('discount_amount')
                    ->numeric()
                    ->default(0)
                    ->minValue(0)
                    ->live(onBlur: true)
                    ->label('Desconto')
                    ->afterStateUpdated(function ($state, callable $get, callable $set): void {
                        $quantity = (float) ($get('quantity') ?: 0);
                        $unitPrice = (float) ($get('unit_price') ?: 0);
                        $discount = (float) ($state ?: 0);
                        $tax = (float) ($get('tax_amount') ?: 0);

                        $set('total_amount', ($quantity * $unitPrice) - $discount + $tax);
                    }),
                Forms\Components\TextInput::make('tax_amount')
                    ->numeric()
                    ->default(0)
                    ->minValue(0)
                    ->live(onBlur: true)
                    ->label('Impostos')
                    ->afterStateUpdated(function ($state, callable $get, callable $set): void {
                        $quantity = (float) ($get('quantity') ?: 0);
                        $unitPrice = (float) ($get('unit_price') ?: 0);
                        $discount = (float) ($get('discount_amount') ?: 0);
                        $tax = (float) ($state ?: 0);

                        $set('total_amount', ($quantity * $unitPrice) - $discount + $tax);
                    }),
                Forms\Components\TextInput::make('total_amount')
                    ->numeric()
                    ->required()
                    ->default(0)
                    ->minValue(0)
                    ->label('Total'),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('product_name')
            ->columns([
                Tables\Columns\TextColumn::make('product_name')
                    ->label('Produto'),
                Tables\Columns\TextColumn::make('quantity')
                    ->numeric(decimalPlaces: 3)
                    ->label('Quantidade'),
                Tables\Columns\TextColumn::make('unit_price')
                    ->money('BRL')
                    ->label('Valor unitário'),
                Tables\Columns\TextColumn::make('total_amount')
                    ->money('BRL')
                    ->label('Total'),
            ])
            ->filters([])
            ->headerActions([
                Tables\Actions\CreateAction::make(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }
}
