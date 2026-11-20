<?php

namespace App\Filament\Resources\Users;

use App\Enums\Role;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * A1 (voir et filtrer les comptes), A2 (suspendre, rétablir, supprimer), A3 (promouvoir administrateur).
 * Pas de création ni d'édition : les comptes naissent par l'inscription (V19), et on ne modifie pas le mot de passe d'autrui.
 */
class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?string $slug = 'comptes';

    protected static ?string $modelLabel = 'compte';

    protected static ?string $pluralModelLabel = 'comptes';

    protected static ?int $navigationSort = 10;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('cree_le', 'desc')
            ->columns([
                TextColumn::make('pseudo')->label('Pseudo')->searchable()->sortable(),
                TextColumn::make('email')->label('Email')->searchable()->sortable(),
                TextColumn::make('role')->label('Rôle')->badge()
                    ->formatStateUsing(fn (Role $state) => $state === Role::Admin ? 'Administrateur' : 'Membre')
                    ->color(fn (Role $state) => $state === Role::Admin ? 'warning' : 'gray'),
                TextColumn::make('suspendu_le')->label('Statut')->badge()
                    ->state(fn (User $u) => $u->estSuspendu() ? 'Suspendu' : 'Actif')
                    ->color(fn (string $state) => $state === 'Suspendu' ? 'danger' : 'success'),
                TextColumn::make('cree_le')->label('Inscrit le')->dateTime('d/m/Y H:i')->sortable(),
                TextColumn::make('derniere_connexion_le')->label('Dernière connexion')->dateTime('d/m/Y H:i')->sortable()->placeholder('jamais'),
            ])
            ->deferFilters(false)
            ->filters([
                SelectFilter::make('role')->label('Rôle')->options(['membre' => 'Membre', 'admin' => 'Administrateur']),
                SelectFilter::make('statut')->label('Statut')->options(['actif' => 'Actif', 'suspendu' => 'Suspendu'])
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                        'suspendu' => $query->whereNotNull('suspendu_le'),
                        'actif' => $query->whereNull('suspendu_le'),
                        default => $query,
                    }),
            ])
            ->recordActions([
                Action::make('suspendre')->label('Suspendre')->icon(Heroicon::OutlinedNoSymbol)->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (User $u) => ! $u->estSuspendu() && ! self::estMoi($u))
                    ->action(function (User $u) {
                        $u->forceFill(['suspendu_le' => now()])->save();
                        // Ses jetons d'accès sont révoqués tout de suite (le middleware compte.actif les refuserait déjà).
                        $u->tokens()->delete();
                        Notification::make()->title('Compte suspendu')->success()->send();
                    }),
                Action::make('retablir')->label('Rétablir')->icon(Heroicon::OutlinedCheckCircle)->color('success')
                    ->visible(fn (User $u) => $u->estSuspendu())
                    ->action(function (User $u) {
                        $u->forceFill(['suspendu_le' => null])->save();
                        Notification::make()->title('Compte rétabli')->success()->send();
                    }),
                Action::make('promouvoir')->label('Promouvoir administrateur')->icon(Heroicon::OutlinedShieldCheck)
                    ->requiresConfirmation()
                    ->modalDescription('Cet administrateur pourra modérer et configurer la bibliothèque.')
                    ->visible(fn (User $u) => ! $u->estAdmin() && ! $u->estSuspendu())
                    ->action(function (User $u) {
                        $u->forceFill(['role' => Role::Admin])->save();
                        Notification::make()->title('Membre promu administrateur')->success()->send();
                    }),
                DeleteAction::make()->label('Supprimer')
                    ->modalDescription('Marque-pages, collections, annotations, votes, recherches et liens courts seront effacés. Ses signalements restent, sans auteur.')
                    ->visible(fn (User $u) => ! self::estMoi($u) && ! $u->estAdmin()),
            ])
            ->toolbarActions([]);
    }

    /** On ne s'agit pas soi-même : un administrateur qui se suspend ou se supprime se verrouille dehors. */
    private static function estMoi(User $u): bool
    {
        return Auth::id() === $u->id;
    }

    public static function getPages(): array
    {
        return ['index' => ListUsers::route('/')];
    }
}
