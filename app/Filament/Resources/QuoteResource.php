<?php

namespace App\Filament\Resources;

use App\Actions\ConvertQuoteToSaleAction;
use App\Filament\Forms\Components\MoneyInput;
use App\Filament\Resources\QuoteResource\Pages;
use App\Models\Client;
use App\Models\Product;
use App\Models\Quote;
use App\Models\Service;
use Closure;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Enums\FontWeight;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class QuoteResource extends CompanyOwnedResource
{
    protected static ?string $model = Quote::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $modelLabel = 'Orçamento';

    protected static ?string $pluralModelLabel = 'Orçamentos';

    protected static ?string $navigationLabel = 'Orçamentos';

    protected static ?string $navigationGroup = 'Comercial';

    protected static ?int $navigationSort = 5;

    public const STATUS_OPTIONS = [
        'draft' => 'Rascunho', 'sent' => 'Enviado', 'approved' => 'Aprovado',
        'rejected' => 'Recusado', 'expired' => 'Expirado',
    ];

    public const STATUS_COLORS = [
        'draft' => 'gray', 'sent' => 'info', 'approved' => 'success',
        'rejected' => 'danger', 'expired' => 'warning',
    ];

    public static function form(Form $form): Form
    {
        return $form->columns(3)->schema([
            Forms\Components\Section::make('Dados do orçamento')
                ->icon('heroicon-o-document-text')
                ->columns(3)
                ->columnSpan(2)
                ->schema([
                    static::companyField()
                        ->afterStateUpdated(function (Set $set): void {
                            $set('client_id', null);
                            $set('items', []);
                        })
                        ->columnSpanFull(),
                    Forms\Components\Select::make('client_id')
                        ->options(fn (Get $get): array => Client::query()
                            ->where('company_id', $get('company_id'))
                            ->orderBy('name')->pluck('name', 'id')->all())
                        ->searchable()->preload()->native(false)->required()
                        ->label('Cliente'),
                    Forms\Components\DatePicker::make('quote_date')
                        ->required()->native(false)->displayFormat('d/m/Y')->default(now())->live()
                        ->label('Data'),
                    Forms\Components\DatePicker::make('valid_until')
                        ->native(false)->displayFormat('d/m/Y')->afterOrEqual('quote_date')
                        ->hint('Opcional')
                        ->helperText('Até quando os preços valem.')
                        ->label('Validade'),
                    Forms\Components\Textarea::make('notes')
                        ->rows(2)->autosize()->maxLength(65535)->columnSpanFull()
                        ->placeholder('Condições de pagamento, prazo de entrega, garantia…')
                        ->helperText('Aparece no PDF enviado ao cliente.')
                        ->label('Observações'),
                ]),
            Forms\Components\Section::make('Resumo')
                ->icon('heroicon-o-calculator')
                ->columnSpan(1)
                ->schema([
                    Forms\Components\Placeholder::make('subtotal')
                        ->inlineLabel()
                        ->content(fn (Get $get): string => static::money(static::subtotal($get('items') ?? [])))
                        ->label('Subtotal'),
                    MoneyInput::make('discount_amount')
                        ->default(0)->live(onBlur: true)->inlineLabel()
                        ->rule(fn (Get $get): Closure => function (string $attribute, $value, Closure $fail) use ($get): void {
                            if ((float) $value > static::subtotal($get('items') ?? []) + 0.001) {
                                $fail('O desconto não pode ser maior que o subtotal.');
                            }
                        })
                        ->label('Desconto'),
                    Forms\Components\Placeholder::make('total')
                        ->inlineLabel()
                        ->content(fn (Get $get): string => static::money(max(static::subtotal($get('items') ?? []) - (float) $get('discount_amount'), 0)))
                        ->extraAttributes(['class' => 'text-xl font-bold text-primary-600 dark:text-primary-400'])
                        ->label('Total'),
                ]),
            Forms\Components\Section::make('Situação')
                ->icon('heroicon-o-flag')
                ->columns(['default' => 1, 'md' => 2])
                ->schema([
                    Forms\Components\ToggleButtons::make('status')
                        ->options(static::STATUS_OPTIONS)
                        ->colors(static::STATUS_COLORS)
                        ->default('draft')->required()->inline()
                        ->hiddenLabel(),
                    Forms\Components\Placeholder::make('venda_gerada')
                        ->label('Venda gerada')
                        ->visible(fn (?Quote $record): bool => filled($record?->sale_id))
                        ->content(fn (Quote $record): string => '#'.$record->sale_id),
                ]),
            Forms\Components\Section::make('Itens')
                ->icon('heroicon-o-list-bullet')
                ->schema([
                    Forms\Components\Repeater::make('items')
                        ->relationship()
                        ->hiddenLabel()
                        ->defaultItems(1)
                        ->minItems(1)
                        ->reorderable(false)
                        ->collapsible()
                        ->cloneable()
                        ->live()
                        ->itemLabel(fn (array $state): string => filled($state['name'] ?? null)
                            ? $state['name'].' — '.static::money(static::itemTotal($state))
                            : 'Novo item')
                        ->schema([
                            Forms\Components\ToggleButtons::make('item_type')
                                ->options(['product' => 'Produto', 'service' => 'Serviço'])
                                ->icons(['product' => 'heroicon-m-cube', 'service' => 'heroicon-m-wrench-screwdriver'])
                                ->default('product')->required()->inline()->grouped()->live()
                                ->afterStateUpdated(function (Set $set): void {
                                    $set('product_id', null);
                                    $set('service_id', null);
                                    $set('name', null);
                                    $set('unit', 'UN');
                                    $set('unit_price', 0);
                                })
                                ->columnSpanFull()
                                ->hiddenLabel(),
                            Forms\Components\Select::make('product_id')
                                ->options(fn (Get $get): array => Product::query()
                                    ->where('company_id', $get('../../company_id'))->where('is_active', true)
                                    ->orderBy('name')->pluck('name', 'id')->all())
                                ->searchable()->preload()->native(false)
                                ->visible(fn (Get $get): bool => $get('item_type') === 'product')
                                ->required(fn (Get $get): bool => $get('item_type') === 'product')
                                ->live()->afterStateUpdated(fn ($state, Set $set) => static::fillProduct($state, $set))
                                ->columnSpan(['default' => 12, 'md' => 4])
                                ->label('Produto'),
                            Forms\Components\Select::make('service_id')
                                ->options(fn (Get $get): array => Service::query()
                                    ->where('company_id', $get('../../company_id'))->where('is_active', true)
                                    ->orderBy('name')->pluck('name', 'id')->all())
                                ->searchable()->preload()->native(false)
                                ->visible(fn (Get $get): bool => $get('item_type') === 'service')
                                ->required(fn (Get $get): bool => $get('item_type') === 'service')
                                ->live()->afterStateUpdated(fn ($state, Set $set) => static::fillService($state, $set))
                                ->columnSpan(['default' => 12, 'md' => 4])
                                ->label('Serviço'),
                            Forms\Components\Hidden::make('name'),
                            Forms\Components\Hidden::make('unit')->default('UN'),
                            Forms\Components\TextInput::make('quantity')
                                ->numeric()->required()->minValue(.001)->default(1)
                                ->suffix(fn (Get $get): string => $get('unit') ?: 'UN')
                                ->live(onBlur: true)
                                ->columnSpan(['default' => 6, 'md' => 3])
                                ->label('Quantidade'),
                            MoneyInput::make('unit_price')
                                ->required()->default(0)->live(onBlur: true)
                                ->columnSpan(['default' => 6, 'md' => 3])
                                ->label('Preço unitário'),
                            Forms\Components\Placeholder::make('item_total')
                                ->content(fn (Get $get): string => static::money(static::itemTotal(['quantity' => $get('quantity'), 'unit_price' => $get('unit_price')])))
                                ->extraAttributes(['class' => 'text-base font-semibold'])
                                ->columnSpan(['default' => 12, 'md' => 2])
                                ->label('Total do item'),
                        ])->columns(12)->addActionLabel('Adicionar item'),
                ]),
        ]);
    }

    private static function fillProduct(mixed $id, Set $set): void
    {
        if ($product = Product::find($id)) {
            $set('name', $product->name);
            $set('unit', $product->unit);
            $set('unit_price', $product->sale_price);
        }
    }

    private static function fillService(mixed $id, Set $set): void
    {
        if ($service = Service::find($id)) {
            $set('name', $service->name);
            $set('unit', $service->unit);
            $set('unit_price', $service->unit_price);
        }
    }

    public static function itemTotal(array $item): float
    {
        return max(round((float) ($item['quantity'] ?? 0) * (float) ($item['unit_price'] ?? 0), 2), 0);
    }

    public static function subtotal(array $items): float
    {
        return round(collect($items)->sum(fn (array $item): float => static::itemTotal($item)), 2);
    }

    public static function money(float $value): string
    {
        return 'R$ '.number_format($value, 2, ',', '.');
    }

    public static function convertToSale(Quote $record): void
    {
        try {
            $sale = app(ConvertQuoteToSaleAction::class)->execute($record);
        } catch (ValidationException $exception) {
            Notification::make()->danger()->title('Não foi possível gerar a venda.')
                ->body(collect($exception->errors())->flatten()->first())->send();

            return;
        }

        $record->refresh();
        $skipped = $record->items()->where('item_type', 'service')->exists();
        Notification::make()->success()->title('Venda #'.$sale->id.' criada.')
            ->body($skipped ? 'Os serviços do orçamento não entram na venda. Crie uma ordem de serviço para eles.' : 'Confira os dados e emita a NF-e quando quiser.')
            ->actions([Action::make('abrir')->label('Abrir venda')->url(SaleResource::getUrl('edit', ['record' => $sale]))])
            ->send();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('client'))
            ->defaultSort('id', 'desc')
            ->emptyStateIcon('heroicon-o-document-text')
            ->emptyStateHeading('Nenhum orçamento ainda')
            ->emptyStateDescription('Monte um orçamento, envie o PDF ao cliente e gere a venda quando ele aprovar.')
            ->columns([
                Tables\Columns\TextColumn::make('number')->label('Número')->searchable()->sortable()
                    ->weight(FontWeight::Bold)
                    ->description(fn (Quote $record): string => $record->quote_date?->format('d/m/Y') ?? ''),
                Tables\Columns\TextColumn::make('client.name')->label('Cliente')->searchable()->limit(40)
                    ->tooltip(fn (Quote $record): ?string => mb_strlen((string) $record->client?->name) > 40 ? $record->client?->name : null),
                Tables\Columns\TextColumn::make('valid_until')->date('d/m/Y')->label('Validade')->sortable()
                    ->placeholder('Sem validade')
                    ->color(fn (Quote $record): ?string => $record->isExpired() ? 'danger' : null)
                    ->icon(fn (Quote $record): ?string => $record->isExpired() ? 'heroicon-m-exclamation-circle' : null)
                    ->tooltip(fn (Quote $record): ?string => $record->isExpired() ? 'Validade vencida' : null),
                Tables\Columns\TextColumn::make('status')->badge()->label('Situação')
                    ->formatStateUsing(fn (?string $state): string => static::STATUS_OPTIONS[$state] ?? '—')
                    ->color(fn (?string $state): string => static::STATUS_COLORS[$state] ?? 'gray')
                    ->description(fn (Quote $record): ?string => $record->sale_id ? 'Venda #'.$record->sale_id : null),
                Tables\Columns\TextColumn::make('total_amount')->money('BRL')->label('Total')->sortable()
                    ->weight(FontWeight::SemiBold)->alignEnd(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options(static::STATUS_OPTIONS)->label('Situação'),
            ])
            ->actions([
                Tables\Actions\Action::make('pdf')
                    ->label('PDF')->icon('heroicon-o-arrow-down-tray')->color('gray')
                    ->url(fn (Quote $record): string => route('quotes.pdf', $record))
                    ->openUrlInNewTab(),
                Tables\Actions\Action::make('gerar_venda')
                    ->label('Gerar venda')->icon('heroicon-o-shopping-cart')->color('success')
                    ->visible(fn (Quote $record): bool => blank($record->sale_id) && ! in_array($record->status, ['rejected', 'expired'], true) && static::canManageCompanyRecord($record))
                    ->requiresConfirmation()
                    ->modalDescription('Cria uma venda pendente com os produtos do orçamento e baixa o estoque. O orçamento fica como aprovado.')
                    ->action(fn (Quote $record) => static::convertToSale($record)),
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\EditAction::make(),
                    Tables\Actions\Action::make('abrir_venda')
                        ->label('Abrir venda')->icon('heroicon-o-arrow-top-right-on-square')
                        ->visible(fn (Quote $record): bool => filled($record->sale_id))
                        ->url(fn (Quote $record): string => SaleResource::getUrl('edit', ['record' => $record->sale_id])),
                    Tables\Actions\DeleteAction::make(),
                ])->tooltip('Mais ações'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListQuotes::route('/'),
            'create' => Pages\CreateQuote::route('/create'),
            'edit' => Pages\EditQuote::route('/{record}/edit'),
        ];
    }
}
