<?php

namespace App\Filament\Resources;

use App\Filament\Resources\QuoteResource\Pages;
use App\Models\Client;
use App\Models\Product;
use App\Models\Quote;
use App\Models\Service;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class QuoteResource extends CompanyOwnedResource
{
    protected static ?string $model = Quote::class;
    protected static ?string $navigationIcon = 'heroicon-o-document-text';
    protected static ?string $modelLabel = 'Orçamento';
    protected static ?string $pluralModelLabel = 'Orçamentos';
    protected static ?string $navigationLabel = 'Orçamentos';
    protected static ?string $navigationGroup = 'Comercial';
    protected static ?int $navigationSort = 5;

    public static function form(Form $form): Form
    {
        return $form->schema([
            static::companyField(),
            Forms\Components\Select::make('client_id')
                ->options(fn (Get $get): array => Client::query()
                    ->where('company_id', $get('company_id'))
                    ->orderBy('name')->pluck('name', 'id')->all())
                ->searchable()->preload()->native(false)->required()->label('Cliente'),
            Forms\Components\DatePicker::make('quote_date')->required()->default(now())->label('Data'),
            Forms\Components\DatePicker::make('valid_until')->afterOrEqual('quote_date')->label('Validade'),
            Forms\Components\Select::make('status')->options([
                'draft' => 'Rascunho', 'sent' => 'Enviado', 'approved' => 'Aprovado',
                'rejected' => 'Recusado', 'expired' => 'Expirado',
            ])->default('draft')->required()->native(false)->label('Status'),
            Forms\Components\Repeater::make('items')
                ->relationship()
                ->label('Produtos e serviços')
                ->defaultItems(1)
                ->minItems(1)
                ->reorderable(false)
                ->live()
                ->schema([
                    Forms\Components\Select::make('item_type')
                        ->options(['product' => 'Produto', 'service' => 'Serviço'])
                        ->default('product')->required()->native(false)->live()
                        ->afterStateUpdated(function (?string $state, Set $set): void {
                            $set('product_id', null);
                            $set('service_id', null);
                            $set('name', null);
                            $set('unit', 'UN');
                            $set('unit_price', 0);
                        })->label('Tipo'),
                    Forms\Components\Select::make('product_id')
                        ->options(fn (Get $get): array => Product::query()
                            ->where('company_id', $get('../../company_id'))->where('is_active', true)
                            ->orderBy('name')->pluck('name', 'id')->all())
                        ->searchable()->preload()->native(false)
                        ->visible(fn (Get $get): bool => $get('item_type') === 'product')
                        ->required(fn (Get $get): bool => $get('item_type') === 'product')
                        ->live()->afterStateUpdated(fn ($state, Set $set) => static::fillProduct($state, $set))
                        ->label('Produto'),
                    Forms\Components\Select::make('service_id')
                        ->options(fn (Get $get): array => Service::query()
                            ->where('company_id', $get('../../company_id'))->where('is_active', true)
                            ->orderBy('name')->pluck('name', 'id')->all())
                        ->searchable()->preload()->native(false)
                        ->visible(fn (Get $get): bool => $get('item_type') === 'service')
                        ->required(fn (Get $get): bool => $get('item_type') === 'service')
                        ->live()->afterStateUpdated(fn ($state, Set $set) => static::fillService($state, $set))
                        ->label('Serviço'),
                    Forms\Components\Hidden::make('name'),
                    Forms\Components\TextInput::make('unit')->required()->readOnly()->default('UN')->label('Unidade'),
                    Forms\Components\TextInput::make('quantity')->numeric()->required()->minValue(.001)->default(1)->label('Quantidade'),
                    Forms\Components\TextInput::make('unit_price')->numeric()->required()->minValue(0)->label('Preço unitário'),
                ])->columns(6)->columnSpanFull()->addActionLabel('Adicionar item'),
            Forms\Components\TextInput::make('discount_amount')->numeric()->default(0)->minValue(0)->label('Desconto'),
            Forms\Components\Textarea::make('notes')->rows(3)->columnSpanFull()->label('Observações'),
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

    public static function table(Table $table): Table
    {
        return $table->columns([
            Tables\Columns\TextColumn::make('number')->label('Número')->searchable()->sortable(),
            Tables\Columns\TextColumn::make('client.name')->label('Cliente')->searchable(),
            Tables\Columns\TextColumn::make('quote_date')->date('d/m/Y')->label('Data'),
            Tables\Columns\TextColumn::make('valid_until')->date('d/m/Y')->label('Validade'),
            Tables\Columns\TextColumn::make('status')->badge()->formatStateUsing(fn (?string $state): string => [
                'draft' => 'Rascunho', 'sent' => 'Enviado', 'approved' => 'Aprovado',
                'rejected' => 'Recusado', 'expired' => 'Expirado',
            ][$state] ?? '—'),
            Tables\Columns\TextColumn::make('total_amount')->money('BRL')->label('Total'),
        ])->actions([
            Tables\Actions\EditAction::make(),
            Tables\Actions\Action::make('pdf')
                ->label('Exportar PDF')->icon('heroicon-o-arrow-down-tray')
                ->url(fn (Quote $record): string => route('quotes.pdf', $record))
                ->openUrlInNewTab(),
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
