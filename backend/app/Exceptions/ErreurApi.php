<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Une erreur « du contrat » : un statut HTTP et une liste d'erreurs {code, champ, message}.
 *
 * Les contrôleurs la lancent (throw ErreurApi::nonTrouve()) au lieu de fabriquer à la main
 * un JSON d'erreur : la forme {"erreurs": [...]} est ainsi écrite à un seul endroit
 * (RenduErreursApi) et ne peut pas diverger d'une route à l'autre.
 */
class ErreurApi extends RuntimeException
{
    /**
     * @param  list<array{code: string, champ: string|null, message: string}>  $erreurs
     * @param  array<string, string>  $entetes
     */
    public function __construct(
        public readonly int $statut,
        public readonly array $erreurs,
        public readonly array $entetes = [],
    ) {
        parent::__construct($erreurs[0]['message'] ?? 'Erreur');
    }

    public static function une(int $statut, string $code, string $message, ?string $champ = null): self
    {
        return new self($statut, [['code' => $code, 'champ' => $champ, 'message' => $message]]);
    }

    /** Ressource inconnue, privée, masquée, ou appartenant à un autre membre (jamais 403). */
    public static function nonTrouve(string $message = 'Ressource introuvable.', ?string $champ = null): self
    {
        return self::une(404, 'ERR_NOT_FOUND', $message, $champ);
    }

    public static function nonConnecte(string $message = 'Connectez-vous pour continuer.'): self
    {
        return self::une(401, 'ERR_UNAUTHENTICATED', $message);
    }

    public static function jetonReseauInvalide(): self
    {
        return self::une(401, 'ERR_INVALID_NETWORK_TOKEN', 'Le jeton réseau est absent ou invalide.');
    }

    public static function trop(int $secondes): self
    {
        return new self(
            429,
            [['code' => 'ERR_TOO_MANY_REQUESTS', 'champ' => null, 'message' => "Trop de requêtes, réessayez dans {$secondes} secondes."]],
            ['Retry-After' => (string) $secondes],
        );
    }
}
