<?php

declare(strict_types=1);

namespace App\Services\Engine;

use Babel\Moteur\Moteur;
use GMP;

/**
 * Les dimensions de la bibliothèque et le passage adresse <-> numéro de page.
 *
 * La bibliothèque contient N = 29^3200 pages (toutes les pages possibles de 3 200 caractères
 * pris parmi 29 symboles). On numérote les pages de 0 à N - 1 dans l'ordre des adresses :
 * numéro = (((hexagone × 4 + mur) × 5 + étagère) × 32 + volume) × 410 + page (chaque
 * coordonnée comptée à partir de 0). C'est une numération mixte, comme l'heure
 * (jours, heures, minutes), avec un « chiffre » de plus fort poids, l'hexagone, qui n'a pas de borne
 * fixe : c'est un entier de plus de 3 000 chiffres en base 36, d'où GMP.
 *
 * Les constantes viennent toutes du moteur (Moteur::MURS, ETAGERES, VOLUMES, PAGES,
 * LONGUEUR_PAGE, ALPHABET) : aucune valeur n'est recopiée ici, donc rien ne peut diverger.
 * Cette classe ne dépend pas de la clé : elle ne fait que de l'arithmétique sur les adresses.
 */
final class Bibliotheque
{
    /** Nombre de pages par hexagone : 4 murs × 5 étagères × 32 volumes × 410 pages = 262 400. */
    public readonly int $pagesParHexagone;

    /** N = 29^3200, le nombre total de pages. */
    public readonly GMP $nombreDePages;

    /** Numéro du dernier hexagone : (N - 1) div 262 400. */
    public readonly GMP $dernierHexagone;

    public function __construct()
    {
        $this->pagesParHexagone = Moteur::MURS * Moteur::ETAGERES * Moteur::VOLUMES * Moteur::PAGES;
        $this->nombreDePages = gmp_pow(strlen(Moteur::ALPHABET), Moteur::LONGUEUR_PAGE);
        $this->dernierHexagone = gmp_div_q(gmp_sub($this->nombreDePages, 1), $this->pagesParHexagone);
    }

    /**
     * Numéro de la page, de 0 à N - 1 (ou au-delà si l'adresse n'existe pas : c'est à l'appelant
     * de comparer avec $nombreDePages).
     *
     * Les coordonnées partent de 1 comme dans l'adresse (mur 1 à 4, etc.).
     */
    public function numero(GMP $hexagone, int $mur, int $etagere, int $volume, int $page): GMP
    {
        $numero = gmp_add(gmp_mul($hexagone, Moteur::MURS), $mur - 1);
        $numero = gmp_add(gmp_mul($numero, Moteur::ETAGERES), $etagere - 1);
        $numero = gmp_add(gmp_mul($numero, Moteur::VOLUMES), $volume - 1);

        return gmp_add(gmp_mul($numero, Moteur::PAGES), $page - 1);
    }

    /**
     * L'écriture canonique d'un numéro de page : l'unique adresse valide de cette page.
     *
     * @param  GMP  $numero  entre 0 et N - 1
     */
    public function adresse(GMP $numero): string
    {
        [$reste, $page] = $this->diviser($numero, Moteur::PAGES);
        [$reste, $volume] = $this->diviser($reste, Moteur::VOLUMES);
        [$reste, $etagere] = $this->diviser($reste, Moteur::ETAGERES);
        [$hexagone, $mur] = $this->diviser($reste, Moteur::MURS);

        return sprintf('%s-w%d-s%d-v%d:%d', gmp_strval($hexagone, 36), $mur + 1, $etagere + 1, $volume + 1, $page + 1);
    }

    /**
     * @return array{GMP, int} le quotient et le reste
     */
    private function diviser(GMP $nombre, int $diviseur): array
    {
        return [gmp_div_q($nombre, $diviseur), gmp_intval(gmp_div_r($nombre, $diviseur))];
    }
}
