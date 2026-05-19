<?php

namespace App\Filament\Resources\UserResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class FinesRelationManager extends RelationManager
{
    protected static string $relationship = 'fines';

    protected static ?string $title = 'Multas';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('ait')
                    ->required()
                    ->maxLength(255),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('ait')
            ->columns([
                Tables\Columns\TextColumn::make('vehicle.name')->label('Veículo'),
                Tables\Columns\TextColumn::make('ait')->label('AIT'),
                Tables\Columns\TextColumn::make('fine_date')->date('d/m/Y')->label('Data da Multa'),
                Tables\Columns\TextColumn::make('description')->label('Descrição'),
            ]);
    }
}