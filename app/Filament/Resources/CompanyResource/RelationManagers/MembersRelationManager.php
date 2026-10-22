<?php

namespace App\Filament\Resources\CompanyResource\RelationManagers;

use App\Models\User;
use App\Services\CompanyMembershipService;
use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class MembersRelationManager extends RelationManager
{
    protected static string $relationship = 'members';
    protected static ?string $title = 'Pessoas com acesso';

    private function roleOptions(): array
    {
        $options = ['manager' => 'Gestor', 'operator' => 'Operador de emissão', 'viewer' => 'Consulta'];
        return auth()->user()->canAccessCompany($this->getOwnerRecord(), 'owner') ? ['owner' => 'Proprietário'] + $options : $options;
    }

    public function table(Table $table): Table
    {
        return $table->columns([
            Tables\Columns\TextColumn::make('name')->label('Nome'),
            Tables\Columns\TextColumn::make('email')->label('E-mail'),
            Tables\Columns\TextColumn::make('pivot.role')->label('Permissão')->badge()
                ->formatStateUsing(fn (string $state): string => ['owner' => 'Proprietário', 'manager' => 'Gestor', 'operator' => 'Operador', 'viewer' => 'Consulta'][$state] ?? $state),
        ])->headerActions([
            Tables\Actions\Action::make('grant_access')->label('Conceder acesso')->form([
                Forms\Components\Select::make('user_id')->label('Usuário')->options(fn (): array => User::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all())->required()->searchable(),
                Forms\Components\Select::make('role')->label('Permissão')->options(fn (): array => $this->roleOptions())->required()->default('viewer'),
            ])->visible(fn (): bool => auth()->user()->canAccessCompany($this->getOwnerRecord(), 'manage_members'))
                ->action(fn (array $data) => app(CompanyMembershipService::class)->assign($this->getOwnerRecord(), User::findOrFail($data['user_id']), $data['role'], auth()->user())),
        ])->actions([
            Tables\Actions\Action::make('change_role')->label('Alterar permissão')->form([
                Forms\Components\Select::make('role')->label('Permissão')->options(fn (): array => $this->roleOptions())->required(),
            ])->fillForm(fn (User $record): array => ['role' => $record->pivot->role])
                ->visible(fn (): bool => auth()->user()->canAccessCompany($this->getOwnerRecord(), 'manage_members'))
                ->action(fn (User $record, array $data) => app(CompanyMembershipService::class)->assign($this->getOwnerRecord(), $record, $data['role'], auth()->user())),
            Tables\Actions\Action::make('remove_access')->label('Revogar acesso')->color('danger')->requiresConfirmation()
                ->visible(fn (): bool => auth()->user()->canAccessCompany($this->getOwnerRecord(), 'manage_members'))
                ->action(fn (User $record) => app(CompanyMembershipService::class)->remove($this->getOwnerRecord(), $record, auth()->user())),
        ]);
    }
}
