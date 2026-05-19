<?php

namespace App\Filament\Resources\UserResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class DriversRelationManager extends RelationManager
{
    protected static string $relationship = 'drivers';

    protected static ?string $title = 'Motoristas';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->maxLength(255),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                Tables\Columns\TextColumn::make('vehicle.name')->label('Veículo'),
                Tables\Columns\TextColumn::make('name')->label('Nome'),
                Tables\Columns\TextColumn::make('cpf')->label('CPF'),
                Tables\Columns\TextColumn::make('cnh')->label('CNH'),
                Tables\Columns\TextColumn::make('birth_date')->date('d/m/Y')->label('Nascimento'),
                Tables\Columns\TextColumn::make('cnh_expiration_date')->date('d/m/Y')->label('Validade CNH'),
            ]);
    }
}