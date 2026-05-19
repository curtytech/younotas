<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ClientResource\Pages;
use App\Models\Client;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ClientResource extends Resource
{
    protected static ?string $model = Client::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?string $modelLabel = 'Cliente';

    protected static ?string $pluralModelLabel = 'Clientes';

    protected static ?string $navigationLabel = 'Clientes';

    protected static ?string $navigationGroup = 'Cadastros Fiscais';

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
                        ->required()
                        ->label('Usuário')
                    : Forms\Components\Hidden::make('user_id')
                        ->default(auth()->id()),
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->maxLength(255)
                    ->label('Nome do cliente'),
                Forms\Components\TextInput::make('email')
                    ->email()
                    ->required()
                    ->maxLength(255)
                    ->label('E-mail'),
                Forms\Components\TextInput::make('phone')
                    ->required()
                    ->maxLength(30)
                    ->label('Telefone'),
                Forms\Components\Select::make('document_type')
                    ->options([
                        'cpf' => 'CPF',
                        'cnpj' => 'CNPJ',
                        'nif' => 'NIF',
                    ])
                    ->required()
                    ->label('Tipo de documento'),
                Forms\Components\TextInput::make('document')
                    ->required()
                    ->maxLength(255)
                    ->label('Documento'),
                Forms\Components\TextInput::make('address')
                    ->required()
                    ->maxLength(255)
                    ->label('Endereço'),
                Forms\Components\TextInput::make('address_number')
                    ->maxLength(50)
                    ->label('Número'),
                Forms\Components\TextInput::make('address_complement')
                    ->maxLength(255)
                    ->label('Complemento'),
                Forms\Components\TextInput::make('neighborhood')
                    ->maxLength(255)
                    ->label('Bairro'),
                Forms\Components\TextInput::make('city')
                    ->required()
                    ->maxLength(255)
                    ->label('Cidade'),
                Forms\Components\TextInput::make('state')
                    ->maxLength(2)
                    ->label('UF'),
                Forms\Components\TextInput::make('zip_code')
                    ->maxLength(20)
                    ->label('CEP'),
                Forms\Components\TextInput::make('country')
                    ->default('BR')
                    ->required()
                    ->maxLength(2)
                    ->label('País'),
                Forms\Components\Toggle::make('is_active')
                    ->default(true)
                    ->label('Ativo'),
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
                    ->sortable()
                    ->label('Cliente'),
                Tables\Columns\TextColumn::make('document_type')
                    ->badge()
                    ->label('Tipo'),
                Tables\Columns\TextColumn::make('document')
                    ->searchable()
                    ->label('Documento'),
                Tables\Columns\TextColumn::make('email')
                    ->searchable()
                    ->label('E-mail'),
                Tables\Columns\TextColumn::make('phone')
                    ->label('Telefone'),
                Tables\Columns\TextColumn::make('city')
                    ->label('Cidade'),
                Tables\Columns\IconColumn::make('is_active')
                    ->boolean()
                    ->label('Ativo'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('user_id')
                    ->relationship('user', 'name')
                    ->label('Usuário')
                    ->visible(fn (): bool => auth()->user()->role === 'admin'),
                Tables\Filters\SelectFilter::make('document_type')
                    ->options([
                        'cpf' => 'CPF',
                        'cnpj' => 'CNPJ',
                        'nif' => 'NIF',
                    ])
                    ->label('Tipo de documento'),
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
            'index' => Pages\ListClients::route('/'),
            'create' => Pages\CreateClient::route('/create'),
            'edit' => Pages\EditClient::route('/{record}/edit'),
        ];
    }
}
