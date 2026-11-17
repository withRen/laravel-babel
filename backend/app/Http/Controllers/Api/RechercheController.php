<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\RechercheRequest;
use App\Models\Bibliotheque;
use App\Services\Engine\Normaliseur;
use Babel\Moteur\Moteur;
use Illuminate\Http\JsonResponse;

/** POST /recherches (F2, B2) : l'adresse d'une page qui contient le texte. Rien n'est enregistré. */
class RechercheController extends Controller
{
    public function __invoke(RechercheRequest $requete, Normaliseur $normaliseur, Moteur $moteur): JsonResponse
    {
        $mode = $requete->mode();

        // F3 : on normalise TOUJOURS avant le moteur, même quand la demande vient d'un pair. En mode « page »,
        // seules les étapes 1 à 3 s'appliquent (une page contient des espaces doubles qu'il faut garder).
        $texte = $normaliseur->normaliser($requete->texte(), etapesDePage: $mode === 'page');

        // Le moteur contrôle dans l'ordre mode, texte vide, longueur, et lève l'unique erreur du contrat.
        $adresse = $moteur->chercher($texte, $mode);

        return response()->json([
            'bibliotheque' => Bibliotheque::locale()?->identifiant ?? config('babel.identifiant'),
            'texte_normalise' => $texte, // ce qui a réellement été cherché (V6)
            'mode' => $mode,
            'adresse' => $adresse,
        ]);
    }
}
