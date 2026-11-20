<?php

declare(strict_types=1);

namespace App\Services\Engine;

use Babel\Moteur\Moteur;
use Normalizer;

/**
 * F3 : normaliser(texte). Convertit n'importe quelle chaîne en texte cherchable (29 symboles).
 *
 * La règle est celle du contrat commun à toutes les bibliothèques (docs/cahier-des-charges.md,
 * fiche F3) : si une bibliothèque normalisait autrement, la même phrase donnerait un autre texte
 * cherché, donc une autre adresse. Chaque étape est donc écrite sans interprétation :
 *
 *  1. minuscules ;
 *  2. accents et cédille retirés, lettres liées développées (œ → oe, æ → ae) ;
 *  3. tout caractère hors des 29 symboles devient une espace (saut de ligne compris) ;
 *  4. les espaces qui se suivent n'en font plus qu'une ;
 *  5. espaces du début et de la fin retirées.
 *
 * Le mode « page » s'arrête à l'étape 3 : une page lue contient déjà des espaces doubles ou en
 * bordure, et les réduire en ferait un autre texte (donc une autre adresse).
 *
 * La fonction ne lève jamais d'exception : elle accepte tout, y compris une chaîne vide ou
 * un texte qui n'est pas de l'UTF-8 valide. Un résultat vide ou trop long est refusé ensuite
 * par le moteur (ERR_EMPTY_QUERY, ERR_TEXT_TOO_LONG).
 */
final class Normaliseur
{
    /**
     * @param  bool  $etapesDePage  true : étapes 1 à 3 seulement (mode « page » du moteur)
     */
    public function normaliser(string $texte, bool $etapesDePage = false): string
    {
        // Entrée quelconque : on remplace les octets qui ne sont pas de l'UTF-8 par « ? »,
        // qui sortira de l'alphabet à l'étape 3. Sans cela preg_* renverrait null.
        $texte = mb_scrub($texte, 'UTF-8');

        // 1. minuscules
        $texte = mb_strtolower($texte, 'UTF-8');

        // 2. accents : décomposition NFD (é → e + accent), puis on retire les signes combinants.
        //    Les lettres liées ne se décomposent pas : on les développe à la main.
        $decompose = Normalizer::normalize($texte, Normalizer::FORM_D);
        $texte = strtr($decompose === false ? $texte : $decompose, ['œ' => 'oe', 'æ' => 'ae']);
        $texte = (string) preg_replace('/\p{Mn}+/u', '', $texte);

        // 3. tout ce qui n'est pas un des 29 symboles devient une espace. Les symboles sont
        //    pris dans le moteur, pas recopiés ; preg_quote protège la virgule, le point et l'espace.
        $horsAlphabet = '/[^'.preg_quote(Moteur::ALPHABET, '/').']/u';
        $texte = (string) preg_replace($horsAlphabet, ' ', $texte);

        if ($etapesDePage) {
            return $texte;
        }

        // 4. une seule espace à la suite, puis 5. plus d'espace au début ni à la fin
        return trim((string) preg_replace('/ {2,}/', ' ', $texte), ' ');
    }
}
