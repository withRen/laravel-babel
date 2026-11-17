<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\LecturesRequest;
use App\Models\Bibliotheque;
use App\Services\Reseau\LecteurDePages;
use Babel\Moteur\Erreur\ErreurMoteur;
use Illuminate\Http\JsonResponse;

/**
 * POST /lectures (B1) : jusqu'à 50 pages en un appel (un seul appel pour le quota).
 * La réponse suit l'ordre de la demande ; une adresse refusée reçoit ses erreurs à sa place,
 * le statut reste 200.
 */
class LectureController extends Controller
{
    public function __invoke(LecturesRequest $requete, LecteurDePages $lecteur): JsonResponse
    {
        $elements = [];
        foreach ($requete->adresses() as $adresse) {
            try {
                $elements[] = ['adresse' => $adresse, 'texte' => $lecteur->lire($adresse), 'erreurs' => null];
            } catch (ErreurMoteur $e) {
                $elements[] = ['adresse' => $adresse, 'texte' => null, 'erreurs' => $e->versTableau()];
            }
        }

        return response()->json([
            'bibliotheque' => Bibliotheque::locale()?->identifiant ?? config('babel.identifiant'),
            'elements' => $elements,
        ]);
    }
}
