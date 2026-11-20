<?php

declare(strict_types=1);

namespace App\Services\Engine;

use Closure;
use GMP;

/**
 * F7 : auHasard(). Tire l'adresse d'une page, chaque page de la bibliothèque ayant la même chance.
 *
 * Méthode : on tire UN numéro de page uniforme entre 0 et N - 1 (N = 29^3200 pages), puis on l'écrit
 * en adresse (Bibliotheque::adresse). On ne tire pas l'hexagone, le mur... séparément : tirer un
 * hexagone « au hasard » est justement le piège (un entier de 64 bits ne couvre que les hexagones dont
 * le nom tient en une douzaine de caractères alors que presque toutes les pages sont dans des hexagones
 * de 3 000 caractères). Un numéro uniforme sur toutes les pages donne, par construction, des
 * coordonnées uniformes, et le dernier hexagone (incomplet, une seule page) sort avec sa vraie probabilité.
 *
 * Uniformité exacte : le « rejet ». On tire k bits aléatoires, où k est le plus petit nombre de bits tel
 * que 2^k ≥ N, soit un nombre uniforme sur [0, 2^k). Si le nombre est ≥ N, on le jette et on recommence.
 * Ce qui reste est exactement uniforme sur [0, N). Comme N > 2^(k-1), on garde au moins une fois sur deux :
 * deux tirages en moyenne. Un « modulo N » serait plus simple mais biaiserait légèrement vers les petits
 * numéros ; ici il n'y a aucun biais.
 *
 * Source : random_bytes() (générateur cryptographique du système). Pas de graine, donc pas de
 * tirage reproductible, et mt_rand/rand ne sont pas utilisés (prévisibles et limités à 32 ou 64 bits).
 * Les calculs sont faits en GMP, qui n'a pas de limite de taille.
 */
final class TirageAuHasard
{
    /** @var Closure(int): string */
    private readonly Closure $octets;

    private readonly int $nombreDeBits;

    /**
     * @param  (Closure(int): string)|null  $source  fournit n octets aléatoires ; null = random_bytes.
     *                                               Ne sert qu'aux tests, pour rejouer un tirage connu.
     */
    public function __construct(private readonly Bibliotheque $bibliotheque, ?Closure $source = null)
    {
        $this->octets = $source ?? random_bytes(...);
        // Nombre de bits de N - 1 : le plus petit k tel que 2^k ≥ N.
        $this->nombreDeBits = strlen(gmp_strval(gmp_sub($bibliotheque->nombreDePages, 1), 2));
    }

    public function auHasard(): string
    {
        return $this->bibliotheque->adresse($this->numeroAuHasard());
    }

    /**
     * Un numéro de page uniforme sur [0, N).
     */
    public function numeroAuHasard(): GMP
    {
        $nombreDOctets = intdiv($this->nombreDeBits + 7, 8);
        // Bits en trop dans le premier octet (poids fort) : on les met à zéro pour obtenir exactement k bits.
        $masque = 0xFF >> ($nombreDOctets * 8 - $this->nombreDeBits);

        do {
            $octets = ($this->octets)($nombreDOctets);
            $octets[0] = chr(ord($octets[0]) & $masque);
            $numero = gmp_import($octets);
        } while (gmp_cmp($numero, $this->bibliotheque->nombreDePages) >= 0);

        return $numero;
    }
}
