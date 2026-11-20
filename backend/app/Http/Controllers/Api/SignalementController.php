<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\DemandeSignalementRequest;
use App\Http\Resources\Api\SignalementResource;
use App\Services\Signalements\ServiceSignalements;
use Illuminate\Http\JsonResponse;

/** POST /app/signalements (V17) : signaler une annotation publique, une collection publique ou un lien court. */
class SignalementController extends Controller
{
    public function store(DemandeSignalementRequest $requete, ServiceSignalements $signalements): JsonResponse
    {
        ['signalement' => $signalement, 'cree' => $cree] = $signalements->signaler(
            $requete->type(), $requete->idCible(), $requete->motif(), $requete->user(),
        );

        // 201 : nouveau signalement. 200 : ce membre avait déjà signalé ce contenu, voici le sien (pas de doublon).
        return (new SignalementResource($signalement))->response()->setStatusCode($cree ? 201 : 200);
    }
}
