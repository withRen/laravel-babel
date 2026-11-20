<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pour les routes ouvertes aux visiteurs qui « tiennent compte du membre » (contrat, règle 4) :
 * lire une page montre son vote et son marque-page à un membre, rien à un visiteur.
 *
 * - pas de jeton : visiteur, la requête continue (auth:sanctum, lui, répondrait 401) ;
 * - jeton valide : le membre est reconnu, $request->user() le renvoie ;
 * - jeton faux, expiré, révoqué, ou compte suspendu : traité comme un visiteur. Une route publique
 *   ne doit pas casser parce que l'application web garde un vieux jeton ; elle lira simplement
 *   sans les données du membre. (Les routes « membre » restent protégées par auth:sanctum.)
 */
class AuthentificationOptionnelle
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->bearerToken() !== null) {
            $membre = Auth::guard('sanctum')->user();

            if ($membre !== null && ! $membre->estSuspendu()) {
                Auth::shouldUse('sanctum');
                $request->setUserResolver(fn () => $membre);
            }
        }

        return $next($request);
    }
}
