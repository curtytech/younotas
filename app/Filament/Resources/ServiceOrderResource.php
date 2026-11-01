<?php

namespace App\Filament\Resources;

use App\Filament\Forms\Components\MoneyInput;

use App\Filament\Resources\ServiceOrderResource\Pages;
use App\Jobs\CancelServiceNfseJob;
use App\Jobs\ConsultServiceNfseJob;
use App\Jobs\EmitServiceNfseJob;
use App\Models\Client;
use App\Models\Service;
use App\Models\ServiceOrder;
use App\Models\Technician;
use App\Support\NfseStatus;
use App\Services\FiscalEmissionPreparation;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ServiceOrderResource extends CompanyOwnedResource
{
    protected static ?string $model = ServiceOrder::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $modelLabel = 'Ordem de Serviço';

    protected static ?string $pluralModelLabel = 'Ordens de Serviço';

    protected static ?string $navigationLabel = 'Ordens de Serviço';

    protected static ?string $navigationGroup = 'Operações';

    protected static ?int $navigationSort = 10;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery();
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            static::companyField(),
            Forms\Components\Select::make('client_id')->options(fn (Get $get): array => Client::query()->visibleTo(auth()->user())->where('company_id', $get('company_id'))->orderBy('name')->pluck('name', 'id')->all())->searchable()->preload()->native(false)->required()->label('Cliente'),
            Forms\Components\Select::make('technician_id')->options(fn (Get $get): array => Technician::query()->where('company_id', $get('company_id'))->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all())->searchable()->preload()->label('Técnico'),
            Forms\Components\Select::make('status')->options(['draft' => 'Rascunho', 'scheduled' => 'Agendada', 'in_progress' => 'Em execução', 'completed' => 'Concluída', 'billed' => 'Faturada', 'canceled' => 'Cancelada'])->default('draft')->required()->label('Status'),
            Forms\Components\Toggle::make('iss_retido')->label('ISS retido pelo tomador')->default(false),
            Forms\Components\TextInput::make('codigo_municipio_prestacao')->label('Código IBGE do município da prestação')->regex('/^[0-9]{7}$/')->maxLength(7),
            Forms\Components\DateTimePicker::make('scheduled_for')->label('Agendamento'),
            Forms\Components\Textarea::make('problem_description')->label('Problema/solicitação'),
            Forms\Components\Textarea::make('execution_description')->label('Descrição da execução'),
            Forms\Components\Repeater::make('items')->relationship()->mutateRelationshipDataBeforeCreateUsing(function (array $data): array {
                if (empty($data['service_id'])) {
                    throw \Illuminate\Validation\ValidationException::withMessages(['data.items' => 'Selecione um serviço para cada item novo.']);
                }
                return $data;
            })->minItems(1)->defaultItems(1)->live()->schema([
                Forms\Components\Select::make('service_id')->options(fn (Get $get): array => Service::query()->where('company_id', $get('../../company_id'))->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all())->searchable()->preload()->required(fn (Get $get): bool => blank($get('service_name')))->helperText('Obrigatório em itens novos. Itens antigos mantêm os dados do serviço mesmo que ele tenha sido removido.')->live()->afterStateUpdated(function ($state, callable $set): void {
                    if ($service = Service::find($state)) {
                        $set('service_name', $service->name);
                        $set('service_code', $service->code);
                        $set('description', $service->description);
                        $set('unit', $service->unit);
                        $set('unit_price', $service->unit_price);
                        foreach (['municipal_service_code', 'lc116_code', 'cnae_code', 'nbs_code', 'iss_aliquot', 'pis_aliquot', 'cofins_aliquot', 'inss_aliquot', 'ir_aliquot', 'csll_aliquot'] as $field) {
                            $set($field, $service->{$field});
                        }
                    }
                })->label('Serviço'),
                Forms\Components\Hidden::make('service_name'), Forms\Components\Hidden::make('service_code'), Forms\Components\Hidden::make('description'), Forms\Components\Hidden::make('municipal_service_code'), Forms\Components\Hidden::make('lc116_code'), Forms\Components\Hidden::make('cnae_code'), Forms\Components\Hidden::make('nbs_code'), Forms\Components\Hidden::make('unit'),
                Forms\Components\TextInput::make('quantity')->numeric()->required()->default(1)->minValue(.001)->label('Quantidade')
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn ($state, callable $get, callable $set) => static::updateItemTotal($get, $set)),
                MoneyInput::make('unit_price')->numeric()->required()->minValue(0)->label('Valor unitário')
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn ($state, callable $get, callable $set) => static::updateItemTotal($get, $set)),
                MoneyInput::make('total_amount')->numeric()->required()->default(0)->readOnly()->label('Total'),
                ...collect(['iss', 'pis', 'cofins', 'inss', 'ir', 'csll'])->map(fn (string $tax) => Forms\Components\Hidden::make("{$tax}_aliquot"))->all(),
            ])->columnSpanFull()->label('Serviços executados'),
            Forms\Components\FileUpload::make('signature_path')->image()->disk('local')->directory('service-orders/signatures')->label('Assinatura do cliente')
                ->live()
                ->required(fn (Get $get): bool => filled($get('signed_by_name'))),
            Forms\Components\TextInput::make('signed_by_name')->label('Nome do cliente que assinou')
                ->required(fn (Get $get): bool => filled($get('signature_path')))
                ->helperText('Obrigatório quando a assinatura é enviada.'),
            Forms\Components\Textarea::make('notes')->label('Observações'),
        ]);
    }

    protected static function updateItemTotal(callable $get, callable $set): void
    {
        $quantity = (float) ($get('quantity') ?: 0);
        $unitPrice = (float) ($get('unit_price') ?: 0);
        $discount = (float) ($get('discount_amount') ?: 0);
        $tax = (float) ($get('tax_amount') ?: 0);

        $set('total_amount', max(round(($quantity * $unitPrice) - $discount + $tax, 2), 0));
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            Tables\Columns\TextColumn::make('number')->searchable()->sortable()->label('Número'),
            Tables\Columns\TextColumn::make('client.name')->searchable()->label('Cliente'),
            Tables\Columns\TextColumn::make('technician.name')->label('Técnico'),
            Tables\Columns\TextColumn::make('scheduled_for')->dateTime('d/m/Y H:i')->label('Agendamento'),
            Tables\Columns\TextColumn::make('status')->badge()->formatStateUsing(fn (?string $state): string => ['draft' => 'Rascunho', 'scheduled' => 'Agendada', 'in_progress' => 'Em execução', 'completed' => 'Concluída', 'billed' => 'Faturada', 'canceled' => 'Cancelada'][$state] ?? '—')->label('Status'),
            Tables\Columns\TextColumn::make('company.name')->label('Empresa'),
            Tables\Columns\TextColumn::make('focus_nfse_environment')->label('Ambiente da nota')->badge()->formatStateUsing(fn (?string $state): string => $state === 'producao' ? 'Produção' : 'Homologação'),
            Tables\Columns\TextColumn::make('focus_nfse_status')->badge()->label('NFS-e'),
            Tables\Columns\TextColumn::make('total_amount')->money('BRL')->label('Total'),
        ])->actions([
            Tables\Actions\EditAction::make(),
            Tables\Actions\Action::make('emitir_nfse')->label(fn (ServiceOrder $r): string => NfseStatus::requiresNewReference($r->focus_nfse_status) ? 'Emitir nova NFS-e' : 'Emitir NFS-e')->color('success')->visible(fn (ServiceOrder $r): bool => static::canManageCompanyRecord($r) && $r->status === 'completed' && app(FiscalEmissionPreparation::class)->canReserve($r))->requiresConfirmation()->action(fn (ServiceOrder $r) => EmitServiceNfseJob::dispatch($r->id)),
            Tables\Actions\Action::make('consultar_nfse')->label('Consultar NFS-e')->visible(fn (ServiceOrder $r): bool => static::canManageCompanyRecord($r) && filled($r->focus_nfse_connection_id) && in_array($r->focus_nfse_status, [NfseStatus::PROCESSING, NfseStatus::SENDING], true))->action(fn (ServiceOrder $r) => ConsultServiceNfseJob::dispatch($r->id)),
            Tables\Actions\Action::make('cancelar_nfse')->label('Cancelar NFS-e')->color('danger')->visible(fn (ServiceOrder $r): bool => static::canManageCompanyRecord($r) && filled($r->focus_nfse_connection_id) && NfseStatus::canCancel($r->focus_nfse_status))->requiresConfirmation()->form([Forms\Components\Textarea::make('justification')->label('Justificativa')->required()->minLength(15)->maxLength(255)])->action(fn (ServiceOrder $r, array $data) => CancelServiceNfseJob::dispatch($r->id, $data['justification'])),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListServiceOrders::route('/'), 'create' => Pages\CreateServiceOrder::route('/create'), 'edit' => Pages\EditServiceOrder::route('/{record}/edit')];
    }
}
