<?php

declare(strict_types=1);

namespace App\Services\Engine;

use Babel\Moteur\Erreur\CodeErreur;
use Babel\Moteur\Erreur\Erreur;
use Babel\Moteur\Moteur;

/**
 * F5 : valider(adresse). Dit si une adresse est bien écrite ET existe.
 *
 *  - ne lève jamais d'exception : un refus est un résultat normal (ResultatValidation), pas un cas exceptionnel ;
 *  - renvoie toutes les erreurs d'un coup (l'interface peut surligner chaque champ fautif) ;
 *  - refuse sans corriger : « w04 », « W4 » ou un hexagone en majuscules sont des erreurs.
 *    Ainsi une page n'a qu'une seule écriture, et deux marque-pages sur la même page ont
 *    exactement la même adresse ;
 *  - une adresse de page valide est toujours lisible par Moteur::lire().
 *
 * Les erreurs et leurs messages sont ceux du moteur (Erreur, CodeErreur), avec les mêmes champs et le même
 * ordre (hexagone, mur, etagere, volume, page) : le contrat d'erreur ne change pas selon qu'on valide
 * avant ou qu'on laisse lire() refuser.
 *
 * Deux niveaux : page (<hexagone>-w<mur>-s<étagère>-v<volume>:<page>) et livre (la même chose
 * sans « :<page> »).
 */
final class Valideur
{
    /** Forme générale : tout ce qui n'a pas cette forme est « adresse » mal écrite, sans autre détail. */
    private const DECOUPAGE = '/\A([^-]*)-w([^-]*)-s([^-]*)-v([^:]*)(?::(.*))?\z/s';

    public function __construct(private readonly Bibliotheque $bibliotheque) {}

    public function valider(mixed $adresse): ResultatValidation
    {
        // L'entrée est censée être une chaîne ; si ce n'est pas le cas on ne lève rien non plus.
        if (! is_string($adresse) || preg_match(self::DECOUPAGE, $adresse, $m, PREG_UNMATCHED_AS_NULL) !== 1) {
            // preg_match peut aussi renvoyer false (limite PCRE) : on le traite comme une adresse mal écrite.
            return ResultatValidation::refusee([new Erreur(CodeErreur::AdresseMalEcrite, 'adresse')]);
        }

        $erreurs = [];
        $hexagone = $this->hexagone((string) $m[1], $erreurs);
        $mur = $this->champ('mur', $m[2], Moteur::MURS, $erreurs);
        $etagere = $this->champ('etagere', $m[3], Moteur::ETAGERES, $erreurs);
        $volume = $this->champ('volume', $m[4], Moteur::VOLUMES, $erreurs);

        // Sans « : », c'est l'adresse d'un livre. Avec « : », la page est obligatoire et contrôlée.
        $niveau = $m[5] === null ? ResultatValidation::NIVEAU_LIVRE : ResultatValidation::NIVEAU_PAGE;
        $page = $niveau === ResultatValidation::NIVEAU_PAGE
            ? $this->champ('page', $m[5], Moteur::PAGES, $erreurs)
            : 1;

        if ($erreurs === [] && $hexagone !== null && $mur !== null && $etagere !== null && $volume !== null && $page !== null) {
            // Tous les champs sont bien écrits et dans leurs limites : reste le dernier hexagone, qui est
            // incomplet. Un livre existe si sa première page existe (la page 1 est la première du livre).
            $numero = $this->bibliotheque->numero($hexagone, $mur, $etagere, $volume, $page);
            if (gmp_cmp($numero, $this->bibliotheque->nombreDePages) >= 0) {
                $erreurs[] = new Erreur(CodeErreur::HorsLimites, 'adresse');
            }
        }

        return $erreurs === [] ? ResultatValidation::valide($niveau) : ResultatValidation::refusee($erreurs);
    }

    /**
     * L'hexagone : nombre en base 36 (0-9, a-z minuscules), sans zéro de tête, au plus égal au dernier.
     * Au plus une erreur, la première qui s'applique.
     *
     * @param  list<Erreur>  $erreurs
     */
    private function hexagone(string $brut, array &$erreurs): ?\GMP
    {
        if ($brut === '') {
            $erreurs[] = new Erreur(CodeErreur::AdresseMalEcrite, 'hexagone');
        } elseif (preg_match('/\A[0-9a-z]+\z/', $brut) !== 1) {
            // Les majuscules tombent ici : « A » n'est pas « a ».
            $erreurs[] = new Erreur(CodeErreur::HexagoneInvalide, 'hexagone');
        } elseif (strlen($brut) > 1 && $brut[0] === '0') {
            $erreurs[] = new Erreur(CodeErreur::AdresseMalEcrite, 'hexagone');
        } elseif (strlen($brut) > Moteur::LONGUEUR_MAX_HEXAGONE) {
            // Trop long pour exister : refusé AVANT toute conversion en grand nombre, pour qu'une entrée
            // énorme ne coûte aucun calcul.
            $erreurs[] = new Erreur(CodeErreur::HorsLimites, 'hexagone');
        } else {
            $hexagone = gmp_init($brut, 36);
            if (gmp_cmp($hexagone, $this->bibliotheque->dernierHexagone) <= 0) {
                return $hexagone;
            }
            $erreurs[] = new Erreur(CodeErreur::HorsLimites, 'hexagone');
        }

        return null;
    }

    /**
     * Un champ décimal (mur, étagère, volume, page) : pas de signe, pas de zéro de tête, entre 1 et le maximum.
     * Au plus une erreur, la première qui s'applique.
     *
     * @param  list<Erreur>  $erreurs
     */
    private function champ(string $nom, ?string $valeur, int $maximum, array &$erreurs): ?int
    {
        if ($valeur === null || preg_match('/\A(0|[1-9][0-9]*)\z/', $valeur) !== 1) {
            $erreurs[] = new Erreur(CodeErreur::AdresseMalEcrite, $nom);

            return null;
        }
        // La longueur est contrôlée avant la conversion en entier : « 99999999999999999999 » ne déborde pas.
        if (strlen($valeur) > 3 || (int) $valeur < 1 || (int) $valeur > $maximum) {
            $erreurs[] = new Erreur(CodeErreur::HorsLimites, $nom);

            return null;
        }

        return (int) $valeur;
    }
}
