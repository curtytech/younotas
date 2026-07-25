<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SaleResource\Pages;
use App\Filament\Resources\SaleResource\RelationManagers\SaleItemsRelationManager;
use App\Jobs\CancelSaleNfeJob;
use App\Jobs\ConsultSaleNfeJob;
use App\Jobs\EmitSaleNfeJob;
use App\Models\Product;
use App\Models\Sale;
use App\Support\NfeStatus;
use Closure;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
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
                                $set('product_code', (string) $product->id);
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
                            ->rule(fn (Get $get): Closure => function (string $attribute, $value, Closure $fail) use ($get): void {
                                $requestedQuantity = (float) ($value ?: 0);
                                $availableStock = static::getAvailableStock((int) ($get('product_id') ?: 0));

                                if ($availableStock === null || $requestedQuantity <= 0 || $requestedQuantity <= $availableStock) {
                                    return;
                                }

                                $fail(sprintf(
                                    'Estoque insuficiente. Disponivel: %s, solicitado: %s.',
                                    number_format($availableStock, 3, ',', '.'),
                                    number_format($requestedQuantity, 3, ',', '.'),
                                ));
                            })
                            ->helperText(fn (Get $get): ?string => static::getStockWarningMessage(
                                productId: (int) ($get('product_id') ?: 0),
                                requestedQuantity: (float) ($get('quantity') ?: 0),
                            ))
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
                Tables\Columns\TextColumn::make('focus_nfe_status')
                    ->badge()
                    ->placeholder('Não emitida')
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        NfeStatus::SENDING => 'Enviando', NfeStatus::PROCESSING => 'Processando',
                        NfeStatus::AUTHORIZED => 'Autorizada', NfeStatus::CANCELED => 'Cancelada',
                        NfeStatus::AUTHORIZATION_ERROR => 'Erro de autorização',
                        NfeStatus::TRANSPORT_ERROR => 'Erro de envio', default => 'Não emitida',
                    })
                    ->color(fn (?string $state): string => match ($state) {
                        NfeStatus::AUTHORIZED => 'success', NfeStatus::CANCELED => 'gray',
                        NfeStatus::PROCESSING, NfeStatus::SENDING => 'warning',
                        NfeStatus::AUTHORIZATION_ERROR, NfeStatus::TRANSPORT_ERROR => 'danger', default => 'gray',
                    })
                    ->label('NF-e'),
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
                Tables\Actions\Action::make('previsualizar_danfe')
                    ->label('Pré-visualizar DANFe')
                    ->icon('heroicon-o-document')
                    ->color('gray')
                    ->url(fn (Sale $record): string => route('sales.danfe-preview', $record))
                    ->openUrlInNewTab(),
                Tables\Actions\Action::make('emitir_nfe')
                    ->label('Emitir NF-e')->icon('heroicon-o-paper-airplane')
                    ->visible(fn (Sale $record): bool => NfeStatus::canEmit($record->focus_nfe_status))
                    ->requiresConfirmation()
                    ->action(fn (Sale $record): mixed => EmitSaleNfeJob::dispatch($record->id))
                    ->successNotificationTitle('Emissão da NF-e enfileirada.'),
                Tables\Actions\Action::make('consultar_nfe')
                    ->label('Consultar NF-e')->icon('heroicon-o-arrow-path')
                    ->visible(fn (Sale $record): bool => filled($record->focus_nfe_ref) && $record->focus_nfe_status === NfeStatus::PROCESSING)
                    ->action(fn (Sale $record): mixed => ConsultSaleNfeJob::dispatch($record->id))
                    ->successNotificationTitle('Consulta da NF-e enfileirada.'),
                Tables\Actions\Action::make('cancelar_nfe')
                    ->label('Cancelar NF-e')->icon('heroicon-o-x-circle')->color('danger')
                    ->visible(fn (Sale $record): bool => NfeStatus::canCancel($record->focus_nfe_status))
                    ->requiresConfirmation()
                    ->form([Forms\Components\Textarea::make('justification')->label('Justificativa')->required()->minLength(15)->maxLength(255)])
                    ->action(fn (Sale $record, array $data): mixed => CancelSaleNfeJob::dispatch($record->id, $data['justification']))
                    ->successNotificationTitle('Cancelamento da NF-e enfileirado.'),
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

    protected static function getAvailableStock(int $productId): ?float
    {
        if ($productId <= 0) {
            return null;
        }

        $product = Product::query()->find($productId);

        return $product ? (float) $product->stock_quantity : null;
    }

    protected static function getStockWarningMessage(int $productId, float $requestedQuantity): ?string
    {
        $availableStock = static::getAvailableStock($productId);

        if ($availableStock === null) {
            return null;
        }

        $message = 'Estoque disponivel: '.number_format($availableStock, 3, ',', '.');

        if ($requestedQuantity > $availableStock) {
            $message .= '. A quantidade informada e maior que o estoque e a venda nao sera salva.';
        }

        return $message;
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
