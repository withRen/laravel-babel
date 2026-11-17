<?php

declare(strict_types=1);

namespace App\Services\Engine;

use Babel\Moteur\Erreur\Erreur;

/**
 * Ce que renvoie Valideur::valider : « valide » avec un niveau, ou la liste de toutes les erreurs.
 *
 * Une adresse valide a toujours un niveau et aucune erreur ; une adresse refusée n'a pas de niveau et
 * au moins une erreur. Les deux états ne peuvent pas se mélanger : le constructeur est privé.
 */
final readonly class ResultatValidation
{
    public const NIVEAU_PAGE = 'page';

    public const NIVEAU_LIVRE = 'livre';

    /**
     * @param  list<Erreur>  $erreurs
     */
    private function __construct(
        public bool $valide,
        public ?string $niveau,
        public array $erreurs,
    ) {}

    public static function valide(string $niveau): self
    {
        return new self(true, $niveau, []);
    }

    /**
     * @param  non-empty-list<Erreur>  $erreurs
     */
    public static function refusee(array $erreurs): self
    {
        return new self(false, null, $erreurs);
    }

    /**
     * Les erreurs au format du contrat {code, champ, message}, celui du moteur (Erreur::versTableau).
     *
     * @return list<array{code: string, champ: string, message: string}>
     */
    public function erreursVersTableau(): array
    {
        return array_map(static fn (Erreur $e): array => $e->versTableau(), $this->erreurs);
    }
}
