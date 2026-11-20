<?php

namespace App\Filament\Resources\TextesBannis;

use App\Enums\StatutTexteBanni;
use App\Filament\Resources\TextesBannis\Pages\ListTextesBannis;
use App\Models\TexteBanni;
use App\Services\Moderation\ServiceBannissement;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * A5 (déclarer un texte banni, ou lever un bannissement) et A7 (examiner ceux des autres bibliothèques).
 * Le texte est stocké normalisé : c'est celui qu'on cherche dans les pages (règle 16).
 */
class TexteBanniResource extends Resource
{
    protected static ?string $model = TexteBanni::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedNoSymbol;

    protected static ?string $slug = 'textes-bannis';

    protected static ?string $modelLabel = 'texte banni';

    protected static ?string $pluralModelLabel = 'textes bannis';

    protected static ?int $navigationSort = 30;

    public static function canCreate(): bool
    {
        return false; // la déclaration passe par l'action « Déclarer » de la liste (ServiceBannissement::declarer)
    }

    public static function getNavigationBadge(): ?string
    {
        $n = TexteBanni::query()->where('statut', StatutTexteBanni::Propose)->count();

        return $n > 0 ? (string) $n : null;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('origine')->withCount('adresses');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('cree_le', 'desc')
            ->columns([
                TextColumn::make('texte')->label('Texte (normalisé)')->wrap()->limit(100)->searchable(),
                TextColumn::make('motif')->label('Motif')->wrap()->limit(80)->placeholder('—'),
                TextColumn::make('origine.nom')->label('Origine')
                    ->formatStateUsing(fn ($state, TexteBanni $t) => $t->origine->est_locale ? 'Ici' : $t->origine->nom),
                TextColumn::make('statut')->label('Statut')->badge()
                    ->formatStateUsing(fn (StatutTexteBanni $state) => match ($state) {
                        StatutTexteBanni::Propose => 'Proposé',
                        StatutTexteBanni::Applique => 'Appliqué',
                        StatutTexteBanni::Rejete => 'Rejeté / levé',
                    })
                    ->color(fn (StatutTexteBanni $state) => match ($state) {
                        StatutTexteBanni::Propose => 'warning',
                        StatutTexteBanni::Applique => 'danger',
                        StatutTexteBanni::Rejete => 'gray',
                    }),
                TextColumn::make('adresses_count')->label('Adresses marquées')->sortable(),
                TextColumn::make('decide_le')->label('Décidé le')->dateTime('d/m/Y H:i')->placeholder('—'),
            ])
            ->deferFilters(false)
            ->filters([
                SelectFilter::make('statut')->label('Statut')
                    ->options(['propose' => 'Proposé', 'applique' => 'Appliqué', 'rejete' => 'Rejeté / levé']),
                SelectFilter::make('origine')->label('Origine')
                    ->options(['locale' => 'Ici', 'pairs' => 'Autres bibliothèques'])
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                        'locale' => $query->whereHas('origine', fn ($b) => $b->where('est_locale', true)),
                        'pairs' => $query->whereHas('origine', fn ($b) => $b->where('est_locale', false)),
                        default => $query,
                    }),
            ])
            ->recordActions([
                Action::make('appliquer')->label('Appliquer')->icon(Heroicon::OutlinedCheck)->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('Les pages enregistrées qui contiennent ce texte ne seront plus montrées. Rien n\'est supprimé.')
                    ->visible(fn (TexteBanni $t) => $t->statut !== StatutTexteBanni::Applique)
                    ->action(function (TexteBanni $t) {
                        app(ServiceBannissement::class)->appliquer($t);
                        Notification::make()->title('Texte appliqué')->success()->send();
                    }),
                Action::make('rejeter')->label(fn (TexteBanni $t) => $t->statut === StatutTexteBanni::Applique ? 'Lever le bannissement' : 'Rejeter')
                    ->icon(Heroicon::OutlinedXMark)
                    ->requiresConfirmation()
                    ->visible(fn (TexteBanni $t) => $t->statut !== StatutTexteBanni::Rejete)
                    ->action(function (TexteBanni $t) {
                        app(ServiceBannissement::class)->lever($t);
                        Notification::make()->title('Texte rejeté, marques retirées')->success()->send();
                    }),
            ])
            ->toolbarActions([]);
    }

    public static function getPages(): array
    {
        return ['index' => ListTextesBannis::route('/')];
    }
}
