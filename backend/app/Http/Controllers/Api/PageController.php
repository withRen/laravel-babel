<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Bibliotheque;
use App\Services\Reseau\LecteurDePages;
use Illuminate\Http\JsonResponse;

/** GET /pages/{adresse} (F1, B1) : le texte de la page, toujours lisible, même s'il est banni. */
class PageController extends Controller
{
    public function __invoke(string $adresse, LecteurDePages $lecteur): JsonResponse
    {
        // Une adresse refusée lève ErreurMoteur : 422, ou 404 si l'adresse est bien écrite mais n'existe pas.
        $texte = $lecteur->lire($adresse);

        return response()->json([
            'bibliotheque' => Bibliotheque::locale()?->identifiant ?? config('babel.identifiant'),
            'adresse' => $adresse, // telle que reçue, à l'octet près
            'texte' => $texte,
        ]);
    }
}
