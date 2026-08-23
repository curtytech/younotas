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
use App\Models\Sale;
use App\Models\ServiceOrder;
use App\Support\NfeStatus;
use App\Support\NfseStatus;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class FiscalDocumentResource extends Resource
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

        if (auth()->user()->role !== 'admin') {
            $query->where('user_id', auth()->id());
        }

        return $query;
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
                        'erro_autorizacao', 'erro_emissao' => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'autorizado' => 'Autorizada',
                        'processando', 'enviando' => 'Processando',
                        'cancelado' => 'Cancelada',
                        'erro_autorizacao' => 'Erro de autorização',
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
                Tables\Filters\SelectFilter::make('document_type')
                    ->options(['NF-e' => 'NF-e', 'NFS-e' => 'NFS-e'])
                    ->label('Tipo'),
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'processando' => 'Processando',
                        'autorizado' => 'Autorizada',
                        'cancelado' => 'Cancelada',
                        'erro_autorizacao' => 'Erro de autorização',
                        'erro_emissao' => 'Erro de envio',
                    ])
                    ->label('Situação'),
                Tables\Filters\TrashedFilter::make()
                    ->label('Arquivamento')
                    ->placeholder('Somente ativos')
                    ->trueLabel('Ativos e arquivados')
                    ->falseLabel('Somente arquivados'),
            ])
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\Action::make('abrir_documento')
                        ->label('Abrir Documento')
                        ->icon('heroicon-o-arrow-top-right-on-square')
                        ->url(fn (FiscalDocument $record): ?string => $record->document_url)
                        ->openUrlInNewTab()
                        ->visible(fn (FiscalDocument $record): bool => filled($record->document_url)),
                    Tables\Actions\Action::make('consultar')
                        ->label('Consultar')
                        ->icon('heroicon-o-arrow-path')
                        ->color('info')
                        ->visible(fn (FiscalDocument $record): bool => in_array($record->status, ['processando', 'enviando'], true) && $record->isLinked())
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
                        ->modalDescription('O payload será reconstruído com a configuração atual antes do envio.')
                        ->visible(fn (FiscalDocument $record): bool => in_array($record->status, ['erro_autorizacao', 'erro_emissao'], true) && $record->isLinked())
                        ->action(function (FiscalDocument $record): void {
                            self::dispatchForSource($record, 'emit');
                        })
                        ->successNotificationTitle('Reenvio agendado'),
                    Tables\Actions\Action::make('cancelar_nota')
                        ->label('Cancelar Nota')
                        ->icon('heroicon-o-x-circle')
                        ->color('danger')
                        ->visible(fn (FiscalDocument $record): bool => $record->isLinked() && ($record->document_type === 'NF-e'
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
                    Tables\Actions\Action::make('arquivar')
                        ->label('Arquivar')
                        ->icon('heroicon-o-archive-box')
                        ->color('gray')
                        ->requiresConfirmation()
                        ->modalHeading('Arquivar documento fiscal?')
                        ->modalDescription(fn (FiscalDocument $record): string => in_array($record->status, ['processando', 'enviando'], true)
                            ? 'A nota continuará sendo consultada em segundo plano e poderá ser autorizada posteriormente. O registro apenas ficará oculto da listagem principal.'
                            : 'O documento sairá da listagem principal, mas continuará preservado para auditoria e poderá ser restaurado.')
                        ->visible(fn (FiscalDocument $record): bool => ! $record->trashed())
                        ->action(function (FiscalDocument $record): void {
                            $record->delete();
                        })
                        ->successNotificationTitle('Documento fiscal arquivado.'),
                    Tables\Actions\Action::make('restaurar')
                        ->label('Restaurar')
                        ->icon('heroicon-o-arrow-uturn-left')
                        ->color('success')
                        ->requiresConfirmation()
                        ->modalHeading('Restaurar documento fiscal?')
                        ->visible(fn (FiscalDocument $record): bool => $record->trashed())
                        ->action(function (FiscalDocument $record): void {
                            $record->restore();
                        })
                        ->successNotificationTitle('Documento fiscal restaurado.'),
                ])
                    ->icon('heroicon-o-ellipsis-vertical')
                    ->label('Ações'),
            ])
            ->defaultPaginationPageOption(25);
    }

    private static function dispatchForSource(FiscalDocument $record, string $operation, ?string $justification = null): void
    {
        if ($record->source_type === Sale::class) {
            match ($operation) {
                'consult' => ConsultSaleNfeJob::dispatch((int) $record->source_id),
                'emit' => EmitSaleNfeJob::dispatch((int) $record->source_id),
                'cancel' => CancelSaleNfeJob::dispatch((int) $record->source_id, $justification),
            };

            return;
        }

        if ($record->source_type === ServiceOrder::class) {
            match ($operation) {
                'consult' => ConsultServiceNfseJob::dispatch((int) $record->source_id),
                'emit' => EmitServiceNfseJob::dispatch((int) $record->source_id),
                'cancel' => CancelServiceNfseJob::dispatch((int) $record->source_id, $justification),
            };
        }
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
