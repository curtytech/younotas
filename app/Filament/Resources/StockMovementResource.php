<?php

namespace App\Filament\Resources;

use App\Filament\Resources\StockMovementResource\Pages;
use App\Models\StockMovement;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class StockMovementResource extends Resource
{
    protected static ?string $model = StockMovement::class;

    protected static ?string $navigationIcon = 'heroicon-o-arrows-right-left';

    protected static ?string $modelLabel = 'Movimentação de Estoque';

    protected static ?string $pluralModelLabel = 'Movimentações de Estoque';

    protected static ?string $navigationLabel = 'Estoque';

    protected static ?string $navigationGroup = 'Comercial';

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
                auth()->user()->role === 'enterprise'
                    ? Forms\Components\Select::make('user_id')
                        ->relationship('user', 'name')
                        ->searchable()
                        ->preload()
                        ->default(auth()->id())
                        ->required()
                        ->label('Usuário')
                    : Forms\Components\Hidden::make('user_id')
                        ->default(auth()->id()),
                Forms\Components\Select::make('product_id')
                    ->relationship('product', 'name', function (Builder $query) {
                        if (auth()->user()->role !== 'enterprise') {
                            $query->where('user_id', auth()->id());
                        }
                    })
                    ->searchable()
                    ->preload()
                    ->required()
                    ->label('Produto'),
                Forms\Components\Select::make('movement_type')
                    ->options([
                        'entry' => 'Entrada',
                        'exit' => 'Saída',
                        'adjustment' => 'Ajuste',
                    ])
                    ->required()
                    ->label('Tipo de movimentação'),
                Forms\Components\Select::make('source_type')
                    ->options([
                        'manual' => 'Manual',
                        'sale' => 'Venda',
                        'return' => 'Devolução',
                        'inventory_adjustment' => 'Ajuste de inventário',
                    ])
                    ->required()
                    ->label('Origem'),
                Forms\Components\TextInput::make('reference')
                    ->maxLength(255)
                    ->label('Referência'),
                Forms\Components\TextInput::make('quantity')
                    ->numeric()
                    ->required()
                    ->label('Quantidade'),
                Forms\Components\TextInput::make('previous_stock')
                    ->numeric()
                    ->default(0)
                    ->label('Saldo anterior'),
                Forms\Components\TextInput::make('current_stock')
                    ->numeric()
                    ->default(0)
                    ->label('Saldo atual'),
                Forms\Components\TextInput::make('unit_cost')
                    ->numeric()
                    ->default(0)
                    ->label('Custo unitário'),
                Forms\Components\DateTimePicker::make('moved_at')
                    ->required()
                    ->default(now())
                    ->label('Data da movimentação'),
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
                Tables\Columns\TextColumn::make('product.name')
                    ->searchable()
                    ->label('Produto'),
                Tables\Columns\TextColumn::make('movement_type')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'entry' => 'success',
                        'exit' => 'danger',
                        'adjustment' => 'warning',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'entry' => 'Entrada',
                        'exit' => 'Saída',
                        'adjustment' => 'Ajuste',
                        default => $state,
                    })
                    ->label('Tipo'),
                Tables\Columns\TextColumn::make('source_type')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'manual' => 'Manual',
                        'sale' => 'Venda',
                        'return' => 'Devolução',
                        'inventory_adjustment' => 'Inventário',
                        default => $state,
                    })
                    ->label('Origem'),
                Tables\Columns\TextColumn::make('quantity')
                    ->numeric(decimalPlaces: 3)
                    ->label('Quantidade'),
                Tables\Columns\TextColumn::make('current_stock')
                    ->numeric(decimalPlaces: 3)
                    ->label('Saldo atual'),
                Tables\Columns\TextColumn::make('moved_at')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->label('Movimentado em'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('user_id')
                    ->relationship('user', 'name')
                    ->label('Usuário')
                    ->visible(fn (): bool => auth()->user()->role === 'admin'),
                Tables\Filters\SelectFilter::make('movement_type')
                    ->options([
                        'entry' => 'Entrada',
                        'exit' => 'Saída',
                        'adjustment' => 'Ajuste',
                    ])
                    ->label('Tipo'),
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
            'index' => Pages\ListStockMovements::route('/'),
            'create' => Pages\CreateStockMovement::route('/create'),
            'edit' => Pages\EditStockMovement::route('/{record}/edit'),
        ];
    }
}
