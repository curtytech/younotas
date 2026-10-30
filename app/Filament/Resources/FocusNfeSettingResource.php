<?php

namespace App\Filament\Resources;

use App\Filament\Forms\Components\MoneyInput;

use App\Filament\Resources\FocusNfeSettingResource\Pages;
use App\Models\FocusNfeSetting;
use App\Models\Company;
use App\Services\FiscalReadiness;
use App\Services\FocusNfeConfigService;
use App\Support\BrazilianStates;
use App\Support\FiscalDocument;
use Closure;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;

class FocusNfeSettingResource extends CompanyOwnedResource
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
        $query = parent::getEloquentQuery()->with(['company', 'user']);


        return $query;
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Empresa emitente')
                    ->description('A configuração pertence à empresa. Os acessos são gerenciados no cadastro de empresas emitentes.')
                    ->schema([static::companyField('manage_fiscal')]),
                Forms\Components\Section::make('Preferências de emissão')
                    ->schema([
                        Forms\Components\Select::make('settings.environment')->required()->options([
                            'homologacao' => 'Homologação — testes sem valor fiscal',
                            'producao' => 'Produção — documentos com valor fiscal',
                        ])->default('homologacao')->native(false)->live()
                            ->label('Ambiente padrão para novas emissões')
                            ->helperText('Notas já enviadas continuam na conexão original. Trocar este padrão não altera notas existentes.'),
                        Forms\Components\Toggle::make('settings.documents.nfe')->label('Emitir NF-e')->default(true)->live(),
                        Forms\Components\Toggle::make('settings.documents.nfse')->label('Emitir NFS-e municipal')->default(true)->live(),
                        Forms\Components\Placeholder::make('production_notice')->hiddenLabel()
                            ->visible(fn (Get $get): bool => $get('settings.environment') === 'producao')
                            ->content('Produção gera documentos com valor fiscal. A alteração do ambiente será registrada no histórico de configuração.'),
                    ]),
                Forms\Components\Section::make('Prontidão para emitir')
                    ->description('Pendências de cadastro, credencial e habilitação por ambiente, com base nos dados já salvos.')
                    ->visible(fn (?FocusNfeSetting $record): bool => (bool) $record?->company)
                    ->schema([Forms\Components\View::make('filament.fiscal.readiness')
                        ->viewData(fn (?FocusNfeSetting $record): array => ['checks' => $record?->company ? app(FiscalReadiness::class)->checkAll($record->company) : []])]),
                Forms\Components\Group::make()->schema([
                    ...array_map(fn (string $environment, string $label) => Forms\Components\Section::make($label)
                        ->description($environment === 'producao' ? 'Credencial própria para documentos com valor fiscal.' : 'Credencial própria para testes de integração.')
                        ->schema([
                            Forms\Components\Placeholder::make('credential_status_'.$environment)->label('Situação')
                                ->content(fn (?FocusNfeSetting $record): string => static::hasConfiguredToken($record, $environment) ? 'Credencial cadastrada' : 'Credencial não cadastrada'),
                            Forms\Components\TextInput::make('settings.api_keys.'.$environment)
                                ->label('Cadastrar ou substituir token')->password()->autocomplete('new-password')->maxLength(255)
                                ->required(fn (Get $get, ?FocusNfeSetting $record): bool => $get('settings.environment') === $environment && ! $get('remove_api_keys.'.$environment) && ! static::hasConfiguredToken($record, $environment))
                                ->helperText('Deixe vazio para manter a credencial cadastrada. O token atual não é exibido.'),
                            Forms\Components\Toggle::make('remove_api_keys.'.$environment)->label('Remover credencial deste ambiente')->default(false),
                        ]), ['homologacao', 'producao'], ['Homologação', 'Produção']),
                ])->columns(2),
                Forms\Components\Section::make('Integração e notificações')
                    ->visible(fn (): bool => auth()->user()->role === 'admin')
                    ->description('Área técnica de integração. As consultas automáticas continuam funcionando sem webhook.')
                    ->schema([
                        Forms\Components\Placeholder::make('webhook_url_nfe')->label('Webhook NF-e')
                            ->content(fn (): string => rtrim((string) config('app.url'), '/').'/api/webhooks/focus-nfe'),
                        Forms\Components\Placeholder::make('webhook_url_nfse')->label('Webhook NFS-e')
                            ->content(fn (): string => rtrim((string) config('app.url'), '/').'/api/webhooks/focus-nfse'),
                    ]),
                Forms\Components\Section::make('Identificação do emitente')
                    ->description('Identificação comum da empresa e dados municipais para emissão de serviços.')
                    ->schema([
                        Forms\Components\TextInput::make('settings.prestador.cnpj')
                            ->required()
                            ->string()
                            ->maxLength(18)
                            ->rule(fn (): Closure => function (string $attribute, $value, Closure $fail): void {
                                if (blank($value)) {
                                    return;
                                }

                                if (! FiscalDocument::cnpjIsValid((string) $value)) {
                                    $fail('Informe um CNPJ válido.');
                                }
                            })
                            ->dehydrateStateUsing(fn (?string $state): ?string => filled($state) ? FiscalDocument::digits((string) $state) : null)
                            ->label('CNPJ do emitente'),
                        Forms\Components\TextInput::make('settings.prestador.inscricao_municipal')
                            ->required(fn (Get $get): bool => $get('settings.documents.nfse') !== false)
                            ->string()
                            ->maxLength(255)
                            ->label('Inscrição municipal'),
                        Forms\Components\TextInput::make('settings.prestador.codigo_municipio')
                            ->required()
                            ->string()
                            ->maxLength(7)
                            ->rule('regex:/^\d{7}$/')
                            ->validationMessages(['regex' => 'O código do município deve ter 7 dígitos.'])
                            ->label('Código do município'),
                        Forms\Components\TextInput::make('settings.nfse.natureza_operacao')
                            ->required(fn (Get $get): bool => $get('settings.documents.nfse') !== false)
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
                    ->columns(3)
                    ->collapsible(),
                Forms\Components\Section::make('Emitente NF-e')
                    ->description('Dados cadastrais exibidos como emitente na NF-e.')
                    ->schema([
                        Forms\Components\TextInput::make('settings.nfe.emitente.nome')
                            ->required(fn (Get $get): bool => $get('settings.documents.nfe') !== false)
                            ->string()
                            ->maxLength(255)
                            ->label('Razão social'),
                        Forms\Components\TextInput::make('settings.nfe.emitente.nome_fantasia')
                            ->required(fn (Get $get): bool => $get('settings.documents.nfe') !== false)
                            ->string()
                            ->maxLength(255)
                            ->label('Nome fantasia'),
                        Forms\Components\TextInput::make('settings.nfe.emitente.logradouro')
                            ->required(fn (Get $get): bool => $get('settings.documents.nfe') !== false)
                            ->string()
                            ->maxLength(255)
                            ->label('Logradouro'),
                        Forms\Components\TextInput::make('settings.nfe.emitente.numero')
                            ->required(fn (Get $get): bool => $get('settings.documents.nfe') !== false)
                            ->string()
                            ->maxLength(20)
                            ->label('Número'),
                        Forms\Components\TextInput::make('settings.nfe.emitente.bairro')
                            ->required(fn (Get $get): bool => $get('settings.documents.nfe') !== false)
                            ->string()
                            ->maxLength(255)
                            ->label('Bairro'),
                        Forms\Components\TextInput::make('settings.nfe.emitente.municipio')
                            ->required(fn (Get $get): bool => $get('settings.documents.nfe') !== false)
                            ->string()
                            ->maxLength(255)
                            ->label('Município'),
                        Forms\Components\TextInput::make('settings.nfe.emitente.uf')
                            ->required(fn (Get $get): bool => $get('settings.documents.nfe') !== false)
                            ->string()
                            ->maxLength(2)
                            ->rule(fn (): Closure => function (string $attribute, $value, Closure $fail): void {
                                if (blank($value)) {
                                    return;
                                }

                                if (! BrazilianStates::isValid((string) $value)) {
                                    $fail('Informe uma UF válida.');
                                }
                            })
                            ->dehydrateStateUsing(fn (?string $state): ?string => BrazilianStates::normalize($state))
                            ->label('UF'),
                        Forms\Components\TextInput::make('settings.nfe.emitente.cep')
                            ->required(fn (Get $get): bool => $get('settings.documents.nfe') !== false)
                            ->string()
                            ->maxLength(10)
                            ->rule(fn (): Closure => function (string $attribute, $value, Closure $fail): void {
                                if (blank($value)) {
                                    return;
                                }

                                if (! preg_match('/^\d{8}$/', FiscalDocument::digits((string) $value))) {
                                    $fail('O CEP deve conter 8 dígitos.');
                                }
                            })
                            ->dehydrateStateUsing(fn (?string $state): ?string => filled($state) ? FiscalDocument::digits((string) $state) : null)
                            ->label('CEP'),
                        Forms\Components\TextInput::make('settings.nfe.emitente.inscricao_estadual')
                            ->required(fn (Get $get): bool => $get('settings.documents.nfe') !== false)
                            ->string()
                            ->maxLength(255)
                            ->label('Inscrição estadual'),
                        Forms\Components\Select::make('settings.nfe.emitente.regime_tributario')
                            ->required(fn (Get $get): bool => $get('settings.documents.nfe') !== false)
                            ->options([
                                '1' => 'Simples Nacional',
                                '2' => 'Simples Nacional - excesso de sublimite',
                                '3' => 'Regime Normal',
                            ])
                            ->default('1')
                            ->native(false)
                            ->label('Regime tributário'),
                    ])
                    ->columns(3)
                    ->collapsible(),
                Forms\Components\Section::make('Regras NF-e')
                    ->description('Parâmetros fiscais padrão para vendas de mercadorias.')
                    ->schema([
                        Forms\Components\TextInput::make('settings.nfe.natureza_operacao')
                            ->required(fn (Get $get): bool => $get('settings.documents.nfe') !== false)
                            ->string()
                            ->maxLength(255)
                            ->default('VENDA DE MERCADORIA')
                            ->label('Natureza da operação'),
                        Forms\Components\TextInput::make('settings.nfe.tipo_documento')
                            ->required(fn (Get $get): bool => $get('settings.documents.nfe') !== false)
                            ->numeric()
                            ->default(1)
                            ->label('Tipo do documento'),
                        Forms\Components\TextInput::make('settings.nfe.finalidade_emissao')
                            ->required(fn (Get $get): bool => $get('settings.documents.nfe') !== false)
                            ->numeric()
                            ->default(1)
                            ->label('Finalidade da emissão'),
                        Forms\Components\TextInput::make('settings.nfe.consumidor_final')
                            ->required(fn (Get $get): bool => $get('settings.documents.nfe') !== false)
                            ->numeric()
                            ->default(1)
                            ->label('Consumidor final'),
                        Forms\Components\TextInput::make('settings.nfe.presenca_comprador')
                            ->required(fn (Get $get): bool => $get('settings.documents.nfe') !== false)
                            ->numeric()
                            ->default(1)
                            ->label('Presença do comprador'),
                        Forms\Components\TextInput::make('settings.nfe.modalidade_frete')
                            ->required(fn (Get $get): bool => $get('settings.documents.nfe') !== false)
                            ->numeric()
                            ->default(9)
                            ->label('Modalidade do frete'),
                        Forms\Components\TextInput::make('settings.nfe.forma_pagamento')
                            ->required(fn (Get $get): bool => $get('settings.documents.nfe') !== false)
                            ->string()
                            ->maxLength(2)
                            ->default('01')
                            ->label('Forma de pagamento'),
                        Forms\Components\TextInput::make('settings.nfe.cfop_padrao')
                            ->required(fn (Get $get): bool => $get('settings.documents.nfe') !== false)
                            ->string()
                            ->maxLength(10)
                            ->default('5102')
                            ->helperText('Usado quando o produto não tem CFOP próprio. Informe o CFOP dentro do estado; vendas para outra UF usam 6xxx automaticamente.')
                            ->label('CFOP padrão'),
                        Forms\Components\TextInput::make('settings.nfe.cfop_interestadual_padrao')->label('CFOP interestadual padrão')->regex('/^6[0-9]{3}$/')->maxLength(4),
                        Forms\Components\TextInput::make('settings.nfe.codigo_ncm_padrao')
                            ->string()
                            ->maxLength(8)
                            ->label('NCM padrão'),
                        Forms\Components\TextInput::make('settings.nfe.icms_origem')
                            ->required(fn (Get $get): bool => $get('settings.documents.nfe') !== false)
                            ->string()
                            ->maxLength(5)
                            ->default('0')
                            ->label('Origem ICMS'),
                        Forms\Components\TextInput::make('settings.nfe.icms_situacao_tributaria')
                            ->required(fn (Get $get): bool => $get('settings.documents.nfe') !== false)
                            ->string()
                            ->maxLength(5)
                            ->default('102')
                            ->label('Situação tributária ICMS'),
                        Forms\Components\TextInput::make('settings.nfe.pis_situacao_tributaria')
                            ->required(fn (Get $get): bool => $get('settings.documents.nfe') !== false)
                            ->string()
                            ->maxLength(5)
                            ->default('49')
                            ->helperText('Simples Nacional: 49 (outras operações de saída). Confirme com o contador.')
                            ->label('Situação tributária PIS'),
                        Forms\Components\TextInput::make('settings.nfe.cofins_situacao_tributaria')
                            ->required(fn (Get $get): bool => $get('settings.documents.nfe') !== false)
                            ->string()
                            ->maxLength(5)
                            ->default('49')
                            ->helperText('Simples Nacional: 49 (outras operações de saída). Confirme com o contador.')
                            ->label('Situação tributária COFINS'),
                        MoneyInput::make('settings.nfe.valor_frete')
                            ->numeric()
                            ->default(0)
                            ->minValue(0)
                            ->label('Valor do frete'),
                        MoneyInput::make('settings.nfe.valor_seguro')
                            ->numeric()
                            ->default(0)
                            ->minValue(0)
                            ->label('Valor do seguro'),
                        MoneyInput::make('settings.nfe.valor_outras_despesas')
                            ->numeric()
                            ->default(0)
                            ->minValue(0)
                            ->label('Outras despesas'),
                    ])
                    ->columns(3)
                    ->collapsible(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('company.name')->label('Empresa emitente')->searchable(),
                Tables\Columns\TextColumn::make('settings.prestador.cnpj')
                    ->label('CNPJ'),
                Tables\Columns\TextColumn::make('settings.environment')
                    ->getStateUsing(fn ($record): string => FocusNfeConfigService::normalizeSettings($record->settings ?? [])['environment'] === 'producao' ? 'Produção' : 'Homologação')
                    ->label('Padrão para novas emissões'),
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

    public static function hasConfiguredToken(?FocusNfeSetting $record, string $environment): bool
    {
        return $record?->company?->connections()->where('environment', $environment)->where('enabled', true)->get()->contains(fn ($connection): bool => filled($connection->api_key)) ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->check() && (auth()->user()->role === 'admin' || static::companyOptions('manage_fiscal') !== []);
    }

    public static function canEdit(Model $record): bool
    {
        return static::canManageCompanyRecord($record, 'manage_fiscal');
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }
}
