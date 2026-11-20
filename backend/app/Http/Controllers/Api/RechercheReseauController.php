<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ErreurApi;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\RechercheReseauRequest;
use App\Services\Reseau\RechercheReseau;
use Illuminate\Http\JsonResponse;

/** La recherche sur tout le réseau (V15) : lancer, puis lire l'état. Les résultats arrivent aussi par Reverb. */
class RechercheReseauController extends Controller
{
    /** POST /app/recherches-reseau : répond tout de suite (202) ; chaque pair répond ensuite sur le canal temps réel. */
    public function lancer(RechercheReseauRequest $requete, RechercheReseau $recherche): JsonResponse
    {
        $lancee = $recherche->lancer($requete->identifiant(), $requete->texte(), $requete->mode(), $requete->user());

        return response()->json($lancee, 202);
    }

    /** GET /app/recherches-reseau/{identifiant} : l'état et les résultats déjà arrivés (au moins 10 minutes). */
    public function etat(string $identifiant, RechercheReseau $recherche): JsonResponse
    {
        // Un identifiant mal écrit, inconnu ou expiré : la même 404.
        $etat = $recherche->etat($identifiant)
            ?? throw ErreurApi::nonTrouve("Cette recherche n'existe pas ou a expiré.", 'identifiant');

        return response()->json($etat);
    }
}
