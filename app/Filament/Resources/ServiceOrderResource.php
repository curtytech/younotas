<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ServiceOrderResource\Pages;
use App\Jobs\CancelServiceNfseJob;
use App\Jobs\ConsultServiceNfseJob;
use App\Jobs\EmitServiceNfseJob;
use App\Models\Service;
use App\Models\ServiceOrder;
use App\Models\Technician;
use App\Support\NfseStatus;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ServiceOrderResource extends Resource
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
        return parent::getEloquentQuery()->when(auth()->user()->role !== 'admin', fn (Builder $q) => $q->where('user_id', auth()->id()));
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            auth()->user()->role === 'admin' ? Forms\Components\Select::make('user_id')->relationship('user', 'name')->required()->default(auth()->id())->label('Emissor') : Forms\Components\Hidden::make('user_id')->default(auth()->id()),
            Forms\Components\Select::make('client_id')->relationship('client', 'name', fn (Builder $q) => $q->when(auth()->user()->role !== 'admin', fn (Builder $q) => $q->where('user_id', auth()->id())))->searchable()->preload()->required()->label('Cliente'),
            Forms\Components\Select::make('technician_id')->options(fn (Get $get): array => Technician::query()->where('user_id', $get('user_id') ?: auth()->id())->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all())->searchable()->preload()->label('Técnico'),
            Forms\Components\Select::make('status')->options(['draft' => 'Rascunho', 'scheduled' => 'Agendada', 'in_progress' => 'Em execução', 'completed' => 'Concluída', 'billed' => 'Faturada', 'canceled' => 'Cancelada'])->default('draft')->required()->label('Status'),
            Forms\Components\DateTimePicker::make('scheduled_for')->label('Agendamento'),
            Forms\Components\Textarea::make('problem_description')->label('Problema/solicitação'),
            Forms\Components\Textarea::make('execution_description')->label('Descrição da execução'),
            Forms\Components\Repeater::make('items')->relationship()->minItems(1)->defaultItems(1)->live()->schema([
                Forms\Components\Select::make('service_id')->options(fn (Get $get): array => Service::query()->where('user_id', $get('../../user_id') ?: auth()->id())->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all())->searchable()->preload()->required()->live()->afterStateUpdated(function ($state, callable $set): void { if ($service = Service::find($state)) { $set('service_name', $service->name); $set('service_code', $service->code); $set('description', $service->description); $set('unit', $service->unit); $set('unit_price', $service->unit_price); foreach (['municipal_service_code', 'lc116_code', 'cnae_code', 'nbs_code', 'iss_aliquot', 'pis_aliquot', 'cofins_aliquot', 'inss_aliquot', 'ir_aliquot', 'csll_aliquot'] as $field) $set($field, $service->{$field}); } })->label('Serviço'),
                Forms\Components\Hidden::make('service_name'), Forms\Components\Hidden::make('service_code'), Forms\Components\Hidden::make('description'), Forms\Components\Hidden::make('municipal_service_code'), Forms\Components\Hidden::make('lc116_code'), Forms\Components\Hidden::make('cnae_code'), Forms\Components\Hidden::make('nbs_code'), Forms\Components\Hidden::make('unit'),
                Forms\Components\TextInput::make('quantity')->numeric()->required()->default(1)->minValue(.001)->label('Quantidade'),
                Forms\Components\TextInput::make('unit_price')->numeric()->required()->minValue(0)->label('Valor unitário'),
                Forms\Components\TextInput::make('total_amount')->numeric()->required()->default(0)->label('Total'),
                ...collect(['iss', 'pis', 'cofins', 'inss', 'ir', 'csll'])->map(fn (string $tax) => Forms\Components\Hidden::make("{$tax}_aliquot"))->all(),
            ])->columnSpanFull()->label('Serviços executados'),
            Forms\Components\FileUpload::make('signature_path')->image()->disk('local')->directory('service-orders/signatures')->label('Assinatura do cliente'),
            Forms\Components\TextInput::make('signed_by_name')->label('Nome do cliente que assinou'),
            Forms\Components\Textarea::make('notes')->label('Observações'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            Tables\Columns\TextColumn::make('number')->searchable()->sortable()->label('Número'),
            Tables\Columns\TextColumn::make('client.name')->searchable()->label('Cliente'),
            Tables\Columns\TextColumn::make('technician.name')->label('Técnico'),
            Tables\Columns\TextColumn::make('scheduled_for')->dateTime('d/m/Y H:i')->label('Agendamento'),
            Tables\Columns\TextColumn::make('status')->badge()->formatStateUsing(fn (?string $state): string => ['draft' => 'Rascunho', 'scheduled' => 'Agendada', 'in_progress' => 'Em execução', 'completed' => 'Concluída', 'billed' => 'Faturada', 'canceled' => 'Cancelada'][$state] ?? '—')->label('Status'),
            Tables\Columns\TextColumn::make('focus_nfse_status')->badge()->label('NFS-e'),
            Tables\Columns\TextColumn::make('total_amount')->money('BRL')->label('Total'),
        ])->actions([
            Tables\Actions\EditAction::make(),
            Tables\Actions\Action::make('emitir_nfse')->label('Emitir NFS-e')->color('success')->visible(fn (ServiceOrder $r): bool => $r->status === 'completed' && NfseStatus::canEmit($r->focus_nfse_status))->requiresConfirmation()->action(fn (ServiceOrder $r) => EmitServiceNfseJob::dispatch($r->id)),
            Tables\Actions\Action::make('consultar_nfse')->label('Consultar NFS-e')->visible(fn (ServiceOrder $r): bool => in_array($r->focus_nfse_status, [NfseStatus::PROCESSING, NfseStatus::SENDING], true))->action(fn (ServiceOrder $r) => ConsultServiceNfseJob::dispatch($r->id)),
            Tables\Actions\Action::make('cancelar_nfse')->label('Cancelar NFS-e')->color('danger')->visible(fn (ServiceOrder $r): bool => NfseStatus::canCancel($r->focus_nfse_status))->requiresConfirmation()->action(fn (ServiceOrder $r) => CancelServiceNfseJob::dispatch($r->id, 'Cancelamento solicitado pelo emissor da ordem de serviço.')),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListServiceOrders::route('/'), 'create' => Pages\CreateServiceOrder::route('/create'), 'edit' => Pages\EditServiceOrder::route('/{record}/edit')];
    }
}
