<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ClientResource\Pages;
use App\Models\Client;
use Closure;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
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
                        ->default(auth()->id())
                        ->required()
                        ->label('Usuário')
                    : Forms\Components\Hidden::make('user_id')
                        ->default(auth()->id()),
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->string()
                    ->minLength(3)
                    ->maxLength(255)
                    ->label('Nome do cliente'),
                Forms\Components\TextInput::make('email')
                    ->email()
                    ->required()
                    ->string()
                    ->maxLength(255)
                    ->unique(
                        ignoreRecord: true,
                        modifyRuleUsing: fn ($rule, callable $get) => $rule->where('user_id', $get('user_id') ?: auth()->id()),
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
                            'cpf' => static::isValidCpf($document) ?: $fail('Informe um CPF valido.'),
                            'cnpj' => static::isValidCnpj($document) ?: $fail('Informe um CNPJ valido.'),
                            'nif' => static::isValidNif($document) ?: $fail('Informe um NIF valido.'),
                            default => $fail('Selecione um tipo de documento valido.'),
                        };
                    })
                    ->unique(
                        ignoreRecord: true,
                        modifyRuleUsing: fn ($rule, callable $get) => $rule
                            ->where('user_id', $get('user_id') ?: auth()->id())
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
                    ->label('Cidade'),
                Forms\Components\TextInput::make('state')
                    ->string()
                    ->length(2)
                    ->maxLength(2)
                    ->label('UF'),
                Forms\Components\TextInput::make('zip_code')
                    ->string()
                    ->maxLength(20)
                    ->label('CEP'),
                Forms\Components\TextInput::make('country')
                    ->default('BR')
                    ->required()
                    ->string()
                    ->length(2)
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
        if (! preg_match('/^\d{11}$/', $cpf) || preg_match('/^(\d)\1{10}$/', $cpf)) {
            return false;
        }

        for ($digitPosition = 9; $digitPosition < 11; $digitPosition++) {
            $sum = 0;

            for ($i = 0; $i < $digitPosition; $i++) {
                $sum += ((int) $cpf[$i]) * (($digitPosition + 1) - $i);
            }

            $digit = ((10 * $sum) % 11) % 10;

            if ((int) $cpf[$digitPosition] !== $digit) {
                return false;
            }
        }

        return true;
    }

    protected static function isValidCnpj(string $cnpj): bool
    {
        if (! preg_match('/^\d{14}$/', $cnpj) || preg_match('/^(\d)\1{13}$/', $cnpj)) {
            return false;
        }

        $firstWeights = [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
        $secondWeights = [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];

        $firstDigit = static::calculateCnpjDigit($cnpj, $firstWeights);
        $secondDigit = static::calculateCnpjDigit($cnpj, $secondWeights);

        return (int) $cnpj[12] === $firstDigit && (int) $cnpj[13] === $secondDigit;
    }

    protected static function calculateCnpjDigit(string $cnpj, array $weights): int
    {
        $sum = 0;

        foreach ($weights as $index => $weight) {
            $sum += ((int) $cnpj[$index]) * $weight;
        }

        $remainder = $sum % 11;

        return $remainder < 2 ? 0 : 11 - $remainder;
    }

    protected static function isValidNif(string $nif): bool
    {
        return (bool) preg_match('/^[A-Z0-9\-\.\/]{5,20}$/', $nif);
    }
}
