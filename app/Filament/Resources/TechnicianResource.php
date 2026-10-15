<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TechnicianResource\Pages\CreateTechnician;
use App\Filament\Resources\TechnicianResource\Pages\EditTechnician;
use App\Filament\Resources\TechnicianResource\Pages\ListTechnicians;
use App\Models\Technician;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class TechnicianResource extends Resource
{
    protected static ?string $model = Technician::class;

    protected static ?string $navigationIcon = 'heroicon-o-user-group';

    protected static ?string $modelLabel = 'Técnico';

    protected static ?string $pluralModelLabel = 'Técnicos';

    protected static ?string $navigationLabel = 'Técnicos';

    protected static ?string $navigationGroup = 'Operações';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->when(auth()->user()->role !== 'admin', fn (Builder $q) => $q->where('user_id', auth()->id()));
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            auth()->user()->role === 'admin' ? Forms\Components\Select::make('user_id')->relationship('user', 'name')->required()->label('Emissor') : Forms\Components\Hidden::make('user_id')->default(auth()->id()),
            Forms\Components\TextInput::make('name')->required()->maxLength(255),
            Forms\Components\TextInput::make('phone')->tel()->maxLength(30),
            Forms\Components\TextInput::make('email')->email()->maxLength(255),
            Forms\Components\Textarea::make('notes')->rows(3),
            Forms\Components\Toggle::make('is_active')->default(true)->label('Ativo'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            Tables\Columns\TextColumn::make('user.name')->label('Emissor')->visible(auth()->user()->role === 'admin'),
            Tables\Columns\TextColumn::make('name')->searchable()->sortable(),
            Tables\Columns\TextColumn::make('phone'), Tables\Columns\TextColumn::make('email'),
            Tables\Columns\IconColumn::make('is_active')->boolean()->label('Ativo'),
        ])->actions([Tables\Actions\EditAction::make(), Tables\Actions\DeleteAction::make()]);
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
        return ['index' => ListTechnicians::route('/'), 'create' => CreateTechnician::route('/create'), 'edit' => EditTechnician::route('/{record}/edit')];
    }
}
