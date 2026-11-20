<?php

namespace App\Http\Controllers\Api;

use App\Enums\StatutTexteBanni;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\TexteBanniResource;
use App\Models\TexteBanni;
use Illuminate\Http\JsonResponse;

/**
 * GET /textes-bannis (A6, B5), protégée par le jeton réseau (middleware jeton.reseau).
 * Seuls les textes bannis ICI (origine locale) et appliqués sont publiés : un texte relevé chez un pair
 * n'est jamais republié, et un bannissement levé (« rejete ») disparaît.
 */
class TexteBanniController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $textes = TexteBanni::query()
            ->where('statut', StatutTexteBanni::Applique)
            ->whereHas('origine', fn ($b) => $b->where('est_locale', true))
            ->orderBy('id')
            ->get();

        return response()->json(['elements' => TexteBanniResource::collection($textes)->resolve()]);
    }
}
