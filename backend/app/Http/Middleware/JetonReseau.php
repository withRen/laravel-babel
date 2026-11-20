<?php

namespace App\Http\Middleware;

use App\Exceptions\ErreurApi;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Réserve une route aux bibliothèques du réseau : l'en-tête « Authorization: Bearer <jeton réseau> »
 * doit contenir le jeton commun remis par la plateforme (config('babel.network_token'), jamais en base).
 *
 * - comparaison en temps constant (hash_equals) : la durée de la comparaison ne dit rien sur le jeton ;
 * - jeton absent, mal formé ou faux : une seule et même réponse 401, sans dire lequel ;
 * - si le serveur n'a PAS de jeton configuré, personne n'entre (sinon « Bearer » vide serait accepté) ;
 * - la réponse porte « Cache-Control: no-store » : la liste ne doit jamais être gardée par un cache.
 */
class JetonReseau
{
    public function handle(Request $request, Closure $next): Response
    {
        $attendu = config('babel.network_token');
        $fourni = $request->bearerToken();

        if (! is_string($attendu) || $attendu === '' || ! is_string($fourni) || ! hash_equals($attendu, $fourni)) {
            throw new ErreurApi(
                401,
                [['code' => 'ERR_INVALID_NETWORK_TOKEN', 'champ' => null, 'message' => 'Cette route est réservée aux bibliothèques du réseau.']],
                ['WWW-Authenticate' => 'Bearer'],
            );
        }

        $reponse = $next($request);
        $reponse->headers->set('Cache-Control', 'no-store');

        return $reponse;
    }
}
