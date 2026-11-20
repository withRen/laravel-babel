<?php

namespace App\Http\Middleware;

use App\Exceptions\ErreurApi;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuse un compte suspendu (A2) : 403 ERR_ACCOUNT_SUSPENDED.
 *
 * À placer APRÈS auth:sanctum. Un membre suspendu après sa connexion garde un jeton valide :
 * on doit donc le refuser à chaque appel, pas seulement à la connexion. L'authentification dit
 * « qui es-tu ? » (401 sinon), ici on décide « as-tu encore le droit d'entrer ? » (403).
 */
class CompteActif
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->estSuspendu()) {
            throw ErreurApi::compteSuspendu();
        }

        return $next($request);
    }
}
