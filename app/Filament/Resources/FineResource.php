<?php

namespace App\Filament\Resources;

use App\Filament\Resources\FineResource\Pages;
use App\Filament\Resources\FineResource\RelationManagers;
use App\Models\Fine;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model; // Import adicionado
use Illuminate\Database\Eloquent\SoftDeletingScope;

class FineResource extends Resource
{
    protected static ?string $model = Fine::class;

    protected static bool $shouldRegisterNavigation = false;

    public static function canViewAny(): bool
    {
        return false;
    }

    protected static ?string $navigationIcon = 'heroicon-o-document-currency-dollar';

    protected static ?string $modelLabel = 'Multa';

    protected static ?string $pluralModelLabel = 'Multas';

    protected static ?string $navigationLabel = 'Multas';

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
                Forms\Components\Select::make('vehicle_id')
                    ->relationship('vehicle', 'name', function (Builder $query) {
                        if (auth()->user()->role !== 'admin') {
                            $query->where('user_id', auth()->id());
                        }
                    })
                    ->required()
                    ->label('Veículo'),
                Forms\Components\TextInput::make('ait')
                    ->label('AIT')
                    ->required()
                    ->maxLength(12)
                    ->helperText('Número do Auto de Infração de Trânsito')
                    ->placeholder('Informe o número do AIT')
                    ->extraInputAttributes([
                        'type' => 'text',
                        'inputmode' => 'numeric',
                    ])
                    ->dehydrateStateUsing(fn ($state) => preg_replace('/\D/', '', $state))
                    ->rules([
                        'required',
                        'regex:/^[0-9]{10,12}$/',
                    ])
                    ->validationMessages([
                        'required' => 'O número do AIT é obrigatório.',
                        'regex' => 'O AIT deve conter apenas números e ter entre 10 e 12 dígitos.',
                    ]),

                Forms\Components\DatePicker::make('fine_date')
                    ->label('Data da Multa')
                    ->required()
                    ->maxDate(now())
                    ->rule('before_or_equal:today'),
                Forms\Components\TextInput::make('description')
                    ->required()
                    ->maxLength(255)
                    ->label('Descrição'),
                Forms\Components\TextInput::make('fine_article')
                    ->required()
                    ->maxLength(255)
                    ->label('Artigo da Multa'),
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
                Tables\Columns\TextColumn::make('vehicle.name')
                    ->label('Veículo')
                    ->sortable(),
                Tables\Columns\TextColumn::make('ait')
                    ->label('AIT')
                    ->searchable(),
                Tables\Columns\TextColumn::make('fine_date')
                    ->date('d/m/Y')
                    ->sortable()
                    ->label('Data da Multa'),
                Tables\Columns\TextColumn::make('description')
                    ->label('Descrição')
                    ->searchable(),
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
            'index' => Pages\ListFines::route('/'),
            'create' => Pages\CreateFine::route('/create'),
            'edit' => Pages\EditFine::route('/{record}/edit'),
        ];
    }
}
