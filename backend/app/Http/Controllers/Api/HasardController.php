<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Bibliotheque;
use App\Services\Engine\TirageAuHasard;
use Illuminate\Http\JsonResponse;

/** GET /app/hasard (F7, V9) : une adresse de page tirée au hasard, chaque page ayant la même chance. */
class HasardController extends Controller
{
    public function __invoke(TirageAuHasard $tirage): JsonResponse
    {
        return response()
            ->json([
                'bibliotheque' => Bibliotheque::locale()?->identifiant ?? config('babel.identifiant'),
                'adresse' => $tirage->auHasard(),
            ])
            // Un tirage n'est pas reproductible : aucun cache (navigateur ou intermédiaire) ne doit le resservir.
            ->header('Cache-Control', 'no-store');
    }
}
