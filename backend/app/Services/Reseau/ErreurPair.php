<?php

declare(strict_types=1);

namespace App\Services\Reseau;

use App\Exceptions\ErreurApi;
use RuntimeException;

/**
 * Un appel à un pair a échoué. Deux cas seulement, qui n'ont pas le même poids (livrable 4, choix 8) :
 *
 *  - injoignable : éteint, plus de 2 secondes, erreur HTTP ou réponse hors contrat. Le compteur d'échecs du pair augmente ;
 *  - occupé : il a répondu 429. Ce n'est pas une panne : son compteur ne bouge pas.
 */
final class ErreurPair extends RuntimeException
{
    private function __construct(
        public readonly string $bibliotheque,
        public readonly bool $occupe,
        public readonly ?int $reessayerApres = null,
        string $message = '',
    ) {
        parent::__construct($message);
    }

    public static function injoignable(string $bibliotheque, string $raison): self
    {
        return new self($bibliotheque, false, null, $raison);
    }

    public static function occupe(string $bibliotheque, ?int $reessayerApres): self
    {
        return new self($bibliotheque, true, $reessayerApres, 'Le pair est occupé (429).');
    }

    /** La réponse du contrat qu'on renvoie à l'application web : 502 ou 503. */
    public function versErreurApi(): ErreurApi
    {
        return $this->occupe
            ? ErreurApi::pairOccupe($this->bibliotheque, $this->reessayerApres)
            : ErreurApi::pairInjoignable($this->bibliotheque);
    }
}
