<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ListePagineeRequest;
use App\Http\Resources\Api\ListePaginee;
use App\Http\Resources\Api\RechercheEnregistreeResource;
use App\Services\Membre\Possession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * /app/moi/recherches (M8) : l'historique des recherches du membre. Les lignes sont écrites par les routes de
 * recherche ; ici on les lit et on les efface. Les recherches des visiteurs ne laissent qu'un compteur (règle 12).
 */
class HistoriqueController extends Controller
{
    public function index(ListePagineeRequest $requete): JsonResponse
    {
        $recherches = $requete->user()->recherches()->orderByDesc('cree_le')->orderByDesc('id');

        return response()->json(ListePaginee::de(
            $recherches->paginate($requete->taille(), ['*'], 'lot', $requete->lot()),
            RechercheEnregistreeResource::class,
        ));
    }

    public function viderTout(Request $requete): Response
    {
        $requete->user()->recherches()->delete();

        return response()->noContent();
    }

    public function destroy(Request $requete, string $id): Response
    {
        Possession::trouver($requete->user()->recherches(), $id)->delete();

        return response()->noContent();
    }
}
