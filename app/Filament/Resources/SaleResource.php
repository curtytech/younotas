<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SaleResource\Pages;
use App\Filament\Resources\SaleResource\RelationManagers\SaleItemsRelationManager;
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
                Forms\Components\Select::make('client_id')
                    ->relationship('client', 'name', function (Builder $query) {
                        if (auth()->user()->role !== 'admin') {
                            $query->where('user_id', auth()->id());
                        }
                    })
                    ->searchable()
                    ->preload()
                    ->label('Cliente'),
                Forms\Components\TextInput::make('number')
                    ->required()
                    ->maxLength(255)
                    ->label('Número da venda'),
                Forms\Components\DatePicker::make('sale_date')
                    ->required()
                    ->default(now())
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
                    ->label('Status do pagamento'),
                Forms\Components\Toggle::make('issue_invoice')
                    ->default(false)
                    ->label('Emitir nota fiscal'),
                Forms\Components\TextInput::make('subtotal_amount')
                    ->numeric()
                    ->default(0)
                    ->label('Subtotal'),
                Forms\Components\TextInput::make('discount_amount')
                    ->numeric()
                    ->default(0)
                    ->label('Desconto'),
                Forms\Components\TextInput::make('tax_amount')
                    ->numeric()
                    ->default(0)
                    ->label('Impostos'),
                Forms\Components\TextInput::make('total_amount')
                    ->numeric()
                    ->default(0)
                    ->label('Total'),
                Forms\Components\Textarea::make('notes')
                    ->rows(3)
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
                Tables\Columns\TextColumn::make('number')
                    ->searchable()
                    ->sortable()
                    ->label('Número'),
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

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSales::route('/'),
            'create' => Pages\CreateSale::route('/create'),
            'edit' => Pages\EditSale::route('/{record}/edit'),
        ];
    }
}
