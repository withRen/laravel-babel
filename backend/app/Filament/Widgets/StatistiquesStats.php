<?php

namespace App\Filament\Widgets;

use App\Enums\StatutSignalement;
use App\Models\ActiviteJour;
use App\Models\Signalement;
use App\Models\User;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/** A11 : comptes, membres actifs, pages lues, recherches, signalements. Les lectures et recherches viennent des compteurs par jour. */
class StatistiquesStats extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        $sur30Jours = ActiviteJour::query()->where('jour', '>=', now()->subDays(29)->toDateString());

        return [
            Stat::make('Comptes', User::query()->count())
                ->description(User::query()->whereNotNull('suspendu_le')->count().' suspendu(s)'),
            Stat::make('Membres actifs (30 j)', User::query()->where('derniere_connexion_le', '>=', now()->subDays(30))->count())
                ->description('connectés dans les 30 derniers jours'),
            Stat::make('Pages lues (30 j)', (clone $sur30Jours)->sum('pages_lues')),
            Stat::make('Recherches (30 j)', (clone $sur30Jours)->sum('recherches')),
            Stat::make('Signalements en attente', Signalement::query()->where('statut', StatutSignalement::EnAttente)->count())
                ->color('warning'),
        ];
    }
}
