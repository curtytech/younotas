<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ServiceResource\Pages;
use App\Models\Service;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ServiceResource extends Resource
{
    protected static ?string $model = Service::class;

    protected static ?string $navigationIcon = 'heroicon-o-wrench-screwdriver';

    protected static ?string $modelLabel = 'Serviço';

    protected static ?string $pluralModelLabel = 'Serviços';

    protected static ?string $navigationLabel = 'Serviços';

    protected static ?string $navigationGroup = 'Cadastros Fiscais';

    protected static ?int $navigationSort = 30;

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
                Forms\Components\Select::make('client_id')
                    ->relationship('client', 'name', function (Builder $query) {
                        if (auth()->user()->role !== 'admin') {
                            $query->where('user_id', auth()->id());
                        }
                    })
                    ->searchable()
                    ->preload()
                    ->required()
                    ->label('Cliente'),
                Forms\Components\TextInput::make('code')
                    ->required()
                    ->maxLength(255)
                    ->label('Código'),
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->maxLength(255)
                    ->label('Nome'),
                Forms\Components\Textarea::make('description')
                    ->rows(3)
                    ->label('Descrição'),
                Forms\Components\TextInput::make('municipal_service_code')
                    ->maxLength(255)
                    ->label('Código municipal'),
                Forms\Components\TextInput::make('lc116_code')
                    ->maxLength(255)
                    ->label('Código LC 116'),
                Forms\Components\TextInput::make('cnae_code')
                    ->maxLength(255)
                    ->label('CNAE'),
                Forms\Components\TextInput::make('nbs_code')
                    ->maxLength(255)
                    ->label('NBS'),
                Forms\Components\TextInput::make('unit')
                    ->default('UN')
                    ->required()
                    ->maxLength(20)
                    ->label('Unidade'),
                Forms\Components\TextInput::make('unit_price')
                    ->numeric()
                    ->default(0)
                    ->label('Valor unitário'),
                Forms\Components\TextInput::make('iss_aliquot')
                    ->numeric()
                    ->default(0)
                    ->label('Alíquota ISS'),
                Forms\Components\TextInput::make('pis_aliquot')
                    ->numeric()
                    ->default(0)
                    ->label('Alíquota PIS'),
                Forms\Components\TextInput::make('cofins_aliquot')
                    ->numeric()
                    ->default(0)
                    ->label('Alíquota COFINS'),
                Forms\Components\TextInput::make('inss_aliquot')
                    ->numeric()
                    ->default(0)
                    ->label('Alíquota INSS'),
                Forms\Components\TextInput::make('ir_aliquot')
                    ->numeric()
                    ->default(0)
                    ->label('Alíquota IR'),
                Forms\Components\TextInput::make('csll_aliquot')
                    ->numeric()
                    ->default(0)
                    ->label('Alíquota CSLL'),
                Forms\Components\Toggle::make('is_active')
                    ->default(true)
                    ->label('Ativo'),
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
                Tables\Columns\TextColumn::make('client.name')
                    ->searchable()
                    ->label('Cliente'),
                Tables\Columns\TextColumn::make('code')
                    ->searchable()
                    ->label('Código'),
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->label('Serviço'),
                Tables\Columns\TextColumn::make('municipal_service_code')
                    ->label('Código municipal'),
                Tables\Columns\TextColumn::make('unit_price')
                    ->money('BRL')
                    ->label('Valor'),
                Tables\Columns\TextColumn::make('iss_aliquot')
                    ->label('ISS %'),
                Tables\Columns\IconColumn::make('is_active')
                    ->boolean()
                    ->label('Ativo'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('user_id')
                    ->relationship('user', 'name')
                    ->label('Usuário')
                    ->visible(fn (): bool => auth()->user()->role === 'admin'),
                Tables\Filters\SelectFilter::make('client_id')
                    ->relationship('client', 'name')
                    ->label('Cliente'),
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
            'index' => Pages\ListServices::route('/'),
            'create' => Pages\CreateService::route('/create'),
            'edit' => Pages\EditService::route('/{record}/edit'),
        ];
    }
}
