<?php

namespace App\Filament\Resources;

use App\Filament\Resources\FiscalDocumentResource\Pages;
use App\Jobs\ConsultSaleNfeJob;
use App\Jobs\ConsultServiceNfseJob;
use App\Jobs\EmitSaleNfeJob;
use App\Jobs\EmitServiceNfseJob;
use App\Models\FiscalDocument;
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
            ->defaultSort('last_sent_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('document_type')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'NF-e' ? 'primary' : 'info')
                    ->label('Tipo'),
                Tables\Columns\TextColumn::make('source_label')
                    ->searchable()
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
                Tables\Columns\TextColumn::make('reference')
                    ->copyable()
                    ->copyMessage('Referência copiada')
                    ->label('Referência Focus'),
                Tables\Columns\TextColumn::make('last_checked_at')
                    ->dateTime('d/m/Y H:i:s')
                    ->placeholder('—')
                    ->sortable()
                    ->label('Última consulta'),
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
            ])
            ->actions([
                Tables\Actions\Action::make('consultar')
                    ->label('Consultar')
                    ->icon('heroicon-o-arrow-path')
                    ->color('info')
                    ->visible(fn (FiscalDocument $record): bool => in_array($record->status, ['processando', 'enviando'], true))
                    ->action(function (FiscalDocument $record): void {
                        if ($record->document_type === 'NF-e') {
                            ConsultSaleNfeJob::dispatch((int) $record->source_id);
                        } else {
                            ConsultServiceNfseJob::dispatch((int) $record->source_id);
                        }
                    })
                    ->successNotificationTitle('Consulta agendada'),
                Tables\Actions\Action::make('reenviar')
                    ->label('Reenviar')
                    ->icon('heroicon-o-arrow-uturn-right')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('Reenviar documento fiscal?')
                    ->modalDescription('O payload será reconstruído com a configuração atual antes do envio.')
                    ->visible(fn (FiscalDocument $record): bool => in_array($record->status, ['erro_autorizacao', 'erro_emissao'], true))
                    ->action(function (FiscalDocument $record): void {
                        if ($record->document_type === 'NF-e') {
                            EmitSaleNfeJob::dispatch((int) $record->source_id);
                        } else {
                            EmitServiceNfseJob::dispatch((int) $record->source_id);
                        }
                    })
                    ->successNotificationTitle('Reenvio agendado'),
                Tables\Actions\Action::make('abrir_documento')
                    ->label('Abrir documento')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (FiscalDocument $record): ?string => $record->document_url)
                    ->openUrlInNewTab()
                    ->visible(fn (FiscalDocument $record): bool => filled($record->document_url)),
            ])
            ->defaultPaginationPageOption(25);
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
