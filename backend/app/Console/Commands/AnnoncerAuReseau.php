<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Annuaire\ErreurAnnuaire;
use App\Services\Annuaire\SynchroniseurAnnuaire;
use Illuminate\Console\Command;

final class AnnoncerAuReseau extends Command
{
    protected $signature = 'babel:annoncer {--controle : Déposer aussi une tâche de contrôle par pair}';

    protected $description = "S'annonce à l'annuaire du réseau et enregistre la liste des pairs";

    public function handle(SynchroniseurAnnuaire $synchroniseur): int
    {
        try {
            $vus = $synchroniseur->synchroniser();
        } catch (ErreurAnnuaire $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Annoncé à l'annuaire. {$vus} pair(s) enregistré(s).");

        if ($this->option('controle')) {
            $this->call('babel:controler-pairs');
        }

        return self::SUCCESS;
    }
}
