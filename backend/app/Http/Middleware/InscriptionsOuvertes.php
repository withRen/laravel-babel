<?php

namespace App\Http\Middleware;

use App\Exceptions\ErreurApi;
use App\Models\Reglage;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A8 : refuse l'inscription (403 ERR_REGISTRATION_CLOSED) quand l'administrateur a fermé les inscriptions
 * (réglage « inscriptions_ouvertes »). Placé en middleware pour répondre 403 AVANT la validation du corps :
 * une bibliothèque fermée ne dit pas « votre email est invalide », elle dit « fermé ».
 */
class InscriptionsOuvertes
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! Reglage::inscriptionsOuvertes()) {
            throw ErreurApi::inscriptionsFermees();
        }

        return $next($request);
    }
}
