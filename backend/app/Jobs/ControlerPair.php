<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Bibliotheque;
use App\Services\Annuaire\ControleurDePair;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Une tâche de fond par pair : un pair lent ou éteint ne retarde jamais les autres. */
final class ControlerPair implements ShouldQueue
{
    use Queueable;

    // Pas de reprise automatique : l'échec est compté par le contrôleur, un rejeu fausserait le compteur.
    public int $tries = 1;

    public function __construct(public readonly int $bibliothequeId) {}

    public function handle(ControleurDePair $controleur): void
    {
        $pair = Bibliotheque::find($this->bibliothequeId);
        if ($pair !== null) {
            $controleur->controler($pair);
        }
    }
}
