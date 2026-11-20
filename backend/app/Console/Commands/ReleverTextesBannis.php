<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\EtatBibliotheque;
use App\Jobs\ReleverTextesBannisDuPair;
use App\Models\Bibliotheque;
use Illuminate\Console\Command;

final class ReleverTextesBannis extends Command
{
    protected $signature = 'babel:relever-textes-bannis';

    protected $description = 'Dépose une tâche de relevé des textes bannis (B5) par pair en ligne';

    public function handle(): int
    {
        if (blank(config('babel.network_token'))) {
            $this->error('BABEL_NETWORK_TOKEN est vide : sans jeton réseau, les pairs refuseraient le relevé.');

            return self::FAILURE;
        }

        // Seuls les pairs en ligne : un pair hors ligne reviendra d'abord par le contrôle régulier (A9).
        $ids = Bibliotheque::query()->where('est_locale', false)->where('etat', EtatBibliotheque::EnLigne)->pluck('id');
        foreach ($ids as $id) {
            ReleverTextesBannisDuPair::dispatch($id);
        }

        $this->info("{$ids->count()} relevé(s) déposé(s).");

        return self::SUCCESS;
    }
}
