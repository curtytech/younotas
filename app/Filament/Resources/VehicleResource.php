<?php

namespace App\Filament\Resources;

use App\Filament\Resources\VehicleResource\Pages;
use App\Filament\Resources\VehicleResource\RelationManagers;
use App\Models\Vehicle;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model; // Import adicionado
use Illuminate\Database\Eloquent\SoftDeletingScope;

class VehicleResource extends Resource
{
    protected static ?string $model = Vehicle::class;

    protected static bool $shouldRegisterNavigation = false;

    public static function canViewAny(): bool
    {
        return false;
    }

    protected static ?string $navigationIcon = 'heroicon-o-truck';

    protected static ?string $modelLabel = 'Veículo';

    protected static ?string $pluralModelLabel = 'Veículos';

    protected static ?string $navigationLabel = 'Veículos';

    protected static ?string $navigationGroup = 'Gerenciamento';

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
                        ->required()
                        ->label('Usuário')
                    : Forms\Components\Hidden::make('user_id')
                        ->default(auth()->id()),
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->maxLength(255)
                    ->label('Nome'),
                Forms\Components\TextInput::make('plate')
                    ->required()
                    ->maxLength(7)
                    ->placeholder('ABC1D23')
                    ->label('Placa')
                    ->reactive()
                    ->afterStateUpdated(function ($state, callable $set) {
                        $set('plate', strtoupper($state));
                    }),
                 Forms\Components\TextInput::make('renavam')
                    ->label('RENAVAM')
                    ->required()
                    ->maxLength(11)
                    ->placeholder('Informe os 11 números do RENAVAM')
                    ->extraInputAttributes([
                        'type' => 'text',
                        'inputmode' => 'numeric',
                    ])
                    ->dehydrateStateUsing(fn ($state) => preg_replace('/\D/', '', $state))
                    ->rules([
                        'required',
                        'digits:11',
                    ])
                    ->validationMessages([
                        'required' => 'O RENAVAM é obrigatório.',
                        'digits' => 'O RENAVAM deve conter exatamente 11 números.',
                    ]),
                Forms\Components\TextInput::make('model')
                    ->required()
                    ->maxLength(255)
                    ->label('Modelo'),
               Forms\Components\TextInput::make('year')
                    ->label('Ano')
                    ->required()
                    ->maxLength(4)
                    ->placeholder('2024')
                    ->extraInputAttributes([
                        'type' => 'text',
                        'inputmode' => 'numeric',
                    ])
                    ->dehydrateStateUsing(fn ($state) => preg_replace('/\D/', '', $state))
                    ->rules([
                        'required',
                        'digits:4',
                    ])
                    ->validationMessages([
                        'required' => 'O ano é obrigatório.',
                        'digits' => 'O ano deve conter exatamente 4 números.',
                    ]),
                Forms\Components\TextInput::make('type')
                    ->required()
                    ->maxLength(255)
                    ->label('Tipo'),
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
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->label('Nome'),
                Tables\Columns\TextColumn::make('plate')
                    ->searchable()
                    ->label('Placa'),
                Tables\Columns\TextColumn::make('renavam')
                    ->searchable()
                    ->label('Renavam'),
                Tables\Columns\TextColumn::make('model')
                    ->searchable()
                    ->label('Modelo'),
                Tables\Columns\TextColumn::make('year')
                    ->searchable()
                    ->label('Ano'),
                Tables\Columns\TextColumn::make('type')
                    ->searchable()
                    ->label('Tipo'),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->label('Criado em'),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->label('Atualizado em'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('user_id')
                    ->relationship('user', 'name')
                    ->label('Usuário')
                    ->visible(fn () => auth()->user()->role === 'admin'),
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
            //
        ];
    }

    // Métodos de permissão adicionados
    public static function canCreate(): bool
    {
        return auth()->user()->role === 'admin';
    }

    public static function canEdit(Model $record): bool
    {
        return auth()->user()->role === 'admin';
    }

    public static function canDelete(Model $record): bool
    {
        return auth()->user()->role === 'admin';
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListVehicles::route('/'),
            'create' => Pages\CreateVehicle::route('/create'),
            'edit' => Pages\EditVehicle::route('/{record}/edit'),
        ];
    }
}
