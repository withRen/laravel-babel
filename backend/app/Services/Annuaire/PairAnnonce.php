<?php

declare(strict_types=1);

namespace App\Services\Annuaire;

/** Une ligne de la liste de l'annuaire : l'identifiant stable d'une bibliothèque et son URL du moment. */
final readonly class PairAnnonce
{
    public function __construct(
        public string $identifiant,
        public string $url,
        public ?string $nom = null,
    ) {}
}
