<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ClientResource\Pages;
use App\Models\Client;
use App\Models\Company;
use App\Services\CepService;
use App\Services\FocusMunicipioService;
use App\Support\BrazilianStates;
use App\Support\FiscalDocument;
use Closure;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ClientResource extends CompanyOwnedResource
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


        return $query;
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                static::companyField(),
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->string()
                    ->minLength(3)
                    ->maxLength(255)
                    ->label('Nome do cliente'),
                Forms\Components\TextInput::make('email')
                    ->email()
                    ->nullable()
                    ->string()
                    ->maxLength(255)
                    ->unique(
                        ignoreRecord: true,
                        modifyRuleUsing: fn ($rule, callable $get) => $rule->where('company_id', $get('company_id')),
                    )
                    ->label('E-mail'),
                Forms\Components\TextInput::make('phone')
                    ->required()
                    ->string()
                    ->minLength(8)
                    ->maxLength(30)
                    ->label('Telefone'),
                Forms\Components\Select::make('document_type')
                    ->options([
                        'cpf' => 'CPF',
                        'cnpj' => 'CNPJ',
                        'nif' => 'NIF',
                    ])
                    ->required()
                    ->live()
                    ->native(false)
                    ->label('Tipo de documento'),
                Forms\Components\TextInput::make('inscricao_estatual')
                    ->string()
                    ->minLength(3)
                    ->maxLength(15)
                    ->label('Inscrição Estadual'),
                Forms\Components\Select::make('indicador_ie')->label('Condição perante o ICMS')
                    ->options(['1' => 'Contribuinte', '2' => 'Contribuinte isento', '9' => 'Não contribuinte'])
                    ->helperText('Sem escolha explícita, a inscrição estadual cadastrada determina a condição.'),

                Forms\Components\TextInput::make('document')
                    ->required()
                    ->string()
                    ->minLength(3)
                    ->maxLength(255)
                    ->live(onBlur: true)
                    ->dehydrateStateUsing(fn (?string $state, Get $get): ?string => static::sanitizeDocument($state, $get('document_type')))
                    ->rule(fn (Get $get): Closure => function (string $attribute, $value, Closure $fail) use ($get): void {
                        $documentType = $get('document_type');
                        $document = static::sanitizeDocument(is_string($value) ? $value : null, $documentType);

                        if (blank($document)) {
                            return;
                        }

                        match ($documentType) {
                            'cpf' => FiscalDocument::cpfIsValid($document) ?: $fail('Informe um CPF válido.'),
                            'cnpj' => FiscalDocument::cnpjIsValid($document) ?: $fail('Informe um CNPJ válido.'),
                            'nif' => static::isValidNif($document) ?: $fail('Informe um NIF válido.'),
                            default => $fail('Selecione um tipo de documento válido.'),
                        };
                    })
                    ->unique(
                        ignoreRecord: true,
                        modifyRuleUsing: fn ($rule, callable $get) => $rule
                            ->where('company_id', $get('company_id'))
                            ->where('document_type', $get('document_type'))
                            ->where('document', static::sanitizeDocument($get('document'), $get('document_type'))),
                    )
                    ->helperText('CPF: 11 digitos. CNPJ: 14 digitos. NIF: 5 a 20 caracteres alfanumericos.')
                    ->label('Documento'),
                Forms\Components\TextInput::make('address')
                    ->required()
                    ->string()
                    ->minLength(3)
                    ->maxLength(255)
                    ->label('Endereço'),
                Forms\Components\TextInput::make('address_number')
                    ->string()
                    ->maxLength(50)
                    ->label('Número'),
                Forms\Components\TextInput::make('address_complement')
                    ->string()
                    ->maxLength(255)
                    ->label('Complemento'),
                Forms\Components\TextInput::make('neighborhood')
                    ->string()
                    ->maxLength(255)
                    ->label('Bairro'),
                Forms\Components\TextInput::make('city')
                    ->required()
                    ->string()
                    ->minLength(2)
                    ->maxLength(255)
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn (Get $get, Set $set) => static::fillIbgeCode($get, $set))
                    ->label('Cidade'),
                Forms\Components\TextInput::make('ibge_code')
                    ->required(fn (Get $get): bool => static::isBrazilian($get('country')))
                    ->validationMessages(['required' => 'Informe o código IBGE do município.'])
                    ->rule(fn (): Closure => function (string $attribute, $value, Closure $fail): void {
                        if (blank($value)) {
                            return;
                        }

                        if (! preg_match('/^\d{7}$/', FiscalDocument::digits((string) $value))) {
                            $fail('O código IBGE deve ter 7 dígitos.');
                        }
                    })
                    ->helperText('Preenchido automaticamente a partir da cidade e UF. Pode ser alterado manualmente.')
                    ->label('Código IBGE do município'),
                Forms\Components\TextInput::make('state')
                    ->required(fn (Get $get): bool => static::isBrazilian($get('country')))
                    ->validationMessages(['required' => 'Informe a UF.'])
                    ->string()
                    ->maxLength(2)
                    ->live(onBlur: true)
                    ->dehydrateStateUsing(fn (?string $state): ?string => BrazilianStates::normalize($state))
                    ->rule(fn (): Closure => function (string $attribute, $value, Closure $fail): void {
                        if (blank($value)) {
                            return;
                        }

                        if (! BrazilianStates::isValid((string) $value)) {
                            $fail('Informe uma UF válida.');
                        }
                    })
                    ->afterStateUpdated(fn (Get $get, Set $set) => static::fillIbgeCode($get, $set))
                    ->label('UF'),
                Forms\Components\TextInput::make('zip_code')
                    ->string()
                    ->maxLength(20)
                    ->live(onBlur: true)
                    ->rule(fn (): Closure => function (string $attribute, $value, Closure $fail): void {
                        if (blank($value)) {
                            return;
                        }

                        if (! preg_match('/^\d{8}$/', FiscalDocument::digits((string) $value))) {
                            $fail('O CEP deve conter 8 dígitos.');
                        }
                    })
                    ->dehydrateStateUsing(fn (?string $state): ?string => filled($state) ? FiscalDocument::digits((string) $state) : null)
                    ->afterStateUpdated(function (?string $state, Set $set): void {
                        if (blank($state)) {
                            return;
                        }

                        $address = app(CepService::class)->addressFor($state);

                        if ($address === null) {
                            Notification::make()
                                ->danger()
                                ->title('CEP não encontrado')
                                ->body('Confira o CEP informado e tente novamente.')
                                ->send();

                            return;
                        }

                        foreach (['address', 'neighborhood', 'city', 'state', 'ibge_code'] as $field) {
                            if ($address[$field] !== null) {
                                $set($field, $address[$field]);
                            }
                        }
                    })
                    ->helperText('Preenche o endereço automaticamente ao informar um CEP válido.')
                    ->label('CEP'),
                Forms\Components\TextInput::make('country')
                    ->default('BR')
                    ->required()
                    ->string()
                    ->length(2)
                    ->maxLength(2)
                    ->live(onBlur: true)
                    ->dehydrateStateUsing(fn (?string $state): ?string => filled($state) ? strtoupper(trim($state)) : null)
                    ->label('País'),
                Forms\Components\Toggle::make('is_active')
                    ->default(true)
                    ->label('Ativo'),
            ]);
    }

    protected static function fillIbgeCode(Get $get, Set $set): void
    {
        $user = Company::find($get('company_id'));
        if (! $user || ! auth()->user()->canAccessCompany($user, 'issue')) {
            return;
        }
        $code = app(FocusMunicipioService::class)->ibgeCodeFor($user, $get('city'), $get('state'));

        if ($code !== null) {
            $set('ibge_code', $code);
        }
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
        return parent::canCreate();
    }

    public static function canEdit(Model $record): bool
    {
        return static::canManageCompanyRecord($record);
    }

    public static function canDelete(Model $record): bool
    {
        return static::canManageCompanyRecord($record);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListClients::route('/'),
            'create' => Pages\CreateClient::route('/create'),
            'edit' => Pages\EditClient::route('/{record}/edit'),
        ];
    }

    protected static function sanitizeDocument(?string $document, ?string $documentType): ?string
    {
        if (blank($document)) {
            return null;
        }

        return match ($documentType) {
            'cpf', 'cnpj' => preg_replace('/\D/', '', $document),
            'nif' => strtoupper(trim($document)),
            default => trim($document),
        };
    }

    protected static function isValidCpf(string $cpf): bool
    {
        return FiscalDocument::cpfIsValid($cpf);
    }

    protected static function isValidCnpj(string $cnpj): bool
    {
        return FiscalDocument::cnpjIsValid($cnpj);
    }

    protected static function isBrazilian(?string $country): bool
    {
        return strtoupper(trim((string) $country)) === 'BR';
    }

    protected static function isValidNif(string $nif): bool
    {
        return (bool) preg_match('/^[A-Z0-9\-\.\/]{5,20}$/', $nif);
    }
}
