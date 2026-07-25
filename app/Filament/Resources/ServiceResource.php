<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ServiceResource\Pages;
use App\Jobs\CancelServiceNfseJob;
use App\Jobs\ConsultServiceNfseJob;
use App\Jobs\EmitServiceNfseJob;
use App\Models\Service;
use App\Support\NfseStatus;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Throwable;

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
                        ->default(auth()->id())
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
                    ->native(false)
                    ->label('Cliente'),
                Forms\Components\TextInput::make('code')
                    ->string()
                    ->minLength(2)
                    ->maxLength(255)
                    ->unique(
                        ignoreRecord: true,
                        modifyRuleUsing: fn ($rule, callable $get) => $rule->where('user_id', $get('user_id') ?: auth()->id()),
                    )
                    ->label('Código'),
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->string()
                    ->minLength(2)
                    ->maxLength(255)
                    ->label('Nome'),
                Forms\Components\TextInput::make('municipal_service_code')
                    ->string()
                    ->maxLength(255)
                    ->label('Código municipal'),
                Forms\Components\TextInput::make('lc116_code')
                    ->string()
                    ->maxLength(255)
                    ->label('Código LC 116'),
                Forms\Components\TextInput::make('cnae_code')
                    ->string()
                    ->maxLength(255)
                    ->label('CNAE'),
                Forms\Components\TextInput::make('nbs_code')
                    ->string()
                    ->maxLength(255)
                    ->label('NBS'),
                Forms\Components\TextInput::make('unit')
                    ->default('UN')
                    ->required()
                    ->string()
                    ->minLength(1)
                    ->maxLength(20)
                    ->label('Unidade'),
                Forms\Components\TextInput::make('unit_price')
                    ->numeric()
                    ->default(0)
                    ->minValue(0)
                    ->label('Valor unitário'),
                Forms\Components\TextInput::make('iss_aliquot')
                    ->numeric()
                    ->default(0)
                    ->minValue(0)
                    ->maxValue(100)
                    ->label('Alíquota ISS %'),
                Forms\Components\TextInput::make('pis_aliquot')
                    ->numeric()
                    ->default(0)
                    ->minValue(0)
                    ->maxValue(100)
                    ->label('Alíquota PIS %'),
                Forms\Components\TextInput::make('cofins_aliquot')
                    ->numeric()
                    ->default(0)
                    ->minValue(0)
                    ->maxValue(100)
                    ->label('Alíquota COFINS %'),
                Forms\Components\TextInput::make('inss_aliquot')
                    ->numeric()
                    ->default(0)
                    ->minValue(0)
                    ->maxValue(100)
                    ->label('Alíquota INSS %'),
                Forms\Components\TextInput::make('ir_aliquot')
                    ->numeric()
                    ->default(0)
                    ->minValue(0)
                    ->maxValue(100)
                    ->label('Alíquota IR %'),
                Forms\Components\TextInput::make('csll_aliquot')
                    ->numeric()
                    ->default(0)
                    ->minValue(0)
                    ->maxValue(100)
                    ->label('Alíquota CSLL %'),
                Forms\Components\Textarea::make('description')
                    ->rows(3)
                    ->maxLength(65535)
                    ->label('Descrição'),
                Forms\Components\Textarea::make('notes')
                    ->rows(3)
                    ->maxLength(65535)
                    ->label('Observações'),
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
                Tables\Columns\TextColumn::make('focus_nfse_status')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'autorizado' => 'success',
                        'processando' => 'warning',
                        'erro_autorizacao' => 'danger',
                        'cancelado' => 'gray',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'autorizado' => 'Autorizada',
                        'processando' => 'Processando',
                        'erro_autorizacao' => 'Erro Autorização',
                        'cancelado' => 'Cancelada',
                        default => 'Não emitida',
                    })
                    ->label('NFS-e'),
                Tables\Columns\TextColumn::make('focus_nfse_number')
                    ->label('Número NFS-e')
                    ->toggleable(isToggledHiddenByDefault: true),
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
                Tables\Actions\Action::make('emitir_nfse')
                    ->label('Emitir NFS-e')
                    ->icon('heroicon-o-document-text')
                    ->color('success')
                    ->visible(fn (Service $record): bool => NfseStatus::canEmit($record->focus_nfse_status))
                    ->requiresConfirmation()
                    ->action(function (Service $record): void {
                        try {
                            EmitServiceNfseJob::dispatch($record->id);

                            Notification::make()
                                ->title('Emissão da NFS-e agendada')
                                ->body('O processamento será acompanhado automaticamente.')
                                ->success()
                                ->send();
                        } catch (Throwable $e) {
                            Notification::make()
                                ->title('Erro ao emitir NFS-e')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),

                Tables\Actions\Action::make('consultar_nfse')
                    ->label('Consultar Status')
                    ->icon('heroicon-o-arrow-path')
                    ->color('info')
                    ->visible(fn (Service $record): bool => ! blank($record->focus_nfse_ref))
                    ->action(function (Service $record): void {
                        try {
                            ConsultServiceNfseJob::dispatch($record->id);

                            Notification::make()
                                ->title('Consulta da NFS-e agendada')
                                ->body('O status será atualizado em instantes.')
                                ->info()
                                ->send();
                        } catch (Throwable $e) {
                            Notification::make()
                                ->title('Erro ao consultar NFS-e')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),

                Tables\Actions\Action::make('ver_danfse')
                    ->label('Ver DANFSE')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->color('warning')
                    ->url(fn (Service $record): ?string => $record->focus_nfse_url, shouldOpenInNewTab: true)
                    ->visible(fn (Service $record): bool => ! blank($record->focus_nfse_url)),

                Tables\Actions\Action::make('cancelar_nfse')
                    ->label('Cancelar NFS-e')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (Service $record): bool => $record->focus_nfse_status === 'autorizado')
                    ->form([
                        Forms\Components\Textarea::make('justification')
                            ->label('Justificativa do Cancelamento')
                            ->required()
                            ->minLength(15)
                            ->maxLength(255)
                            ->placeholder('Informe a justificativa do cancelamento exigida pela prefeitura.'),
                    ])
                    ->action(function (Service $record, array $data): void {
                        try {
                            CancelServiceNfseJob::dispatch($record->id, $data['justification']);

                            Notification::make()
                                ->title('Cancelamento da NFS-e agendado')
                                ->body('O resultado será atualizado automaticamente.')
                                ->success()
                                ->send();
                        } catch (Throwable $e) {
                            Notification::make()
                                ->title('Erro ao cancelar NFS-e')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),

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
