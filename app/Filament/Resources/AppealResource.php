<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AppealResource\Pages;
use App\Filament\Resources\AppealResource\RelationManagers;
use App\Models\Appeal;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model; // Import adicionado
use Illuminate\Database\Eloquent\SoftDeletingScope;

class AppealResource extends Resource
{
    protected static ?string $model = Appeal::class;

    protected static bool $shouldRegisterNavigation = false;

    public static function canViewAny(): bool
    {
        return false;
    }

    protected static ?string $navigationIcon = 'heroicon-o-scale';

    protected static ?string $modelLabel = 'Recurso';

    protected static ?string $pluralModelLabel = 'Recursos';

    protected static ?string $navigationLabel = 'Recursos';

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
                Forms\Components\Select::make('fine_id')
                    ->relationship('fine', 'ait', function (Builder $query) {
                        if (auth()->user()->role !== 'admin') {
                            $query->where('user_id', auth()->id());
                        }
                    })
                    ->required()
                    ->label('Multa (AIT)'),
                Forms\Components\Select::make('appeal_status_id')
                    ->relationship('appealStatus', 'name')
                    ->label('Status')
                    ->required(),
                Forms\Components\DatePicker::make('date')
                    ->required()
                    ->label('Data')
                    ->maxDate('today'),
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
                Tables\Columns\TextColumn::make('fine.ait')
                    ->label('Multa (AIT)')
                    ->sortable(),
                Tables\Columns\TextColumn::make('appealStatus.name')
                    ->label('Status')
                    ->sortable(),
                Tables\Columns\TextColumn::make('date')
                    ->date('d/m/Y')
                    ->sortable()
                    ->label('Data'),
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
            'index' => Pages\ListAppeals::route('/'),
            'create' => Pages\CreateAppeal::route('/create'),
            'edit' => Pages\EditAppeal::route('/{record}/edit'),
        ];
    }
}
