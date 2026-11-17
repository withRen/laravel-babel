<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\PagesPopulairesRequest;
use App\Http\Resources\Api\PagePopulaireResource;
use App\Services\Reseau\PagesPopulaires;
use Illuminate\Http\JsonResponse;

/** GET /pages-populaires (B4) : les pages les mieux classées, par score décroissant. */
class PagePopulaireController extends Controller
{
    public function __invoke(PagesPopulairesRequest $requete, PagesPopulaires $pages): JsonResponse
    {
        return response()->json([
            'elements' => PagePopulaireResource::collection($pages->lister($requete->taille()))->resolve(),
        ]);
    }
}
