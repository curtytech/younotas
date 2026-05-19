<?php

namespace App\Filament\Resources\UserResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class AppealsRelationManager extends RelationManager
{
    protected static string $relationship = 'appeals';

    protected static ?string $title = 'Recursos';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\DatePicker::make('date')
                    ->required(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->columns([
                Tables\Columns\TextColumn::make('fine.ait')->label('Multa (AIT)'),
                Tables\Columns\TextColumn::make('appealStatus.name')->label('Status'),
                Tables\Columns\TextColumn::make('date')->date('d/m/Y')->label('Data'),
            ]);
    }
}