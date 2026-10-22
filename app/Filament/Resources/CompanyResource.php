<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CompanyResource\Pages;
use App\Filament\Resources\CompanyResource\RelationManagers\MembersRelationManager;
use App\Models\Company;
use App\Services\FiscalReadiness;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class CompanyResource extends Resource
{
    protected static ?string $model = Company::class;
    protected static ?string $navigationIcon = 'heroicon-o-building-office-2';
    protected static ?string $modelLabel = 'Empresa emitente';
    protected static ?string $pluralModelLabel = 'Empresas emitentes';
    protected static ?string $navigationGroup = 'Gerenciamento';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->when(auth()->user()->role !== 'admin',
            fn (Builder $query) => $query->whereHas('members', fn (Builder $members) => $members->where('users.id', auth()->id())));
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('name')->label('Razão social / nome')->required()->maxLength(255),
            Forms\Components\TextInput::make('cnpj')->label('CNPJ')->maxLength(18)
                ->helperText('Os dados completos do emitente ficam na Configuração Fiscal.'),
            Forms\Components\Toggle::make('is_active')->label('Empresa ativa')->default(true)
                ->helperText('Desativar preserva o histórico fiscal e impede novas operações.'),
            Forms\Components\Section::make('Prontidão para emitir')
                ->visible(fn (?Company $record): bool => (bool) $record?->exists)
                ->schema([Forms\Components\View::make('filament.fiscal.readiness')
                    ->viewData(fn (?Company $record): array => ['checks' => $record?->exists ? app(FiscalReadiness::class)->checkAll($record) : []])])
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            Tables\Columns\TextColumn::make('name')->label('Empresa')->searchable(),
            Tables\Columns\TextColumn::make('cnpj')->label('CNPJ')->searchable(),
            Tables\Columns\TextColumn::make('default_environment')->label('Padrão para novas emissões')->badge()
                ->formatStateUsing(fn (string $state): string => $state === 'producao' ? 'Produção' : 'Homologação')
                ->color(fn (string $state): string => $state === 'producao' ? 'danger' : 'info'),
            Tables\Columns\IconColumn::make('is_active')->boolean()->label('Ativa'),
        ])->actions([Tables\Actions\EditAction::make()])->bulkActions([]);
    }

    public static function getRelations(): array { return [MembersRelationManager::class]; }
    public static function getPages(): array
    {
        return ['index' => Pages\ListCompanies::route('/'), 'create' => Pages\CreateCompany::route('/create'), 'edit' => Pages\EditCompany::route('/{record}/edit')];
    }
    public static function canCreate(): bool { return auth()->user()?->role === 'admin'; }
    public static function canEdit(Model $record): bool { return auth()->user()?->canAccessCompany($record, 'manage_members') ?? false; }
    public static function canView(Model $record): bool { return auth()->user()?->canAccessCompany($record, 'view') ?? false; }
    public static function canDelete(Model $record): bool { return false; }
}
