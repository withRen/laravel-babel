<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Bibliotheque;
use App\Services\Reseau\ClientPair;
use App\Services\Reseau\ErreurPair;
use App\Services\Reseau\RechercheReseau;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Une tâche de fond PAR PAIR pendant une recherche réseau : un pair lent ou éteint ne retarde jamais les autres.
 * Elle appelle `POST /recherches` chez le pair (2 secondes au plus), vérifie la réponse contre le contrat, puis publie
 * le résultat. Quoi qu'il arrive, elle publie UN résultat : sinon le bilan `terminee` n'arriverait jamais.
 *
 * Pour que les pairs soient vraiment interrogés en parallèle, il faut plusieurs workers : `php artisan queue:work`
 * lancé plusieurs fois (un worker traite une tâche à la fois).
 */
final class InterrogerPairPourRecherche implements ShouldQueue
{
    use Queueable;

    // Pas de reprise : l'échec est compté par ClientPair, un rejeu fausserait le compteur du pair.
    public int $tries = 1;

    // Garde-fou (le client a déjà son délai de 2 secondes) : une tâche coincée ne bloque pas un worker.
    public int $timeout = 15;

    public function __construct(
        public readonly string $identifiant,
        public readonly int $bibliothequeId,
        public readonly string $texte,
        public readonly string $mode,
    ) {}

    public function handle(ClientPair $client, RechercheReseau $recherche): void
    {
        $pair = Bibliotheque::find($this->bibliothequeId);

        if ($pair === null) {
            $resultat = $this->sansReponse('inconnu', 'sans_reponse');
        } else {
            try {
                $reponse = $client->chercher($pair, $this->texte, $this->mode);
                $resultat = [
                    'bibliotheque' => $pair->identifiant,
                    'statut' => 'trouve',
                    'adresse' => $reponse['adresse'],
                    // Si ce texte diffère du nôtre, l'un des deux applique mal F3 : on le montre tel quel.
                    'texte_normalise' => $reponse['texte_normalise'],
                ];
            } catch (ErreurPair $e) {
                // 429 : occupé, pas une panne (son compteur n'a pas bougé). Tout le reste : sans réponse.
                $resultat = $this->sansReponse($pair->identifiant, $e->occupe ? 'occupe' : 'sans_reponse');
            } catch (Throwable $e) {
                report($e);
                $resultat = $this->sansReponse($pair->identifiant, 'sans_reponse');
            }
        }

        $recherche->enregistrer($this->identifiant, $resultat);
    }

    /** @return array{bibliotheque: string, statut: string, adresse: null, texte_normalise: null} */
    private function sansReponse(string $bibliotheque, string $statut): array
    {
        return ['bibliotheque' => $bibliotheque, 'statut' => $statut, 'adresse' => null, 'texte_normalise' => null];
    }
}
