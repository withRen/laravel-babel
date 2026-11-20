<?php

use App\Services\Engine\Bibliotheque;
use App\Services\Engine\TirageAuHasard;
use App\Services\Engine\Valideur;
use Babel\Moteur\Moteur;

/*
 * F7 : auHasard(). Sans graine, on ne teste pas une valeur mais une distribution : sur 10 000 tirages,
 * murs, étagères, volumes et pages à parts égales ; hexagone de 3 004 caractères ≈ 79 %, 3 003 ≈ 20 %.
 *
 * Les seuils ci-dessous sont à ± 6 écarts-types (ou 8 pour le khi-deux) : un bon générateur ne les dépasse
 * jamais en pratique (moins d'une chance sur 10^8), un générateur biaisé les dépasse tout de suite.
 */
const TIRAGES = 10_000;

/** Les octets (n) du nombre $numero écrit sur $n octets, poids fort en premier. */
function octetsDe(GMP $numero, int $n): string
{
    return str_pad(gmp_export($numero), $n, "\0", STR_PAD_LEFT);
}

/** Khi-deux d'un comptage contre l'uniforme sur $categories. */
function khiDeux(array $comptes, int $categories, int $total): float
{
    $attendu = $total / $categories;
    $somme = 0.0;
    // Les murs, étagères, volumes et pages se comptent à partir de 1.
    for ($i = 1; $i <= $categories; $i++) {
        $somme += (($comptes[$i] ?? 0) - $attendu) ** 2 / $attendu;
    }

    return $somme;
}

/** Seuil à 8 écarts-types de la loi du khi-deux à $ddl degrés de liberté. */
function seuilKhiDeux(int $ddl): float
{
    return $ddl + 8 * sqrt(2 * $ddl);
}

/*
 * Un tirage fait ~3 000 caractères : garder 10 000 adresses coûterait ~30 Mo (et davantage une fois
 * découpées). On ne garde donc que des compteurs, calculés au fil des tirages.
 */
beforeAll(function () {
    $tirage = app(TirageAuHasard::class);
    $valideur = app(Valideur::class);
    $stats = ['mur' => [], 'etagere' => [], 'volume' => [], 'page' => [], 'longueurs' => [], 'valides' => 0, 'empreintes' => []];

    for ($i = 0; $i < TIRAGES; $i++) {
        $adresse = $tirage->auHasard();
        preg_match('/\A([0-9a-z]+)-w(\d+)-s(\d+)-v(\d+):(\d+)\z/', $adresse, $m);

        $stats['mur'][(int) $m[2]] = ($stats['mur'][(int) $m[2]] ?? 0) + 1;
        $stats['etagere'][(int) $m[3]] = ($stats['etagere'][(int) $m[3]] ?? 0) + 1;
        $stats['volume'][(int) $m[4]] = ($stats['volume'][(int) $m[4]] ?? 0) + 1;
        $stats['page'][(int) $m[5]] = ($stats['page'][(int) $m[5]] ?? 0) + 1;
        $stats['longueurs'][strlen($m[1])] = ($stats['longueurs'][strlen($m[1])] ?? 0) + 1;
        $stats['valides'] += $valideur->valider($adresse)->niveau === 'page' ? 1 : 0;
        $stats['empreintes'][md5($adresse)] = true; // 32 octets au lieu de 3 000 : suffit pour détecter un doublon
    }

    $GLOBALS['tirages_babel'] = $stats;
});

afterAll(fn () => $GLOBALS['tirages_babel'] = null);

beforeEach(function () {
    $this->stats = $GLOBALS['tirages_babel'];
});

describe('distribution sur 10 000 tirages', function () {
    it('tire 10 000 adresses de pages valides', function () {
        expect($this->stats['valides'])->toBe(TIRAGES);
    });

    it('répartit les murs à parts égales', function () {
        expect($this->stats['mur'])->toHaveCount(Moteur::MURS)
            ->and(khiDeux($this->stats['mur'], Moteur::MURS, TIRAGES))->toBeLessThan(seuilKhiDeux(Moteur::MURS - 1));
    });

    it('répartit les étagères à parts égales', function () {
        expect($this->stats['etagere'])->toHaveCount(Moteur::ETAGERES)
            ->and(khiDeux($this->stats['etagere'], Moteur::ETAGERES, TIRAGES))->toBeLessThan(seuilKhiDeux(Moteur::ETAGERES - 1));
    });

    it('répartit les volumes à parts égales', function () {
        expect(min(array_keys($this->stats['volume'])))->toBeGreaterThanOrEqual(1)
            ->and(max(array_keys($this->stats['volume'])))->toBeLessThanOrEqual(Moteur::VOLUMES)
            ->and(khiDeux($this->stats['volume'], Moteur::VOLUMES, TIRAGES))->toBeLessThan(seuilKhiDeux(Moteur::VOLUMES - 1));
    });

    it('répartit les pages à parts égales', function () {
        expect(min(array_keys($this->stats['page'])))->toBeGreaterThanOrEqual(1)
            ->and(max(array_keys($this->stats['page'])))->toBeLessThanOrEqual(Moteur::PAGES)
            ->and(khiDeux($this->stats['page'], Moteur::PAGES, TIRAGES))->toBeLessThan(seuilKhiDeux(Moteur::PAGES - 1));
    });

    it('donne un hexagone de 3 004 caractères dans ~79 %, 3 003 dans ~20 %, moins dans moins de 1 %', function () {
        $l = $this->stats['longueurs'];
        $part = fn (callable $filtre): float => array_sum(array_filter($l, $filtre, ARRAY_FILTER_USE_KEY)) / TIRAGES;

        expect($part(fn ($n) => $n === 3004))->toBeGreaterThan(0.76)->toBeLessThan(0.82)
            ->and($part(fn ($n) => $n === 3003))->toBeGreaterThan(0.17)->toBeLessThan(0.23)
            ->and($part(fn ($n) => $n < 3003))->toBeLessThan(0.01);
    });

    it('n\'a pas le piège des 64 bits : aucun hexagone court, presque tous très longs', function () {
        // Un entier aléatoire de 64 bits donnerait des hexagones de 13 caractères au plus.
        expect(min(array_keys($this->stats['longueurs'])))->toBeGreaterThan(2900);
    });

    it('ne répète pas d\'adresse', function () {
        expect($this->stats['empreintes'])->toHaveCount(TIRAGES);
    });
});

describe('uniformité exacte', function () {
    beforeEach(function () {
        $this->bib = app(Bibliotheque::class);
        $this->octets = intdiv(strlen(gmp_strval(gmp_sub($this->bib->nombreDePages, 1), 2)) + 7, 8);
        $this->tirageAvec = fn (array $sequence, ?int &$appels = null): TirageAuHasard => new TirageAuHasard(
            $this->bib,
            function (int $n) use (&$sequence, &$appels) {
                $appels++;
                expect($n)->toBe($this->octets);

                return array_shift($sequence) ?? throw new LogicException('Plus de source');
            },
        );
    });

    it('rejette un tirage ≥ N au lieu de le replier avec un modulo', function () {
        $n = $this->bib->nombreDePages;
        $appels = 0;
        $tirage = ($this->tirageAvec)([
            octetsDe($n, $this->octets),                          // = N : hors bibliothèque, rejeté
            octetsDe(gmp_add($n, 12345), $this->octets),          // > N : rejeté (un modulo l'aurait renvoyé sur 12345)
            "\xFF".str_repeat("\xFF", $this->octets - 1),       // tout à 1 : rejeté
            octetsDe(gmp_init(0), $this->octets),                 // 0 : première page
        ], $appels);

        expect($tirage->auHasard())->toBe('0-w1-s1-v1:1')
            ->and($appels)->toBe(4);
    });

    it('accepte N - 1, la toute dernière page, et la renvoie sans la modifier', function () {
        $dernier = gmp_sub($this->bib->nombreDePages, 1);
        $tirage = ($this->tirageAvec)([octetsDe($dernier, $this->octets)]);

        expect($tirage->auHasard())->toBe(gmp_strval($this->bib->dernierHexagone, 36).'-w1-s1-v1:1');
    });

    it('met à zéro les bits en trop du premier octet avant de comparer à N', function () {
        // Les bits au-delà de k sont retirés : un premier octet qui n'a que ces bits plus un bit utile
        // vaut 1 une fois masqué. Sans masque, le nombre dépasserait N et serait rejeté.
        $k = strlen(gmp_strval(gmp_sub($this->bib->nombreDePages, 1), 2));
        $enTrop = $this->octets * 8 - $k;
        $premier = ((0xFF << (8 - $enTrop)) & 0xFF) | 0x01;
        $octets = chr($premier).str_repeat("\0", $this->octets - 1);

        $tirage = ($this->tirageAvec)([$octets]);

        expect($tirage->numeroAuHasard())->toEqual(gmp_import("\x01".str_repeat("\0", $this->octets - 1)));
    });

    it('fait correspondre un numéro et son adresse à l\'aller et au retour', function (int $numero) {
        $adresse = $this->bib->adresse(gmp_init($numero));
        preg_match('/\A([0-9a-z]+)-w(\d+)-s(\d+)-v(\d+):(\d+)\z/', $adresse, $m);

        expect(gmp_cmp($this->bib->numero(gmp_init($m[1], 36), (int) $m[2], (int) $m[3], (int) $m[4], (int) $m[5]), $numero))->toBe(0);
    })->with([0, 1, 409, 410, 13119, 13120, 65599, 65600, 262399, 262400, 262401, 987654321]);

    it('ordonne les pages dans l\'ordre des adresses : après la page 410 vient la page 1 du volume suivant', function () {
        expect($this->bib->adresse(gmp_init(409)))->toBe('0-w1-s1-v1:410')
            ->and($this->bib->adresse(gmp_init(410)))->toBe('0-w1-s1-v2:1')
            ->and($this->bib->adresse(gmp_init(262400)))->toBe('1-w1-s1-v1:1');
    });
});

it('ne dépend d\'aucune graine : deux instances donnent des tirages différents', function () {
    $a = app(TirageAuHasard::class)->auHasard();
    $b = app(TirageAuHasard::class)->auHasard();

    expect($a)->not->toBe($b);
});

it('est injecté par le conteneur avec la source cryptographique par défaut', function () {
    expect(app(TirageAuHasard::class))->toBeInstanceOf(TirageAuHasard::class)
        ->and(app(Bibliotheque::class))->toBe(app(Bibliotheque::class)); // un seul exemplaire (singleton)
});

it('donne une adresse que le moteur sait lire', function () {
    $moteur = new Moteur(str_repeat('k', 32));
    $adresse = app(TirageAuHasard::class)->auHasard();

    expect(strlen($moteur->lire($adresse)))->toBe(Moteur::LONGUEUR_PAGE);
});
