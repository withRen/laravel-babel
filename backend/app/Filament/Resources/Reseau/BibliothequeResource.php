<?php

namespace App\Filament\Resources\Reseau;

use App\Enums\EtatBibliotheque;
use App\Filament\Resources\Reseau\Pages\ListBibliotheques;
use App\Filament\Widgets\EtatReseauStats;
use App\Models\Bibliotheque;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** A9 : l'état du réseau. Les pairs, qui répond, quelle clé a changé. Lecture seule : l'annuaire et le contrôle régulier écrivent. */
class BibliothequeResource extends Resource
{
    protected static ?string $model = Bibliotheque::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGlobeAlt;

    protected static ?string $slug = 'reseau';

    protected static ?string $modelLabel = 'bibliothèque du réseau';

    protected static ?string $pluralModelLabel = 'réseau';

    protected static ?string $navigationLabel = 'État du réseau';

    protected static ?int $navigationSort = 40;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('est_locale', false);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('nom')
            ->columns([
                TextColumn::make('nom')->label('Nom')->searchable()->sortable()->placeholder('(sans nom)'),
                TextColumn::make('identifiant')->label('Identifiant')->searchable(),
                TextColumn::make('url_actuelle')->label('URL du moment')->limit(40),
                TextColumn::make('etat')->label('État')->badge()
                    ->formatStateUsing(fn (EtatBibliotheque $state) => match ($state) {
                        EtatBibliotheque::EnLigne => 'En ligne',
                        EtatBibliotheque::HorsLigne => 'Hors ligne',
                        EtatBibliotheque::Inconnu => 'Inconnu',
                    })
                    ->color(fn (EtatBibliotheque $state) => match ($state) {
                        EtatBibliotheque::EnLigne => 'success',
                        EtatBibliotheque::HorsLigne => 'danger',
                        EtatBibliotheque::Inconnu => 'gray',
                    }),
                TextColumn::make('echecs_consecutifs')->label('Échecs de suite')->sortable(),
                TextColumn::make('dernier_contact_le')->label('Dernier contact')->dateTime('d/m/Y H:i')->sortable()->placeholder('jamais'),
                IconColumn::make('cle_changee_le')->label('Clé changée')
                    ->state(fn (Bibliotheque $b) => $b->cle_changee_le !== null)->boolean()
                    ->trueIcon(Heroicon::OutlinedExclamationTriangle)->falseIcon(Heroicon::OutlinedMinus)
                    ->trueColor('warning')->falseColor('gray')
                    ->tooltip(fn (Bibliotheque $b) => $b->cle_changee_le
                        ? 'Clé changée le '.$b->cle_changee_le->format('d/m/Y H:i').' : les adresses enregistrées vers elle ne désignent plus les mêmes textes.'
                        : null),
            ])
            ->deferFilters(false)
            ->filters([
                SelectFilter::make('etat')->label('État')
                    ->options(['en_ligne' => 'En ligne', 'hors_ligne' => 'Hors ligne', 'inconnu' => 'Inconnu']),
                Filter::make('cle_changee')->label('Clé changée')->query(fn (Builder $query) => $query->whereNotNull('cle_changee_le')),
            ])
            ->recordActions([])
            ->toolbarActions([]);
    }

    public static function getWidgets(): array
    {
        return [EtatReseauStats::class];
    }

    public static function getPages(): array
    {
        return ['index' => ListBibliotheques::route('/')];
    }
}
