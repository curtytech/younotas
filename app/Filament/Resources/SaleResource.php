<?php

namespace App\Filament\Resources;

use App\Actions\ConsultSaleNfeAction;
use App\Exceptions\FocusRateLimitedException;
use App\Filament\Forms\Components\MoneyInput;
use App\Filament\Resources\SaleResource\Pages;
use App\Filament\Resources\SaleResource\RelationManagers\SaleItemsRelationManager;
use App\Jobs\CancelSaleNfeJob;
use App\Jobs\ConsultSaleNfeJob;
use App\Jobs\EmitSaleNfeJob;
use App\Models\Client;
use App\Models\Product;
use App\Models\Sale;
use App\Services\FiscalDocumentWorkflow;
use App\Services\FiscalEmissionPreparation;
use App\Support\NfeStatus;
use Closure;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Support\Enums\FontWeight;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class SaleResource extends CompanyOwnedResource
{
    protected static ?string $model = Sale::class;

    protected static ?string $navigationIcon = 'heroicon-o-shopping-cart';

    protected static ?string $modelLabel = 'Venda';

    protected static ?string $pluralModelLabel = 'Vendas';

    protected static ?string $navigationLabel = 'Vendas';

    protected static ?string $navigationGroup = 'Comercial';

    protected static ?int $navigationSort = 10;

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        return $query;
    }

    public const STATUS_OPTIONS = [
        'draft' => 'Rascunho',
        'pending' => 'Pendente',
        'completed' => 'Concluída',
        'canceled' => 'Cancelada',
    ];

    public const PAYMENT_OPTIONS = [
        'pending' => 'Pendente',
        'partial' => 'Parcial',
        'paid' => 'Pago',
        'refunded' => 'Estornado',
        'canceled' => 'Cancelado',
    ];

    public static function form(Form $form): Form
    {
        return $form
            ->columns(3)
            ->schema([
                Forms\Components\Section::make('Nota fiscal')
                    ->icon(fn (?Sale $record): string => NfeStatus::icon($record?->focus_nfe_status))
                    ->iconColor(fn (?Sale $record): string => NfeStatus::color($record?->focus_nfe_status))
                    ->visible(fn (?Sale $record): bool => filled($record?->focus_nfe_ref))
                    ->columns(4)
                    ->schema([
                        Forms\Components\Placeholder::make('nfe_situacao')
                            ->label('Situação')
                            ->content(fn (Sale $record): HtmlString => static::badge(NfeStatus::label($record->focus_nfe_status), NfeStatus::color($record->focus_nfe_status))),
                        Forms\Components\Placeholder::make('nfe_numero')
                            ->label('Número')
                            ->content(fn (Sale $record): string => $record->focus_nfe_number ?: 'Ainda não numerada'),
                        Forms\Components\Placeholder::make('nfe_ambiente')
                            ->label('Ambiente')
                            ->content(fn (Sale $record): string => static::environmentLabel($record->focus_nfe_environment)),
                        Forms\Components\Placeholder::make('nfe_consulta')
                            ->label('Última atualização')
                            ->content(fn (Sale $record): string => ($record->focus_nfe_last_checked_at ?? $record->focus_nfe_last_webhook_at ?? $record->focus_nfe_last_sent_at)?->format('d/m/Y H:i') ?? '—'),
                        Forms\Components\Placeholder::make('nfe_diagnostico')
                            ->hiddenLabel()
                            ->columnSpanFull()
                            ->visible(fn (Sale $record): bool => $record->focus_nfe_status !== NfeStatus::AUTHORIZED && $record->focus_nfe_status !== NfeStatus::CANCELED)
                            ->content(fn (Sale $record) => view('filament.fiscal.diagnosis', ['diagnosis' => $record->nfeDiagnosis()])),
                    ]),
                Forms\Components\Placeholder::make('nfe_bloqueio')
                    ->hiddenLabel()
                    ->columnSpanFull()
                    ->visible(fn (?Sale $record): bool => (bool) $record?->hasLockedNfe())
                    ->content('Cliente, itens e valores ficam bloqueados porque a NF-e já foi enviada. Para alterá-los, cancele a nota.'),
                Forms\Components\Section::make('Dados da venda')
                    ->icon('heroicon-o-shopping-cart')
                    ->columns(2)
                    ->columnSpan(2)
                    ->schema([
                        static::companyField()
                            ->afterStateUpdated(fn (Set $set) => $set('client_id', null))
                            ->columnSpanFull(),
                        Forms\Components\Select::make('client_id')
                            ->relationship('client', 'name', fn (Builder $query, Get $get) => $query->where('company_id', $get('company_id'))->orderBy('name'))
                            ->getOptionLabelFromRecordUsing(fn (Client $record): string => $record->name.($record->document ? ' · '.$record->document : ''))
                            ->searchable(['name', 'email', 'document'])
                            ->preload()
                            ->required()
                            ->disabled(fn (?Sale $record): bool => (bool) $record?->hasLockedNfe())
                            ->validationMessages(['required' => 'Escolha o cliente. A NF-e precisa de um destinatário.'])
                            ->label('Cliente'),
                        Forms\Components\DatePicker::make('sale_date')
                            ->required()
                            ->native(false)
                            ->displayFormat('d/m/Y')
                            ->default(now())
                            ->maxDate(now())
                            ->label('Data da venda'),
                        Forms\Components\Textarea::make('notes')
                            ->rows(2)
                            ->autosize()
                            ->maxLength(65535)
                            ->placeholder('Informações internas ou combinados com o cliente')
                            ->columnSpanFull()
                            ->label('Observações'),
                    ]),
                Forms\Components\Section::make('Resumo')
                    ->icon('heroicon-o-calculator')
                    ->columnSpan(1)
                    ->schema([
                        Forms\Components\Hidden::make('subtotal_amount')->default(0),
                        Forms\Components\Placeholder::make('subtotal')
                            ->inlineLabel()
                            ->content(fn (Get $get): string => static::money((float) $get('subtotal_amount')))
                            ->label('Subtotal'),
                        MoneyInput::make('discount_amount')
                            ->numeric()
                            ->default(0)
                            ->minValue(0)
                            ->live(onBlur: true)
                            ->inlineLabel()
                            ->disabled(fn (?Sale $record): bool => (bool) $record?->hasLockedNfe())
                            ->rule(fn (Get $get, ?Sale $record): Closure => function (string $attribute, $value, Closure $fail) use ($get, $record): void {
                                $items = $get('saleItems');
                                $subtotal = filled($items) ? static::subtotalFromItems($items) : (float) ($record?->subtotal_amount ?? 0);

                                if ((float) $value > $subtotal + 0.001) {
                                    $fail('O desconto não pode ser maior que o subtotal.');
                                }
                            })
                            ->afterStateUpdated(function ($state, callable $get, callable $set, ?Sale $record): void {
                                static::updateSaleTotals($get('saleItems') ?? $record?->saleItems()->get()->toArray() ?? [], $get, $set);
                            })
                            ->label('Desconto'),
                        MoneyInput::make('tax_amount')
                            ->numeric()
                            ->default(0)
                            ->minValue(0)
                            ->live(onBlur: true)
                            ->inlineLabel()
                            ->disabled(fn (?Sale $record): bool => (bool) $record?->hasLockedNfe())
                            ->afterStateUpdated(function ($state, callable $get, callable $set, ?Sale $record): void {
                                static::updateSaleTotals($get('saleItems') ?? $record?->saleItems()->get()->toArray() ?? [], $get, $set);
                            })
                            ->label('Impostos'),
                        Forms\Components\Hidden::make('total_amount')->default(0),
                        Forms\Components\Placeholder::make('total')
                            ->inlineLabel()
                            ->content(fn (Get $get): string => static::money((float) $get('total_amount')))
                            ->extraAttributes(['class' => 'text-xl font-bold text-primary-600 dark:text-primary-400'])
                            ->label('Total'),
                    ]),
                Forms\Components\Section::make('Situação')
                    ->icon('heroicon-o-flag')
                    ->columns(['default' => 1, 'md' => 3])
                    ->schema([
                        Forms\Components\Group::make([
                            Forms\Components\ToggleButtons::make('status')
                                ->options(static::STATUS_OPTIONS)
                                ->colors(['draft' => 'gray', 'pending' => 'warning', 'completed' => 'success', 'canceled' => 'danger'])
                                ->default('draft')
                                ->required()
                                ->inline()
                                ->label('Venda'),
                            Forms\Components\ToggleButtons::make('payment_status')
                                ->options(static::PAYMENT_OPTIONS)
                                ->colors(['pending' => 'warning', 'partial' => 'info', 'paid' => 'success', 'refunded' => 'gray', 'canceled' => 'danger'])
                                ->default('pending')
                                ->required()
                                ->inline()
                                ->label('Pagamento'),
                        ])->columnSpan(['default' => 1, 'md' => 2]),
                        Forms\Components\Toggle::make('issue_invoice')
                            ->default(false)
                            ->columnSpan(1)
                            ->helperText('Marca a venda para emissão de NF-e. A emissão é feita pela ação "Emitir NF-e".')
                            ->label('Precisa de nota fiscal'),
                    ]),
                Forms\Components\Section::make('Produtos')
                    ->icon('heroicon-o-cube')
                    ->description('O estoque é baixado ao salvar a venda.')
                    ->visible(fn (string $operation): bool => $operation === 'create')
                    ->schema([
                        Forms\Components\Repeater::make('saleItems')
                            ->relationship()
                            ->hiddenLabel()
                            ->defaultItems(1)
                            ->minItems(1)
                            ->reorderable(false)
                            ->collapsible()
                            ->cloneable()
                            ->live()
                            ->itemLabel(fn (array $state): string => filled($state['product_name'] ?? null)
                                ? $state['product_name'].' — '.static::money((float) ($state['total_amount'] ?? 0))
                                : 'Novo produto')
                            ->afterStateUpdated(function (?array $state, callable $get, callable $set): void {
                                static::updateSaleTotals($state ?? [], $get, $set);
                            })
                            ->addActionLabel('Adicionar produto')
                            ->schema(static::saleItemSchema())
                            ->columns(12),
                    ]),
            ]);
    }

    /** @return array<int, Forms\Components\Component> */
    protected static function saleItemSchema(): array
    {
        return [
            Forms\Components\Select::make('product_id')
                ->options(fn (Get $get): array => Product::query()
                    ->where('company_id', $get('../../company_id'))
                    ->orderBy('name')
                    ->pluck('name', 'id')
                    ->all())
                ->searchable()
                ->preload()
                ->required()
                ->native(false)
                ->columnSpan(['default' => 12, 'md' => 4])
                ->label('Produto')
                ->live()
                ->afterStateUpdated(function ($state, callable $get, callable $set): void {
                    $product = Product::query()->find($state);

                    if (! $product) {
                        return;
                    }

                    $set('product_name', $product->name);
                    $set('product_code', (string) $product->id);
                    $set('unit', $product->unit);
                    $set('unit_price', $product->sale_price);

                    static::updateSaleItemTotal($get, $set);
                }),
            Forms\Components\Hidden::make('product_name'),
            Forms\Components\Hidden::make('product_code'),
            Forms\Components\Hidden::make('unit')->default('UN'),
            Forms\Components\TextInput::make('quantity')
                ->numeric()
                ->required()
                ->default(1)
                ->minValue(0.001)
                ->suffix(fn (Get $get): string => $get('unit') ?: 'UN')
                ->live(onBlur: true)
                ->rule(fn (Get $get): Closure => function (string $attribute, $value, Closure $fail) use ($get): void {
                    $requestedQuantity = (float) ($value ?: 0);
                    $availableStock = static::getAvailableStock((int) ($get('product_id') ?: 0));

                    if ($availableStock === null || $requestedQuantity <= 0 || $requestedQuantity <= $availableStock) {
                        return;
                    }

                    $fail(sprintf(
                        'Estoque insuficiente. Disponível: %s, solicitado: %s.',
                        static::quantity($availableStock),
                        static::quantity($requestedQuantity),
                    ));
                })
                ->helperText(fn (Get $get): ?string => static::getStockWarningMessage(
                    productId: (int) ($get('product_id') ?: 0),
                    requestedQuantity: (float) ($get('quantity') ?: 0),
                ))
                ->columnSpan(['default' => 6, 'md' => 3])
                ->label('Quantidade')
                ->afterStateUpdated(function ($state, callable $get, callable $set): void {
                    static::updateSaleItemTotal($get, $set);
                }),
            MoneyInput::make('unit_price')
                ->numeric()
                ->required()
                ->default(0)
                ->minValue(0)
                ->live(onBlur: true)
                ->columnSpan(['default' => 6, 'md' => 3])
                ->label('Valor unitário')
                ->afterStateUpdated(function ($state, callable $get, callable $set): void {
                    static::updateSaleItemTotal($get, $set);
                }),
            MoneyInput::make('total_amount')
                ->numeric()
                ->required()
                ->default(0)
                ->minValue(0)
                ->readOnly()
                ->columnSpan(['default' => 12, 'md' => 2])
                ->label('Total do item'),
            Forms\Components\Section::make('Desconto e tributos do item')
                ->description('Opcional')
                ->collapsible()
                ->collapsed()
                ->compact()
                ->columns(['default' => 2, 'xl' => 5])
                ->columnSpanFull()
                ->schema([
                    MoneyInput::make('discount_amount')
                        ->numeric()
                        ->default(0)
                        ->minValue(0)
                        ->live(onBlur: true)
                        ->rule(fn (Get $get): Closure => function (string $attribute, $value, Closure $fail) use ($get): void {
                            $base = (float) ($get('quantity') ?: 0) * (float) ($get('unit_price') ?: 0) + (float) ($get('tax_amount') ?: 0);

                            if ((float) $value > $base + 0.001) {
                                $fail('O desconto não pode ser maior que o valor do item.');
                            }
                        })
                        ->label('Desconto')
                        ->afterStateUpdated(function ($state, callable $get, callable $set): void {
                            static::updateSaleItemTotal($get, $set);
                        }),
                    MoneyInput::make('tax_amount')
                        ->numeric()
                        ->default(0)
                        ->minValue(0)
                        ->live(onBlur: true)
                        ->label('Impostos')
                        ->afterStateUpdated(function ($state, callable $get, callable $set): void {
                            static::updateSaleItemTotal($get, $set);
                        }),
                    MoneyInput::make('icms_base_calculo')->label('Base ICMS')->numeric()->minValue(0)->hint('Opcional')->helperText('Obrigatória com alíquota positiva.'),
                    MoneyInput::make('pis_base_calculo')->label('Base PIS')->numeric()->minValue(0)->hint('Opcional')->helperText('Obrigatória com alíquota positiva.'),
                    MoneyInput::make('cofins_base_calculo')->label('Base COFINS')->numeric()->minValue(0)->hint('Opcional')->helperText('Obrigatória com alíquota positiva.'),
                ]),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['client', 'company']))
            ->defaultSort('id', 'desc')
            ->poll(fn (): ?string => static::getEloquentQuery()->whereIn('focus_nfe_status', [NfeStatus::SENDING, NfeStatus::PROCESSING])->exists() ? '10s' : null)
            ->emptyStateIcon('heroicon-o-shopping-cart')
            ->emptyStateHeading('Nenhuma venda registrada')
            ->emptyStateDescription('Crie a primeira venda para baixar o estoque e emitir a NF-e.')
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->searchable()
                    ->sortable()
                    ->formatStateUsing(fn (Sale $record): string => '#'.($record->number ?: $record->id))
                    ->description(fn (Sale $record): string => $record->sale_date?->format('d/m/Y') ?? '')
                    ->weight(FontWeight::Bold)
                    ->label('Venda'),
                Tables\Columns\TextColumn::make('client.name')
                    ->searchable()
                    ->placeholder('Sem cliente')
                    ->description(fn (Sale $record): ?string => static::userHasManyCompanies() ? Str::limit((string) $record->company?->name, 24) : null)
                    ->limit(32)
                    ->tooltip(fn (Sale $record): ?string => mb_strlen((string) $record->client?->name) > 32 ? $record->client?->name : null)
                    ->label('Cliente'),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'pending' => 'warning',
                        'completed' => 'success',
                        'canceled' => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => static::STATUS_OPTIONS[$state] ?? $state)
                    ->description(fn (Sale $record): string => 'Pagamento: '.(static::PAYMENT_OPTIONS[$record->payment_status] ?? $record->payment_status))
                    ->label('Situação'),
                Tables\Columns\TextColumn::make('focus_nfe_status')
                    ->badge()
                    ->default('none')
                    ->formatStateUsing(fn (Sale $record): string => NfeStatus::label($record->focus_nfe_status))
                    ->color(fn (Sale $record): string => NfeStatus::color($record->focus_nfe_status))
                    ->icon(fn (Sale $record): string => NfeStatus::icon($record->focus_nfe_status))
                    ->description(fn (Sale $record): ?string => static::nfeDescription($record))
                    ->tooltip(fn (Sale $record): ?string => NfeStatus::hasProblem($record->focus_nfe_status) ? $record->nfeDiagnosis()['summary'] : null)
                    ->label('NF-e'),
                Tables\Columns\TextColumn::make('total_amount')
                    ->money('BRL')
                    ->sortable()
                    ->weight(FontWeight::SemiBold)
                    ->alignEnd()
                    ->summarize(Tables\Columns\Summarizers\Sum::make()->money('BRL')->label('Total'))
                    ->label('Total'),
                Tables\Columns\TextColumn::make('user.name')
                    ->label('Usuário')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->visible(auth()->user()->role === 'admin'),
                Tables\Columns\TextColumn::make('company.name')
                    ->label('Empresa')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\IconColumn::make('issue_invoice')
                    ->boolean()
                    ->label('Precisa de nota')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('focus_nfe_last_checked_at')
                    ->dateTime('d/m/Y H:i')
                    ->label('Última consulta')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('user_id')
                    ->relationship('user', 'name')
                    ->label('Usuário')
                    ->visible(fn (): bool => auth()->user()->role === 'admin'),
                Tables\Filters\SelectFilter::make('status')
                    ->options(static::STATUS_OPTIONS)
                    ->label('Situação'),
                Tables\Filters\SelectFilter::make('payment_status')
                    ->options(static::PAYMENT_OPTIONS)
                    ->label('Pagamento'),
                Tables\Filters\SelectFilter::make('nota')
                    ->label('NF-e')
                    ->options([
                        'none' => 'Não emitida',
                        'processing' => 'Em processamento',
                        'authorized' => 'Autorizada',
                        'problem' => 'Com problema',
                        'canceled' => 'Cancelada',
                    ])
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        'none' => $query->whereNull('focus_nfe_status'),
                        'processing' => $query->whereIn('focus_nfe_status', [NfeStatus::SENDING, NfeStatus::PROCESSING]),
                        'authorized' => $query->where('focus_nfe_status', NfeStatus::AUTHORIZED),
                        'problem' => $query->whereIn('focus_nfe_status', [NfeStatus::AUTHORIZATION_ERROR, NfeStatus::TRANSPORT_ERROR, NfeStatus::DENIED, NfeStatus::CANCELLATION_ERROR]),
                        'canceled' => $query->where('focus_nfe_status', NfeStatus::CANCELED),
                        default => $query,
                    }),
                Tables\Filters\Filter::make('sale_date')
                    ->label('Período')
                    ->form([
                        Forms\Components\DatePicker::make('from')->label('De'),
                        Forms\Components\DatePicker::make('until')->label('Até'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $query, $date) => $query->whereDate('sale_date', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $query, $date) => $query->whereDate('sale_date', '<=', $date)))
                    ->indicateUsing(fn (array $data): ?string => match (true) {
                        filled($data['from'] ?? null) && filled($data['until'] ?? null) => 'De '.Carbon::parse($data['from'])->format('d/m/Y').' até '.Carbon::parse($data['until'])->format('d/m/Y'),
                        filled($data['from'] ?? null) => 'A partir de '.Carbon::parse($data['from'])->format('d/m/Y'),
                        filled($data['until'] ?? null) => 'Até '.Carbon::parse($data['until'])->format('d/m/Y'),
                        default => null,
                    }),
            ])
            ->actions([
                Tables\Actions\Action::make('abrir_danfe')
                    ->label('DANFE')
                    ->icon('heroicon-o-document-arrow-down')
                    ->color('success')
                    ->visible(fn (Sale $record): bool => $record->focus_nfe_status === NfeStatus::AUTHORIZED && filled($record->focus_nfe_url))
                    ->url(fn (Sale $record): ?string => $record->focus_nfe_url)
                    ->openUrlInNewTab(),
                Tables\Actions\Action::make('ver_problema')
                    ->label('Ver problema')
                    ->icon('heroicon-o-exclamation-triangle')
                    ->color('danger')
                    ->iconButton()
                    ->tooltip('Ver por que a NF-e não foi autorizada')
                    ->visible(fn (Sale $record): bool => NfeStatus::hasProblem($record->focus_nfe_status))
                    ->modalHeading('Por que a NF-e não foi autorizada')
                    ->modalIcon('heroicon-o-exclamation-triangle')
                    ->modalIconColor('danger')
                    ->modalContent(fn (Sale $record) => view('filament.fiscal.diagnosis', ['diagnosis' => $record->nfeDiagnosis()]))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Fechar'),
                static::emitTableAction('emitir_nfe')
                    ->visible(fn (Sale $record): bool => ! NfeStatus::hasProblem($record->focus_nfe_status) && static::canEmit($record)),
                Tables\Actions\Action::make('consultar_nfe')
                    ->label('Atualizar situação')
                    ->iconButton()
                    ->tooltip('Consultar agora a situação da NF-e')
                    ->icon('heroicon-o-arrow-path')
                    ->visible(fn (Sale $record): bool => static::canConsult($record))
                    ->action(fn (Sale $record) => static::consultNow($record)),
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\EditAction::make(),
                    static::emitTableAction('reenviar_nfe')
                        ->visible(fn (Sale $record): bool => NfeStatus::hasProblem($record->focus_nfe_status) && static::canEmit($record)),
                    Tables\Actions\Action::make('previsualizar_danfe')
                        ->label('Pré-visualizar DANFE')
                        ->icon('heroicon-o-eye')
                        ->visible(fn (Sale $record): bool => $record->focus_nfe_status !== NfeStatus::AUTHORIZED)
                        ->action(fn (Sale $record) => static::prepareDanfePreview($record)),
                    Tables\Actions\Action::make('cancelar_nfe')
                        ->label('Cancelar NF-e')->icon('heroicon-o-x-circle')->color('danger')
                        ->visible(fn (Sale $record): bool => static::canManageCompanyRecord($record) && filled($record->focus_nfe_connection_id) && NfeStatus::canCancel($record->focus_nfe_status))
                        ->requiresConfirmation()
                        ->modalDescription('O cancelamento é enviado à SEFAZ e não pode ser desfeito.')
                        ->form([Forms\Components\Textarea::make('justification')->label('Justificativa')->helperText('Entre 15 e 255 caracteres.')->required()->minLength(15)->maxLength(255)])
                        ->action(fn (Sale $record, array $data): mixed => CancelSaleNfeJob::dispatch($record->id, $data['justification']))
                        ->successNotificationTitle('Cancelamento enviado. A situação é atualizada quando a SEFAZ responder.'),
                ])->tooltip('Mais ações'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    protected static function emitTableAction(string $name): Tables\Actions\Action
    {
        return Tables\Actions\Action::make($name)
            ->label(fn (Sale $record): string => match (true) {
                NfeStatus::requiresNewReference($record->focus_nfe_status) => 'Emitir nova NF-e',
                NfeStatus::hasProblem($record->focus_nfe_status) => 'Reenviar NF-e',
                default => 'Emitir NF-e',
            })
            ->icon('heroicon-o-paper-airplane')
            ->requiresConfirmation()
            ->modalDescription('A nota é enviada à SEFAZ e costuma ser autorizada em alguns segundos. A lista atualiza sozinha.')
            ->action(fn (Sale $record) => static::emitNow($record));
    }

    public static function canEmit(Sale $record): bool
    {
        return static::canManageCompanyRecord($record) && app(FiscalEmissionPreparation::class)->canReserve($record);
    }

    /** Reserves the reference and queues the emission, turning reservation errors into a message. */
    public static function prepareDanfePreview(Sale $record): void
    {
        abort_unless(auth()->user()->canAccessCompany((int) $record->company_id, 'view'), 403);
        try {
            app(\App\Services\FocusDanfePreviewService::class)->buildPayload($record);
        } catch (RuntimeException $exception) {
            Notification::make()->warning()->title('Revise os dados antes de abrir a DANFE')
                ->body($exception->getMessage())->persistent()->send();
            return;
        }
        Notification::make()->success()->title('Dados conferidos')
            ->body('Abra a prévia da DANFE para conferir a nota.')
            ->actions([\Filament\Notifications\Actions\Action::make('abrir')->label('Abrir DANFE')
                ->url(route('sales.danfe-preview', $record), shouldOpenInNewTab: true)])
            ->persistent()->send();
    }

    public static function emitNow(Sale $record): void
    {
        try {
            EmitSaleNfeJob::dispatch($record->id);
        } catch (AuthorizationException) {
            Notification::make()->danger()->title('Você não tem permissão para emitir notas desta empresa.')->send();

            return;
        } catch (RuntimeException $exception) {
            Notification::make()->danger()->title('Não foi possível enviar a NF-e.')->body($exception->getMessage())->send();

            return;
        }

        Notification::make()->success()->title('NF-e enviada para a SEFAZ.')
            ->body('A situação aparece aqui assim que a SEFAZ responder, normalmente em poucos segundos.')->send();
    }

    public static function canConsult(Sale $record): bool
    {
        return static::canManageCompanyRecord($record) && filled($record->focus_nfe_connection_id) && filled($record->focus_nfe_ref)
            && in_array($record->focus_nfe_status, [NfeStatus::SENDING, NfeStatus::PROCESSING], true);
    }

    /** Consults the reference now so the user sees the result instead of a queued message. */
    public static function consultNow(Sale $record): void
    {
        // Same lock as the queued job's WithoutOverlapping, without touching its ShouldBeUnique lock.
        try {
            $documentId = app(FiscalDocumentWorkflow::class)->resolve($record)->id;
        } catch (Throwable) {
            Notification::make()->danger()->title('Esta nota precisa ser reconciliada antes da consulta.')
                ->body('Abra o Histórico Fiscal para conferir a origem da emissão.')->send();

            return;
        }
        $lock = Cache::lock('laravel-queue-overlap:'.ConsultSaleNfeJob::class.':fiscal-consult:'.$documentId, 180);

        if (! $lock->get()) {
            Notification::make()->info()->title('A NF-e já está sendo consultada.')
                ->body('A situação é atualizada sozinha em instantes.')->send();

            return;
        }

        try {
            app(ConsultSaleNfeAction::class)->execute($record);
        } catch (FocusRateLimitedException) {
            Notification::make()->warning()->title('A Focus pediu uma pausa nas consultas.')
                ->body('A consulta automática tenta de novo em instantes. Não é preciso fazer nada.')->send();

            return;
        } catch (Throwable) {
            Notification::make()->danger()->title('Não foi possível consultar a NF-e agora.')
                ->body('A consulta automática tenta de novo em alguns minutos.')->send();

            return;
        } finally {
            $lock->release();
        }

        $record->refresh();

        match (true) {
            $record->focus_nfe_status === NfeStatus::AUTHORIZED => Notification::make()->success()
                ->title('NF-e autorizada.')->body('O DANFE já está disponível.')->send(),
            NfeStatus::hasProblem($record->focus_nfe_status) => Notification::make()->danger()
                ->title('A NF-e não foi autorizada.')->body($record->nfeDiagnosis()['summary'])->send(),
            default => Notification::make()->info()->title('A SEFAZ ainda está processando a nota.')
                ->body('Isso costuma levar poucos minutos. A situação é atualizada sozinha.')->send(),
        };
    }

    protected static function nfeDescription(Sale $record): ?string
    {
        $environment = $record->focus_nfe_environment === 'homologacao' ? ' · Homologação' : '';

        return match (true) {
            $record->focus_nfe_status === NfeStatus::AUTHORIZED => 'Nº '.($record->focus_nfe_number ?: '—').$environment,
            in_array($record->focus_nfe_status, [NfeStatus::SENDING, NfeStatus::PROCESSING], true) => 'Aguardando a SEFAZ'.$environment,
            NfeStatus::hasProblem($record->focus_nfe_status) => Str::limit($record->nfeDiagnosis()['summary'], 34),
            $record->focus_nfe_status === NfeStatus::CANCELED => 'Nº '.($record->focus_nfe_number ?: '—').$environment,
            $record->issue_invoice => 'Pronta para emitir',
            default => null,
        };
    }

    public static function environmentLabel(?string $environment): string
    {
        return match ($environment) {
            'producao' => 'Produção',
            'homologacao' => 'Homologação (teste, sem valor fiscal)',
            default => '—',
        };
    }

    protected static function badge(string $label, string $color): HtmlString
    {
        return new HtmlString(Blade::render('<x-filament::badge :color="$color">{{ $label }}</x-filament::badge>', compact('label', 'color')));
    }

    protected static function userHasManyCompanies(): bool
    {
        return once(fn (): bool => count(static::companyOptions('view')) > 1);
    }

    protected static function money(float $value): string
    {
        return 'R$ '.number_format($value, 2, ',', '.');
    }

    protected static function quantity(float $value): string
    {
        return rtrim(rtrim(number_format($value, 3, ',', '.'), '0'), ',');
    }

    public static function getRelations(): array
    {
        return [
            SaleItemsRelationManager::class,
        ];
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
        return static::canManageCompanyRecord($record) && ! $record->fiscalDocuments()->exists();
    }

    protected static function updateSaleItemTotal(callable $get, callable $set): void
    {
        $quantity = (float) ($get('quantity') ?: 0);
        $unitPrice = (float) ($get('unit_price') ?: 0);
        $discount = (float) ($get('discount_amount') ?: 0);
        $tax = (float) ($get('tax_amount') ?: 0);

        $set('total_amount', max(round(($quantity * $unitPrice) - $discount + $tax, 2), 0));
    }

    protected static function updateSaleTotals(array $items, callable $get, callable $set): void
    {
        $subtotal = static::subtotalFromItems($items);

        $saleDiscount = (float) ($get('discount_amount') ?: 0);
        $saleTax = (float) ($get('tax_amount') ?: 0);

        $set('subtotal_amount', round($subtotal, 2));
        $set('total_amount', max(round($subtotal - $saleDiscount + $saleTax, 2), 0));
    }

    protected static function subtotalFromItems(mixed $items): float
    {
        return round(collect(is_array($items) ? $items : [])->sum(function (array $item): float {
            $quantity = (float) ($item['quantity'] ?? 0);
            $unitPrice = (float) ($item['unit_price'] ?? 0);
            $discount = (float) ($item['discount_amount'] ?? 0);
            $tax = (float) ($item['tax_amount'] ?? 0);

            return max(round(($quantity * $unitPrice) - $discount + $tax, 2), 0);
        }), 2);
    }

    protected static function getAvailableStock(int $productId): ?float
    {
        if ($productId <= 0) {
            return null;
        }

        $product = Product::query()->find($productId);

        return $product ? (float) $product->stock_quantity : null;
    }

    protected static function getStockWarningMessage(int $productId, float $requestedQuantity): ?string
    {
        $availableStock = static::getAvailableStock($productId);

        if ($availableStock === null) {
            return null;
        }

        $message = 'Em estoque: '.static::quantity($availableStock);

        if ($requestedQuantity > $availableStock) {
            $message .= '. A quantidade é maior que o estoque e a venda não será salva.';
        }

        return $message;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSales::route('/'),
            'create' => Pages\CreateSale::route('/create'),
            'edit' => Pages\EditSale::route('/{record}/edit'),
        ];
    }
}
