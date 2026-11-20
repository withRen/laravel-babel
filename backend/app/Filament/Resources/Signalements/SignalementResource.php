<?php

namespace App\Filament\Resources\Signalements;

use App\Enums\StatutSignalement;
use App\Filament\Resources\Signalements\Pages\ListSignalements;
use App\Models\Annotation;
use App\Models\Collection;
use App\Models\LienCourt;
use App\Models\Signalement;
use App\Services\Moderation\ServiceSignalements;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/** A4 : la file des signalements. Masquer ou rétablir le contenu publié, ou rejeter le signalement. */
class SignalementResource extends Resource
{
    protected static ?string $model = Signalement::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFlag;

    protected static ?string $modelLabel = 'signalement';

    protected static ?string $pluralModelLabel = 'signalements';

    protected static ?int $navigationSort = 20;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        $n = Signalement::query()->where('statut', StatutSignalement::EnAttente)->count();

        return $n > 0 ? (string) $n : null;
    }

    public static function getEloquentQuery(): Builder
    {
        // Eager loading obligatoire : le lazy loading est interdit hors production (AppServiceProvider).
        return parent::getEloquentQuery()->with(['annotation', 'collection', 'lienCourt', 'auteur', 'traitePar']);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('cree_le', 'asc') // les plus anciens d'abord : une file se vide dans l'ordre
            ->columns([
                TextColumn::make('type')->label('Contenu')->badge()
                    ->state(fn (Signalement $s) => match (true) {
                        $s->annotation_id !== null => 'Annotation',
                        $s->collection_id !== null => 'Collection',
                        default => 'Lien court',
                    }),
                TextColumn::make('apercu')->label('Aperçu')->wrap()->limit(120)
                    ->state(fn (Signalement $s) => match (true) {
                        $s->annotation !== null => $s->annotation->contenu,
                        $s->collection !== null => $s->collection->nom,
                        $s->lienCourt !== null => 'Lien /'.$s->lienCourt->slug.($s->lienCourt->passage ? ' — passage : '.$s->lienCourt->passage : ''),
                        default => '—',
                    }),
                TextColumn::make('motif')->label('Motif')->wrap()->limit(120),
                TextColumn::make('auteur.pseudo')->label('Signalé par')->placeholder('visiteur'),
                TextColumn::make('masque')->label('Contenu')->badge()
                    ->state(fn (Signalement $s) => self::contenu($s)?->masque_le !== null ? 'Masqué' : 'Visible')
                    ->color(fn (string $state) => $state === 'Masqué' ? 'danger' : 'success'),
                TextColumn::make('statut')->label('Statut')->badge()
                    ->formatStateUsing(fn (StatutSignalement $state) => match ($state) {
                        StatutSignalement::EnAttente => 'En attente',
                        StatutSignalement::Traite => 'Traité',
                        StatutSignalement::Rejete => 'Rejeté',
                    })
                    ->color(fn (StatutSignalement $state) => match ($state) {
                        StatutSignalement::EnAttente => 'warning',
                        StatutSignalement::Traite => 'success',
                        StatutSignalement::Rejete => 'gray',
                    }),
                TextColumn::make('cree_le')->label('Reçu le')->dateTime('d/m/Y H:i')->sortable(),
                TextColumn::make('traitePar.pseudo')->label('Décidé par')->placeholder('—'),
            ])
            ->deferFilters(false)
            ->filters([
                SelectFilter::make('statut')->label('Statut')->default(StatutSignalement::EnAttente->value)
                    ->options(['en_attente' => 'En attente', 'traite' => 'Traité', 'rejete' => 'Rejeté']),
            ])
            ->recordActions([
                Action::make('masquer')->label('Masquer le contenu')->icon(Heroicon::OutlinedEyeSlash)->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (Signalement $s) => self::contenu($s)?->masque_le === null)
                    ->action(function (Signalement $s) {
                        app(ServiceSignalements::class)->masquer($s, Auth::user());
                        Notification::make()->title('Contenu masqué, signalement traité')->success()->send();
                    }),
                Action::make('retablir')->label('Rétablir le contenu')->icon(Heroicon::OutlinedEye)->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (Signalement $s) => self::contenu($s)?->masque_le !== null)
                    ->action(function (Signalement $s) {
                        app(ServiceSignalements::class)->retablir($s, Auth::user());
                        Notification::make()->title('Contenu rétabli, signalement rejeté')->success()->send();
                    }),
                Action::make('rejeter')->label('Rejeter')->icon(Heroicon::OutlinedXMark)
                    ->visible(fn (Signalement $s) => $s->statut === StatutSignalement::EnAttente)
                    ->action(function (Signalement $s) {
                        app(ServiceSignalements::class)->rejeter($s, Auth::user());
                        Notification::make()->title('Signalement rejeté')->success()->send();
                    }),
            ])
            ->toolbarActions([]);
    }

    private static function contenu(Signalement $s): Annotation|Collection|LienCourt|null
    {
        return $s->annotation ?? $s->collection ?? $s->lienCourt;
    }

    public static function getPages(): array
    {
        return ['index' => ListSignalements::route('/')];
    }
}
