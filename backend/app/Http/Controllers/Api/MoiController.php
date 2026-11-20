<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ModificationProfilRequest;
use App\Http\Requests\Api\SuppressionCompteRequest;
use App\Http\Resources\Api\UtilisateurResource;
use App\Services\Compte\ServiceCompte;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** GET, PATCH, DELETE /app/moi (M1, M9, M10) : le profil du membre connecté. */
class MoiController extends Controller
{
    public function __construct(private readonly ServiceCompte $comptes) {}

    public function show(Request $request): UtilisateurResource
    {
        return new UtilisateurResource($request->user());
    }

    public function update(ModificationProfilRequest $request): UtilisateurResource
    {
        return new UtilisateurResource(
            $this->comptes->modifier($request->user(), $request->validated())
        );
    }

    public function destroy(SuppressionCompteRequest $request): Response
    {
        $this->comptes->supprimer($request->user(), $request->string('mot_de_passe')->toString());

        return response()->noContent();
    }
}
