<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ConnexionRequest;
use App\Http\Requests\Api\InscriptionRequest;
use App\Http\Resources\Api\SessionResource;
use App\Services\Compte\ServiceCompte;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Laravel\Sanctum\PersonalAccessToken;

/** POST /app/auth/inscription, /connexion, /deconnexion (V19, M1). */
class AuthController extends Controller
{
    public function __construct(private readonly ServiceCompte $comptes) {}

    public function inscrire(InscriptionRequest $request): JsonResponse
    {
        $session = $this->comptes->inscrire(
            $request->string('pseudo')->toString(),
            $request->string('email')->toString(),
            $request->string('mot_de_passe')->toString(),
        );

        return (new SessionResource($session))->response()->setStatusCode(201);
    }

    public function connecter(ConnexionRequest $request): SessionResource
    {
        return new SessionResource($this->comptes->connecter(
            $request->string('email')->toString(),
            $request->string('mot_de_passe')->toString(),
        ));
    }

    /** Révoque le jeton utilisé pour CET appel : les autres sessions du membre (autre appareil) restent ouvertes. */
    public function deconnecter(Request $request): Response
    {
        $jeton = $request->user()->currentAccessToken();

        if ($jeton instanceof PersonalAccessToken) {
            $jeton->delete();
        }

        return response()->noContent();
    }
}
