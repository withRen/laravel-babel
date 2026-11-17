<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\ControlerPair;
use App\Models\Bibliotheque;
use Illuminate\Console\Command;

final class ControlerPairs extends Command
{
    protected $signature = 'babel:controler-pairs';

    protected $description = "Dépose une tâche de contrôle (lecture de l'identité) par pair connu";

    public function handle(): int
    {
        // Les pairs hors ligne sont contrôlés aussi : c'est un contrôle réussi qui les remet en ligne.
        $ids = Bibliotheque::query()->where('est_locale', false)->pluck('id');
        foreach ($ids as $id) {
            ControlerPair::dispatch($id);
        }

        $this->info("{$ids->count()} contrôle(s) déposé(s).");

        return self::SUCCESS;
    }
}
