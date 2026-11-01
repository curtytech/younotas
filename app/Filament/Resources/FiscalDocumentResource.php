<?php

namespace App\Filament\Resources;

use App\Filament\Resources\FiscalDocumentResource\Pages;
use App\Jobs\CancelSaleNfeJob;
use App\Jobs\CancelServiceNfseJob;
use App\Jobs\ConsultSaleNfeJob;
use App\Jobs\ConsultServiceNfseJob;
use App\Jobs\EmitSaleNfeJob;
use App\Jobs\EmitServiceNfseJob;
use App\Models\FiscalDocument;
use App\Services\FiscalIssueDiagnosis;
use App\Support\NfeStatus;
use App\Support\NfseStatus;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Form;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class FiscalDocumentResource extends CompanyOwnedResource
{
    protected static ?string $model = FiscalDocument::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-check';

    protected static ?string $modelLabel = 'Documento fiscal';

    protected static ?string $pluralModelLabel = 'Histórico Fiscal';

    protected static ?string $navigationLabel = 'Histórico Fiscal';

    protected static ?string $navigationGroup = 'Cadastros Fiscais';

    protected static ?int $navigationSort = 35;

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        return $query;
    }

    public static function attentionCount(): int
    {
        return static::getEloquentQuery()->needingAttention()->count();
    }

    public static function getNavigationBadge(): ?string
    {
        $count = auth()->check() ? static::attentionCount() : 0;

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->poll('10s')
            ->defaultSort('issued_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('company.name')->label('Empresa')->searchable(),
                Tables\Columns\TextColumn::make('environment')->label('Ambiente')->badge()->placeholder('Origem a reconciliar')
                    ->formatStateUsing(fn (?string $state): string => $state === 'producao' ? 'Produção' : ($state === 'homologacao' ? 'Homologação' : 'Origem a reconciliar'))
                    ->color(fn (?string $state): string => $state === 'producao' ? 'danger' : 'info'),
                Tables\Columns\TextColumn::make('document_type')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'NF-e' ? 'primary' : 'info')
                    ->label('Tipo'),
                Tables\Columns\TextColumn::make('source_label')
                    ->searchable()
                    ->placeholder('—')
                    ->label('Origem'),
                Tables\Columns\TextColumn::make('issued_at')
                    ->date('d/m/Y')
                    ->sortable()
                    ->label('Data'),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'autorizado' => 'success',
                        'processando', 'enviando' => 'warning',
                        'cancelado' => 'gray',
                        'denegado' => 'danger',
                        'erro_autorizacao', 'erro_emissao', 'erro_cancelamento' => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'autorizado' => 'Autorizada',
                        'processando', 'enviando' => 'Processando',
                        'cancelado' => 'Cancelada',
                        'denegado' => 'Denegada',
                        'erro_autorizacao' => 'Rejeitada',
                        'erro_cancelamento' => 'Erro no cancelamento',
                        'erro_emissao' => 'Erro de envio',
                        default => 'Não emitida',
                    })
                    ->label('Situação'),
                Tables\Columns\TextColumn::make('document_number')
                    ->placeholder('—')
                    ->label('Número'),
                Tables\Columns\TextColumn::make('series')
                    ->placeholder('—')
                    ->label('Série'),
                Tables\Columns\TextColumn::make('metadata.numero_rps')
                    ->placeholder('—')
                    ->toggleable()
                    ->label('RPS'),
                Tables\Columns\TextColumn::make('access_key')
                    ->copyable()
                    ->copyMessage('Chave copiada')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->label('Chave de acesso'),
                Tables\Columns\TextColumn::make('total_amount')
                    ->money('BRL')
                    ->placeholder('—')
                    ->label('Total'),
                Tables\Columns\TextColumn::make('focus_reference')
                    ->copyable()
                    ->copyMessage('Referência copiada')
                    ->placeholder('—')
                    ->label('Referência Focus'),
                Tables\Columns\TextColumn::make('metadata.origin')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'emitted' => 'Emitida',
                        'focus_backup' => 'Backup Focus',
                        'focus_individual' => 'Importada',
                        'xml_upload' => 'XML',
                        'manual' => 'Manual',
                        'seed' => 'Demonstração',
                        default => '—',
                    })
                    ->color('gray')
                    ->placeholder('—')
                    ->label('Origem'),
                Tables\Columns\TextColumn::make('last_checked_at')
                    ->dateTime('d/m/Y H:i:s')
                    ->placeholder('—')
                    ->sortable()
                    ->label('Última consulta'),
                Tables\Columns\TextColumn::make('last_webhook_at')
                    ->dateTime('d/m/Y H:i:s')
                    ->placeholder('—')
                    ->sortable()
                    ->label('Último webhook'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('environment')->label('Ambiente')->options(['homologacao' => 'Homologação', 'producao' => 'Produção']),
                Tables\Filters\SelectFilter::make('document_type')
                    ->options(['NF-e' => 'NF-e', 'NFS-e' => 'NFS-e'])
                    ->label('Tipo'),
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'processando' => 'Processando',
                        'autorizado' => 'Autorizada',
                        'cancelado' => 'Cancelada',
                        'erro_autorizacao' => 'Rejeitada',
                        'erro_cancelamento' => 'Erro no cancelamento',
                        'erro_emissao' => 'Erro de envio',
                    ])
                    ->label('Situação'),
            ])
            ->actions([
                Tables\Actions\Action::make('consultar')
                    ->label('Consultar')
                    ->icon('heroicon-o-arrow-path')
                    ->color('info')
                    ->visible(fn (FiscalDocument $record): bool => filled($record->fiscal_connection_id) && static::canManageCompanyRecord($record))
                    ->action(function (FiscalDocument $record): void {
                        self::dispatchForSource($record, 'consult');
                    })
                    ->successNotificationTitle('Consulta agendada'),
                Tables\Actions\Action::make('reenviar')
                    ->label('Reenviar')
                    ->icon('heroicon-o-arrow-uturn-right')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('Reenviar documento fiscal?')
                    ->modalDescription('A nota manterá a conexão, o ambiente e a referência originais. Falhas de resultado incerto serão consultadas antes de outro envio.')
                    ->visible(fn (FiscalDocument $record): bool => in_array($record->status, ['erro_autorizacao', 'erro_emissao'], true) && $record->isLinked() && filled($record->fiscal_connection_id) && static::canManageCompanyRecord($record))
                    ->action(function (FiscalDocument $record): void {
                        self::dispatchForSource($record, 'emit');
                    })
                    ->successNotificationTitle('Reenvio agendado'),
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\Action::make('timeline')->label('Histórico de eventos')->icon('heroicon-o-clock')
                        ->modalHeading('Histórico do documento')->modalSubmitAction(false)->modalCancelActionLabel('Fechar')
                        ->modalContent(fn (FiscalDocument $record) => view('filament.fiscal.timeline', ['document' => $record])),
                    Tables\Actions\Action::make('diagnostico')->label('Diagnóstico')->icon('heroicon-o-light-bulb')
                        ->visible(fn (FiscalDocument $record): bool => $record->status !== 'autorizado' && $record->status !== 'cancelado')
                        ->modalHeading('Diagnóstico do documento')->modalSubmitAction(false)->modalCancelActionLabel('Fechar')
                        ->modalContent(fn (FiscalDocument $record) => view('filament.fiscal.diagnosis', ['diagnosis' => app(FiscalIssueDiagnosis::class)->explain($record)])),
                    Tables\Actions\Action::make('download_xml')->label('Baixar XML')->icon('heroicon-o-arrow-down-tray')
                        ->visible(fn (FiscalDocument $record): bool => filled($record->xml_path))
                        ->url(fn (FiscalDocument $record): string => route('fiscal-documents.files', ['fiscalDocument' => $record, 'artifact' => 'xml'])),
                    Tables\Actions\Action::make('download_cancellation')->label('XML de cancelamento')
                        ->visible(fn (FiscalDocument $record): bool => filled($record->cancelation_xml_path))
                        ->url(fn (FiscalDocument $record): string => route('fiscal-documents.files', ['fiscalDocument' => $record, 'artifact' => 'cancelamento'])),
                    Tables\Actions\Action::make('abrir_documento')
                        ->label('Abrir Documento')
                        ->icon('heroicon-o-arrow-top-right-on-square')
                        ->url(fn (FiscalDocument $record): ?string => $record->document_url)
                        ->openUrlInNewTab()
                        ->visible(fn (FiscalDocument $record): bool => filled($record->document_url)),
                    Tables\Actions\Action::make('cancelar_nota')
                        ->label('Cancelar Nota')
                        ->icon('heroicon-o-x-circle')
                        ->color('danger')
                        ->visible(fn (FiscalDocument $record): bool => filled($record->fiscal_connection_id) && static::canManageCompanyRecord($record) && ($record->document_type === 'NF-e'
                            ? NfeStatus::canCancel($record->status)
                            : NfseStatus::canCancel($record->status)))
                        ->requiresConfirmation()
                        ->form([
                            Textarea::make('justification')
                                ->label('Justificativa do Cancelamento')
                                ->required()
                                ->minLength(15)
                                ->maxLength(255)
                                ->placeholder('Informe a justificativa do cancelamento.'),
                        ])
                        ->action(function (FiscalDocument $record, array $data): void {
                            self::dispatchForSource($record, 'cancel', $data['justification']);
                        })
                        ->successNotificationTitle('Cancelamento da nota enfileirado.'),
                ])
                    ->icon('heroicon-o-ellipsis-vertical')
                    ->label('Ações'),
            ])
            ->defaultPaginationPageOption(25);
    }

    private static function dispatchForSource(FiscalDocument $record, string $operation, ?string $justification = null): void
    {
        abort_unless(static::canManageCompanyRecord($record), 403);
        abort_unless($record->fiscal_connection_id && $record->environment, 422, 'Reconcilie a origem deste documento antes de operar.');
        if ($record->document_type === 'NF-e') {
            match ($operation) {
                'consult' => ConsultSaleNfeJob::dispatch((int) $record->source_id, $record->id),
                'emit' => EmitSaleNfeJob::dispatch((int) $record->source_id, $record->id),
                'cancel' => CancelSaleNfeJob::dispatch((int) $record->source_id, $justification, $record->id),
            };

            return;
        }
        match ($operation) {
            'consult' => ConsultServiceNfseJob::dispatch((int) $record->source_id, $record->id),
            'emit' => EmitServiceNfseJob::dispatch((int) $record->source_id, $record->id),
            'cancel' => CancelServiceNfseJob::dispatch((int) $record->source_id, $justification, $record->id),
        };
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListFiscalDocuments::route('/')];
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }
}
