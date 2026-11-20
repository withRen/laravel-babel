<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ErreurApi;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ListeCollectionsRequest;
use App\Http\Resources\Api\CollectionResource;
use App\Http\Resources\Api\ResumeCollectionResource;
use App\Services\Reseau\CollectionsPubliques;
use Illuminate\Http\JsonResponse;

/** GET /collections et GET /collections/{id} (B3) : ce que les membres ont rendu public. */
class CollectionController extends Controller
{
    public function index(ListeCollectionsRequest $requete, CollectionsPubliques $collections): JsonResponse
    {
        $lot = $collections->lister($requete->lot(), $requete->taille());

        return response()->json([
            'lot' => $lot->currentPage(),
            'taille' => $lot->perPage(),
            'total' => $lot->total(),
            'elements' => ResumeCollectionResource::collection($lot->items())->resolve(),
        ]);
    }

    public function show(string $id, CollectionsPubliques $collections): CollectionResource
    {
        $collection = $collections->trouver($id)
            ?? throw ErreurApi::nonTrouve("Cette ressource n'existe pas ou n'est pas visible.", 'id');

        return new CollectionResource($collection);
    }
}
