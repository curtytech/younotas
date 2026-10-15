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

class ServiceResource extends Resource
{
    protected static ?string $model = Service::class;
    protected static ?string $navigationIcon = 'heroicon-o-wrench-screwdriver';
    protected static ?string $modelLabel = 'Serviço';
    protected static ?string $pluralModelLabel = 'Serviços';
    protected static ?string $navigationLabel = 'Catálogo de Serviços';
    protected static ?string $navigationGroup = 'Cadastros Fiscais';
    protected static ?int $navigationSort = 30;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->when(auth()->user()->role !== 'admin', fn (Builder $q) => $q->where('user_id', auth()->id()));
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            auth()->user()->role === 'admin'
                ? Forms\Components\Select::make('user_id')->relationship('user', 'name')->searchable()->preload()->default(auth()->id())->required()->label('Emissor')
                : Forms\Components\Hidden::make('user_id')->default(auth()->id()),
            Forms\Components\TextInput::make('code')->maxLength(255)->unique(ignoreRecord: true, modifyRuleUsing: fn ($rule, callable $get) => $rule->where('user_id', $get('user_id') ?: auth()->id()))->label('Código'),
            Forms\Components\TextInput::make('name')->required()->minLength(2)->maxLength(255)->label('Nome'),
            Forms\Components\TextInput::make('unit')->required()->default('UN')->maxLength(20)->label('Unidade'),
            Forms\Components\TextInput::make('unit_price')->numeric()->required()->default(0)->minValue(0)->label('Preço padrão'),
            Forms\Components\TextInput::make('municipal_service_code')->maxLength(255)->label('Código municipal'),
            Forms\Components\TextInput::make('lc116_code')->maxLength(255)->label('Código LC 116'),
            Forms\Components\TextInput::make('cnae_code')->maxLength(255)->label('CNAE'),
            Forms\Components\TextInput::make('nbs_code')->maxLength(255)->label('NBS'),
            ...collect(['iss', 'pis', 'cofins', 'inss', 'ir', 'csll'])->map(fn (string $tax) => Forms\Components\TextInput::make("{$tax}_aliquot")->numeric()->minValue(0)->maxValue(100)->label('Alíquota '.strtoupper($tax).' %'))->all(),
            Forms\Components\Textarea::make('description')->rows(3)->label('Descrição'),
            Forms\Components\Textarea::make('notes')->rows(3)->label('Observações'),
            Forms\Components\Toggle::make('is_active')->default(true)->label('Ativo'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            Tables\Columns\TextColumn::make('user.name')->label('Emissor')->visible(auth()->user()->role === 'admin'),
            Tables\Columns\TextColumn::make('code')->searchable()->label('Código'),
            Tables\Columns\TextColumn::make('name')->searchable()->label('Serviço'),
            Tables\Columns\TextColumn::make('unit_price')->money('BRL')->label('Preço padrão'),
            Tables\Columns\TextColumn::make('municipal_service_code')->label('Código municipal'),
            Tables\Columns\IconColumn::make('is_active')->boolean()->label('Ativo'),
        ])->actions([Tables\Actions\EditAction::make(), Tables\Actions\DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListServices::route('/'), 'create' => Pages\CreateService::route('/create'), 'edit' => Pages\EditService::route('/{record}/edit')];
    }
}
