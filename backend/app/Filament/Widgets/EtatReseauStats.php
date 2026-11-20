<?php

namespace App\Filament\Widgets;

use App\Enums\EtatBibliotheque;
use App\Models\Bibliotheque;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;

/** A9 : lien avec l'annuaire, pairs qui répondent, clés qui ont changé. */
class EtatReseauStats extends StatsOverviewWidget
{
    // Affiché sur la page « État du réseau », pas sur le tableau de bord.
    protected static bool $isDiscovered = false;

    protected ?string $pollingInterval = null;

    protected function getStats(): array
    {
        $pairs = Bibliotheque::query()->where('est_locale', false);
        $annuaire = filled(config('babel.annuaire_url'));
        $vue = Bibliotheque::query()->max('vue_annuaire_le');

        return [
            Stat::make('Annuaire', $annuaire ? 'Configuré' : 'Non configuré')
                ->description($annuaire
                    ? 'Dernière liste reçue : '.($vue ? Carbon::parse($vue)->format('d/m/Y H:i') : 'jamais')
                    : 'BABEL_ANNUAIRE_URL est vide : aucune annonce')
                ->color($annuaire ? 'success' : 'warning'),
            Stat::make('Pairs en ligne', (clone $pairs)->where('etat', EtatBibliotheque::EnLigne)->count().' / '.(clone $pairs)->count())
                ->description((clone $pairs)->where('etat', EtatBibliotheque::HorsLigne)->count().' hors ligne')
                ->color('success'),
            Stat::make('Clés changées', (clone $pairs)->whereNotNull('cle_changee_le')->count())
                ->description('Leurs adresses ne désignent plus les mêmes textes')
                ->color('warning'),
        ];
    }
}
