<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ErreurApi;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ListeCollectionsRequest;
use App\Http\Requests\Api\PagesPopulairesRequest;
use App\Http\Resources\Api\BibliothequeReseauResource;
use App\Http\Resources\Api\CollectionResource;
use App\Http\Resources\Api\PagePopulaireResource;
use App\Http\Resources\Api\ResumeCollectionResource;
use App\Models\Bibliotheque;
use App\Services\Reseau\CollectionsPubliques;
use App\Services\Reseau\ExplorationDuReseau;
use App\Services\Reseau\PagesPopulaires;
use Illuminate\Http\JsonResponse;

/**
 * Les bibliothèques du réseau vues par l'application web (V15, V16, V21, M13).
 *
 * Avec l'identifiant de NOTRE bibliothèque, les routes de collections et de pages populaires répondent comme la porte
 * réseau (V13). Avec celui d'un pair, notre serveur relaie l'appel et filtre ce qui contient un de nos textes bannis.
 */
class BibliothequeController extends Controller
{
    /** GET /app/bibliotheques : la liste connue, la nôtre en premier. Aucun appel réseau : tout vient de la base. */
    public function index(): JsonResponse
    {
        $bibliotheques = Bibliotheque::query()->orderByDesc('est_locale')->orderBy('identifiant')->get();

        if (! $bibliotheques->contains('est_locale', true)) {
            // Base pas encore amorcée : la configuration décrit notre bibliothèque, comme GET /identite.
            $bibliotheques->prepend(new Bibliotheque([
                'identifiant' => config('babel.identifiant'), 'nom' => config('babel.nom'), 'est_locale' => true,
            ]));
        }

        return response()->json(['elements' => BibliothequeReseauResource::collection($bibliotheques)->resolve()]);
    }

    /** GET /app/bibliotheques/{identifiant}/collections */
    public function collections(string $identifiant, ListeCollectionsRequest $requete, ExplorationDuReseau $reseau, CollectionsPubliques $locales): JsonResponse
    {
        $bibliotheque = $reseau->bibliotheque($identifiant);

        if ($bibliotheque->est_locale) {
            $lot = $locales->lister($requete->lot(), $requete->taille());

            return response()->json([
                'lot' => $lot->currentPage(),
                'taille' => $lot->perPage(),
                'total' => $lot->total(),
                'elements' => ResumeCollectionResource::collection($lot->items())->resolve(),
            ]);
        }

        return response()->json($reseau->collections($bibliotheque, $requete->lot(), $requete->taille()));
    }

    /** GET /app/bibliotheques/{identifiant}/collections/{id} */
    public function collection(string $identifiant, string $id, ExplorationDuReseau $reseau, CollectionsPubliques $locales): JsonResponse
    {
        $bibliotheque = $reseau->bibliotheque($identifiant);

        if ($bibliotheque->est_locale) {
            $collection = $locales->trouver($id)
                ?? throw ErreurApi::nonTrouve("Cette ressource n'existe pas ou n'est pas visible.", 'id');

            return response()->json((new CollectionResource($collection))->resolve());
        }

        return response()->json($reseau->collection($bibliotheque, $id));
    }

    /** GET /app/bibliotheques/{identifiant}/pages-populaires */
    public function pagesPopulaires(string $identifiant, PagesPopulairesRequest $requete, ExplorationDuReseau $reseau, PagesPopulaires $locales): JsonResponse
    {
        $bibliotheque = $reseau->bibliotheque($identifiant);

        if ($bibliotheque->est_locale) {
            return response()->json([
                'elements' => PagePopulaireResource::collection($locales->lister($requete->taille()))->resolve(),
            ]);
        }

        return response()->json($reseau->pagesPopulaires($bibliotheque, $requete->taille()));
    }
}
