<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ErreurApi;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\DemandeLienCourtRequest;
use App\Http\Requests\Api\ListeCollectionsRequest;
use App\Http\Resources\Api\CollectionResource;
use App\Http\Resources\Api\LienCourtResource;
use App\Models\LienCourt;
use App\Services\LiensCourts\ServiceLiensCourts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Liens courts : créer (V11), ouvrir (V12), lister et supprimer les siens. */
class LienCourtController extends Controller
{
    /** POST /app/liens-courts : ouvert aux visiteurs (le jeton, s'il est valide, donne un lien « de membre »). */
    public function store(DemandeLienCourtRequest $requete, ServiceLiensCourts $liens): JsonResponse
    {
        $lien = $liens->creer($requete->cible(), $requete->passage(), $requete->expireLe(), $requete->user());

        return (new LienCourtResource($lien))->response()->setStatusCode(201);
    }

    /** GET /app/liens-courts/{slug} : résout le lien et compte une visite. */
    public function show(string $slug, ServiceLiensCourts $liens): JsonResponse
    {
        ['lien' => $lien, 'collection' => $collection] = $liens->ouvrir($slug);

        $cible = $collection !== null
            ? ['type' => 'collection', 'collection' => (new CollectionResource($collection))->resolve()]
            : LienCourtResource::cible($lien) + [
                // La page à ouvrir : la page elle-même, ou la page 1 du livre (règle 4).
                'adresse_a_ouvrir' => $lien->adresse->estLivre() ? $lien->adresse->ecriture().':1' : $lien->adresse->ecriture(),
            ];

        return response()->json([
            'slug' => $lien->slug,
            'cible' => $cible,
            'passage' => $lien->passage,
            'expire_le' => $lien->expire_le?->toIso8601String(),
        ]);
    }

    /** GET /app/moi/liens-courts : mes liens, du plus récent au plus ancien, avec leurs visites. */
    public function index(ListeCollectionsRequest $requete): JsonResponse
    {
        $lot = LienCourt::query()
            ->with('adresse.bibliotheque')
            ->where('utilisateur_id', $requete->user()->id)
            ->orderByDesc('cree_le')
            ->orderByDesc('id') // deux liens créés dans la même seconde : l'id départage
            ->paginate($requete->taille(), ['*'], 'lot', $requete->lot());

        return response()->json([
            'lot' => $lot->currentPage(),
            'taille' => $lot->perPage(),
            'total' => $lot->total(),
            'elements' => LienCourtResource::collection($lot->items())->resolve(),
        ]);
    }

    /** DELETE /app/moi/liens-courts/{slug} : le lien d'un autre membre répond 404, comme un lien inconnu. */
    public function destroy(string $slug, Request $requete): Response
    {
        $lien = LienCourt::query()
            ->where('slug', $slug)
            ->where('utilisateur_id', $requete->user()->id)
            ->first() ?? throw ErreurApi::nonTrouve("Ce lien n'existe pas.", 'slug');

        $lien->delete(); // ses signalements partent avec lui (clé étrangère en cascade, règle 13)

        return response()->noContent();
    }
}
