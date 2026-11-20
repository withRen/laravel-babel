<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\Membre\Donnees\ConvertisseurDAdresses;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * La tâche de fond de l'import (M12) : convertit les adresses importées d'un membre (voir ConvertisseurDAdresses).
 * Lancée juste après l'import, puis relancée par « php artisan babel:convertir-import » tant qu'il en reste.
 */
class ConvertirAdressesImportees implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $utilisateurId) {}

    public function handle(ConvertisseurDAdresses $convertisseur): void
    {
        // Compte supprimé entre-temps : plus rien à convertir.
        $membre = User::query()->find($this->utilisateurId);

        if ($membre !== null) {
            $convertisseur->convertir($membre);
        }
    }
}
