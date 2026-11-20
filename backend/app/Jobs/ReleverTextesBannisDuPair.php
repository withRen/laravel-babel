<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Bibliotheque;
use App\Services\Reseau\ErreurPair;
use App\Services\Reseau\ReleveDesTextesBannis;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Une tâche de fond par pair : le relevé d'un pair lent n'attend pas celui des autres. */
final class ReleverTextesBannisDuPair implements ShouldQueue
{
    use Queueable;

    // Pas de reprise : le prochain relevé (dans quelques minutes) la remplace, et ClientPair a déjà compté l'échec.
    public int $tries = 1;

    public int $timeout = 15;

    public function __construct(public readonly int $bibliothequeId) {}

    public function handle(ReleveDesTextesBannis $releve): void
    {
        $pair = Bibliotheque::find($this->bibliothequeId);
        if ($pair === null || $pair->est_locale) {
            return;
        }

        try {
            $releve->relever($pair);
        } catch (ErreurPair) {
            // Injoignable (compteur déjà augmenté) ou occupé (429, pas une panne) : rien d'autre à faire, on réessaie au prochain relevé.
        }
    }
}
