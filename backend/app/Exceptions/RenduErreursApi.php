<?php

namespace App\Exceptions;

use Babel\Moteur\Erreur\ErreurMoteur;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Throwable;

/**
 * Traduit toutes les exceptions de l'API dans la forme d'erreur du contrat :
 * {"erreurs": [{"code": "ERR_…", "champ": "…"|null, "message": "…"}]}.
 *
 * Ne touche qu'aux routes /api/* : le back-office Filament (/admin) garde son rendu HTML.
 */
final class RenduErreursApi
{
    public static function enregistrer(Exceptions $exceptions): void
    {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Les erreurs « du contrat » (401, 404, 422…) sont des réponses normales, pas des incidents : sans cela
        // Laravel écrirait une entrée « error » avec la trace dans le journal à chaque adresse refusée d'un pair.
        $exceptions->dontReport([ErreurApi::class, ErreurMoteur::class]);

        // Un rendu qui répond null laisse la main au rendu suivant (puis au rendu par défaut).
        $exceptions->render(fn (Throwable $e, Request $request) => self::rendre($e, $request));
    }

    public static function rendre(Throwable $e, Request $request): ?JsonResponse
    {
        if (! $request->is('api/*')) {
            return null;
        }

        return match (true) {
            $e instanceof ErreurApi => self::reponse($e->statut, $e->erreurs, $e->entetes),
            $e instanceof ErreurMoteur => self::depuisMoteur($e),
            $e instanceof ValidationException => self::depuisValidation($e),
            $e instanceof AuthenticationException => self::erreur(401, 'ERR_UNAUTHENTICATED', 'Connectez-vous pour continuer.'),
            // La ressource d'un autre membre répond 404 et non 403 : on ne laisse rien deviner.
            $e instanceof AuthorizationException,
            $e instanceof AccessDeniedHttpException,
            $e instanceof ModelNotFoundException,
            $e instanceof NotFoundHttpException => self::erreur(404, 'ERR_NOT_FOUND', 'Ressource introuvable.'),
            $e instanceof TooManyRequestsHttpException => self::depuisQuota($e),
            default => null, // CleInvalide, bugs : 500 par défaut, on ne les déguise pas.
        };
    }

    /**
     * 404 seulement si TOUTES les erreurs sont ERR_OUT_OF_BOUNDS (l'adresse est bien écrite,
     * la page n'existe pas) ; sinon 422 (au moins une erreur de forme).
     */
    private static function depuisMoteur(ErreurMoteur $e): JsonResponse
    {
        // versTableau() renvoie la liste des erreurs ; l'enveloppe {"erreurs": …} est ajoutée par reponse().
        $erreurs = $e->versTableau();
        $codes = array_column($erreurs, 'code');
        $seulementHorsLimites = $codes !== [] && array_unique($codes) === ['ERR_OUT_OF_BOUNDS'];

        return self::reponse($seulementHorsLimites ? 404 : 422, $erreurs);
    }

    /** Une erreur ERR_INVALID_PARAMETER par champ, toutes renvoyées d'un coup. */
    private static function depuisValidation(ValidationException $e): JsonResponse
    {
        $erreurs = [];
        foreach ($e->errors() as $champ => $messages) {
            // « adresses.3 » → « adresses » : le contrat nomme le champ du corps, pas l'indice.
            $champ = explode('.', (string) $champ)[0];
            $erreurs[$champ] ??= [
                'code' => 'ERR_INVALID_PARAMETER',
                'champ' => $champ,
                'message' => $messages[0],
            ];
        }

        return self::reponse(422, array_values($erreurs));
    }

    /** Le throttle de Laravel lève TooManyRequestsHttpException avec l'en-tête Retry-After. */
    private static function depuisQuota(TooManyRequestsHttpException $e): JsonResponse
    {
        $secondes = (int) ($e->getHeaders()['Retry-After'] ?? 60);

        return self::reponse(
            429,
            [['code' => 'ERR_TOO_MANY_REQUESTS', 'champ' => null, 'message' => "Trop de requêtes, réessayez dans {$secondes} secondes."]],
            ['Retry-After' => (string) $secondes],
        );
    }

    private static function erreur(int $statut, string $code, string $message): JsonResponse
    {
        return self::reponse($statut, [['code' => $code, 'champ' => null, 'message' => $message]]);
    }

    /** @param list<array{code: string, champ: string|null, message: string}> $erreurs */
    private static function reponse(int $statut, array $erreurs, array $entetes = []): JsonResponse
    {
        return new JsonResponse(['erreurs' => $erreurs], $statut, $entetes);
    }
}
