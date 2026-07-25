<?php

namespace App\Filament\Resources;

use App\Filament\Resources\FocusNfeSettingResource\Pages;
use App\Models\FocusNfeSetting;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class FocusNfeSettingResource extends Resource
{
    protected static ?string $model = FocusNfeSetting::class;

    protected static ?string $navigationIcon = 'heroicon-o-shield-check';

    protected static ?string $modelLabel = 'Configuração Fiscal';

    protected static ?string $pluralModelLabel = 'Configurações Fiscais';

    protected static ?string $navigationLabel = 'Configuração Fiscal';

    protected static ?string $navigationGroup = 'Cadastros Fiscais';

    protected static ?int $navigationSort = 40;

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with('user');

        if (auth()->user()->role !== 'admin') {
            $query->where('user_id', auth()->id());
        }

        return $query;
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Responsável')
                    ->schema([
                        auth()->user()->role === 'admin'
                            ? Forms\Components\Select::make('user_id')
                                ->relationship('user', 'name')
                                ->searchable()
                                ->preload()
                                ->required()
                                ->unique(ignoreRecord: true)
                                ->label('Usuário')
                            : Forms\Components\Hidden::make('user_id')
                                ->default(auth()->id()),
                    ]),
                Forms\Components\Section::make('API Focus')
                    ->schema([
                        Forms\Components\TextInput::make('settings.api_key')
                            ->required()
                            ->string()
                            ->maxLength(255)
                            ->label('API Key'),
                        Forms\Components\Select::make('settings.base_url')
                            ->required()
                            ->options([
                                'https://homologacao.focusnfe.com.br' => 'Homologação',
                                'https://api.focusnfe.com.br' => 'Produção',
                            ])
                            ->default('https://homologacao.focusnfe.com.br')
                            ->native(false)
                            ->label('Ambiente'),
                    ])
                    ->columns(3),
                Forms\Components\Section::make('Prestador NFS-e')
                    ->schema([
                        Forms\Components\TextInput::make('settings.prestador.cnpj')
                            ->required()
                            ->string()
                            ->maxLength(18)
                            ->label('CNPJ do prestador'),
                        Forms\Components\TextInput::make('settings.prestador.inscricao_municipal')
                            ->required()
                            ->string()
                            ->maxLength(255)
                            ->label('Inscrição municipal'),
                        Forms\Components\TextInput::make('settings.prestador.codigo_municipio')
                            ->required()
                            ->string()
                            ->maxLength(20)
                            ->label('Código do município'),
                        Forms\Components\TextInput::make('settings.nfse.natureza_operacao')
                            ->required()
                            ->string()
                            ->maxLength(20)
                            ->default('1')
                            ->label('Natureza da operação NFS-e'),
                        Forms\Components\Toggle::make('settings.nfse.incentivador_cultural')
                            ->default(false)
                            ->label('Incentivador cultural'),
                        Forms\Components\Toggle::make('settings.nfse.optante_simples_nacional')
                            ->default(true)
                            ->label('Optante Simples Nacional'),
                    ])
                    ->columns(3),
                Forms\Components\Section::make('Emitente NF-e')
                    ->schema([
                        Forms\Components\TextInput::make('settings.nfe.emitente.nome')
                            ->required()
                            ->string()
                            ->maxLength(255)
                            ->label('Razão social'),
                        Forms\Components\TextInput::make('settings.nfe.emitente.nome_fantasia')
                            ->required()
                            ->string()
                            ->maxLength(255)
                            ->label('Nome fantasia'),
                        Forms\Components\TextInput::make('settings.nfe.emitente.logradouro')
                            ->required()
                            ->string()
                            ->maxLength(255)
                            ->label('Logradouro'),
                        Forms\Components\TextInput::make('settings.nfe.emitente.numero')
                            ->required()
                            ->string()
                            ->maxLength(20)
                            ->label('Número'),
                        Forms\Components\TextInput::make('settings.nfe.emitente.bairro')
                            ->required()
                            ->string()
                            ->maxLength(255)
                            ->label('Bairro'),
                        Forms\Components\TextInput::make('settings.nfe.emitente.municipio')
                            ->required()
                            ->string()
                            ->maxLength(255)
                            ->label('Município'),
                        Forms\Components\TextInput::make('settings.nfe.emitente.uf')
                            ->required()
                            ->string()
                            ->length(2)
                            ->label('UF'),
                        Forms\Components\TextInput::make('settings.nfe.emitente.cep')
                            ->required()
                            ->string()
                            ->maxLength(10)
                            ->label('CEP'),
                        Forms\Components\TextInput::make('settings.nfe.emitente.inscricao_estadual')
                            ->required()
                            ->string()
                            ->maxLength(255)
                            ->label('Inscrição estadual'),
                        Forms\Components\Select::make('settings.nfe.emitente.regime_tributario')
                            ->required()
                            ->options([
                                '1' => 'Simples Nacional',
                                '2' => 'Simples Nacional - excesso de sublimite',
                                '3' => 'Regime Normal',
                            ])
                            ->default('1')
                            ->native(false)
                            ->label('Regime tributário'),
                    ])
                    ->columns(3),
                Forms\Components\Section::make('Regras NF-e')
                    ->schema([
                        Forms\Components\TextInput::make('settings.nfe.natureza_operacao')
                            ->required()
                            ->string()
                            ->maxLength(255)
                            ->default('VENDA DE MERCADORIA')
                            ->label('Natureza da operação'),
                        Forms\Components\TextInput::make('settings.nfe.tipo_documento')
                            ->required()
                            ->numeric()
                            ->default(1)
                            ->label('Tipo do documento'),
                        Forms\Components\TextInput::make('settings.nfe.local_destino')
                            ->required()
                            ->numeric()
                            ->default(1)
                            ->label('Local do destino'),
                        Forms\Components\TextInput::make('settings.nfe.finalidade_emissao')
                            ->required()
                            ->numeric()
                            ->default(1)
                            ->label('Finalidade da emissão'),
                        Forms\Components\TextInput::make('settings.nfe.consumidor_final')
                            ->required()
                            ->numeric()
                            ->default(1)
                            ->label('Consumidor final'),
                        Forms\Components\TextInput::make('settings.nfe.presenca_comprador')
                            ->required()
                            ->numeric()
                            ->default(1)
                            ->label('Presença do comprador'),
                        Forms\Components\TextInput::make('settings.nfe.modalidade_frete')
                            ->required()
                            ->numeric()
                            ->default(9)
                            ->label('Modalidade do frete'),
                        Forms\Components\TextInput::make('settings.nfe.forma_pagamento')
                            ->required()
                            ->string()
                            ->maxLength(2)
                            ->default('01')
                            ->label('Forma de pagamento'),
                        Forms\Components\TextInput::make('settings.nfe.cfop_padrao')
                            ->required()
                            ->string()
                            ->maxLength(10)
                            ->default('5102')
                            ->label('CFOP padrão'),
                        Forms\Components\TextInput::make('settings.nfe.codigo_ncm_padrao')
                            ->string()
                            ->maxLength(8)
                            ->label('NCM padrão'),
                        Forms\Components\TextInput::make('settings.nfe.icms_origem')
                            ->required()
                            ->string()
                            ->maxLength(5)
                            ->default('0')
                            ->label('Origem ICMS'),
                        Forms\Components\TextInput::make('settings.nfe.icms_situacao_tributaria')
                            ->required()
                            ->string()
                            ->maxLength(5)
                            ->default('102')
                            ->label('Situação tributária ICMS'),
                        Forms\Components\TextInput::make('settings.nfe.pis_situacao_tributaria')
                            ->required()
                            ->string()
                            ->maxLength(5)
                            ->default('07')
                            ->label('Situação tributária PIS'),
                        Forms\Components\TextInput::make('settings.nfe.cofins_situacao_tributaria')
                            ->required()
                            ->string()
                            ->maxLength(5)
                            ->default('07')
                            ->label('Situação tributária COFINS'),
                        Forms\Components\TextInput::make('settings.nfe.valor_frete')
                            ->numeric()
                            ->default(0)
                            ->minValue(0)
                            ->label('Valor do frete'),
                        Forms\Components\TextInput::make('settings.nfe.valor_seguro')
                            ->numeric()
                            ->default(0)
                            ->minValue(0)
                            ->label('Valor do seguro'),
                        Forms\Components\TextInput::make('settings.nfe.valor_outras_despesas')
                            ->numeric()
                            ->default(0)
                            ->minValue(0)
                            ->label('Outras despesas'),
                    ])
                    ->columns(3),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('user.name')
                    ->label('Usuário')
                    ->visible(auth()->user()->role === 'admin'),
                Tables\Columns\TextColumn::make('settings.prestador.cnpj')
                    ->label('CNPJ'),
                Tables\Columns\TextColumn::make('settings.base_url')
                    ->formatStateUsing(fn (?string $state): string => $state === 'https://api.focusnfe.com.br' ? 'Produção' : 'Homologação')
                    ->label('Ambiente'),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->label('Atualizado em'),
            ])
            ->filters([])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListFocusNfeSettings::route('/'),
            'create' => Pages\CreateFocusNfeSetting::route('/create'),
            'edit' => Pages\EditFocusNfeSetting::route('/{record}/edit'),
        ];
    }

    public static function canCreate(): bool
    {
        if (! auth()->check()) {
            return false;
        }

        if (auth()->user()->role === 'admin') {
            return true;
        }

        return ! static::getEloquentQuery()->where('user_id', auth()->id())->exists();
    }

    public static function canEdit(Model $record): bool
    {
        return auth()->user()->role === 'admin' || $record->user_id === auth()->id();
    }

    public static function canDelete(Model $record): bool
    {
        return auth()->user()->role === 'admin';
    }
}
