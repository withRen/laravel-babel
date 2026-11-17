<?php

use App\Services\Engine\Bibliotheque;
use App\Services\Engine\ResultatValidation;
use App\Services\Engine\Valideur;
use Babel\Moteur\Erreur\ErreurMoteur;
use Babel\Moteur\Moteur;

/*
 * F5 : valider(adresse). Jamais d'exception, toutes les erreurs d'un coup, refus sans correction,
 * ERR_OUT_OF_BOUNDS au-delà du dernier hexagone.
 */
beforeEach(function () {
    $this->v = app(Valideur::class);
    $this->bib = app(Bibliotheque::class);
    // Clé d'essai, propre aux tests : le moteur sert ici de référence (valide ⇒ lisible).
    $this->moteur = new Moteur(str_repeat('k', 32));

    $this->codes = fn (string $adresse): array => array_map(
        fn ($e) => $e->code->value.':'.$e->champ,
        $this->v->valider($adresse)->erreurs,
    );
    $this->dernier = fn (): string => gmp_strval($this->bib->dernierHexagone, 36);
});

describe('adresses valides', function () {
    it('accepte une adresse de page', function (string $adresse) {
        $r = $this->v->valider($adresse);

        expect($r->valide)->toBeTrue()
            ->and($r->niveau)->toBe('page')
            ->and($r->erreurs)->toBe([]);
    })->with([
        '0-w1-s1-v1:1',
        'a-w4-s5-v32:410',
        'babel-w2-s3-v17:200',
        'zzzz-w1-s1-v1:1',
        '0-w4-s5-v32:410',
    ]);

    it('accepte une adresse de livre, sans :page', function () {
        $r = $this->v->valider('babel-w2-s3-v17');

        expect($r->valide)->toBeTrue()->and($r->niveau)->toBe('livre')->and($r->erreurs)->toBe([]);
    });

    it('accepte le livre 0-w1-s1-v1 de l\'exemple du contrat', function () {
        expect($this->v->valider('0-w1-s1-v1')->niveau)->toBe(ResultatValidation::NIVEAU_LIVRE);
    });

    it('accepte une page lisible par le moteur', function () {
        $adresse = '3f9k2x-w3-s4-v12:287';

        expect($this->v->valider($adresse)->valide)->toBeTrue()
            ->and(strlen($this->moteur->lire($adresse)))->toBe(Moteur::LONGUEUR_PAGE);
    });
});

describe('erreurs de forme', function () {
    it('refuse sans corriger', function (string $adresse, array $attendu) {
        expect(($this->codes)($adresse))->toBe($attendu);
    })->with([
        'zéro devant le mur (w04)' => ['a-w04-s1-v1:1', ['ERR_INVALID_ADDRESS_SYNTAX:mur']],
        'zéro devant l\'étagère' => ['a-w1-s01-v1:1', ['ERR_INVALID_ADDRESS_SYNTAX:etagere']],
        'zéro devant le volume' => ['a-w1-s1-v001:1', ['ERR_INVALID_ADDRESS_SYNTAX:volume']],
        'zéro devant la page' => ['a-w1-s1-v1:01', ['ERR_INVALID_ADDRESS_SYNTAX:page']],
        'zéro devant l\'hexagone' => ['0a-w1-s1-v1:1', ['ERR_INVALID_ADDRESS_SYNTAX:hexagone']],
        'W majuscule' => ['a-W4-s1-v1:1', ['ERR_INVALID_ADDRESS_SYNTAX:adresse']],
        'S majuscule' => ['a-w1-S1-v1:1', ['ERR_INVALID_ADDRESS_SYNTAX:adresse']],
        'V majuscule' => ['a-w1-s1-V1:1', ['ERR_INVALID_ADDRESS_SYNTAX:adresse']],
        'hexagone vide' => ['-w1-s1-v1:1', ['ERR_INVALID_ADDRESS_SYNTAX:hexagone']],
        'page vide après les deux-points' => ['a-w1-s1-v1:', ['ERR_INVALID_ADDRESS_SYNTAX:page']],
        'mur non numérique' => ['a-wx-s1-v1:1', ['ERR_INVALID_ADDRESS_SYNTAX:mur']],
        'signe devant la page' => ['a-w1-s1-v1:+5', ['ERR_INVALID_ADDRESS_SYNTAX:page']],
        'chaîne vide' => ['', ['ERR_INVALID_ADDRESS_SYNTAX:adresse']],
        'n\'importe quoi' => ['bonjour', ['ERR_INVALID_ADDRESS_SYNTAX:adresse']],
        'champ manquant (volume)' => ['a-w1-s1:1', ['ERR_INVALID_ADDRESS_SYNTAX:adresse']],
        'espace autour' => [' a-w1-s1-v1:1', ['ERR_INVALID_HEX_CHARS:hexagone']],
        'deux-points en trop' => ['a-w1-s1-v1:1:2', ['ERR_INVALID_ADDRESS_SYNTAX:page']],
        'saut de ligne final' => ["a-w1-s1-v1:1\n", ['ERR_INVALID_ADDRESS_SYNTAX:page']],
    ]);

    it('refuse un hexagone en majuscules ou avec un caractère interdit', function (string $hex) {
        expect(($this->codes)("{$hex}-w1-s1-v1:1"))->toBe(['ERR_INVALID_HEX_CHARS:hexagone']);
    })->with(['ABC', 'aBc', 'a_b', 'é', 'a b', '1.5']);
});

describe('limites', function () {
    it('refuse un mur, une étagère, un volume ou une page hors limites', function (string $adresse, string $champ) {
        expect(($this->codes)($adresse))->toBe(["ERR_OUT_OF_BOUNDS:{$champ}"]);
    })->with([
        ['a-w0-s1-v1:1', 'mur'],
        ['a-w5-s1-v1:1', 'mur'],
        ['a-w1-s0-v1:1', 'etagere'],
        ['a-w1-s6-v1:1', 'etagere'],
        ['a-w1-s1-v0:1', 'volume'],
        ['a-w1-s1-v33:1', 'volume'],
        ['a-w1-s1-v1:0', 'page'],
        ['a-w1-s1-v1:411', 'page'],
        ['a-w1-s1-v1:99999999999999999999', 'page'],
    ]);

    it('traite un livre hors limites comme une page hors limites', function () {
        expect(($this->codes)('a-w5-s1-v1'))->toBe(['ERR_OUT_OF_BOUNDS:mur']);
    });

    it('refuse un hexagone plus long que 3 004 caractères sans le convertir', function () {
        $r = $this->v->valider(str_repeat('z', Moteur::LONGUEUR_MAX_HEXAGONE + 1).'-w1-s1-v1:1');

        expect($r->valide)->toBeFalse()
            ->and($r->erreurs[0]->code->value)->toBe('ERR_OUT_OF_BOUNDS')
            ->and($r->erreurs[0]->champ)->toBe('hexagone');
    });

    it('refuse un hexagone de 3 004 caractères au-delà du dernier', function () {
        $r = $this->v->valider(str_repeat('z', Moteur::LONGUEUR_MAX_HEXAGONE).'-w1-s1-v1:1');

        expect($r->erreurs[0]->code->value)->toBe('ERR_OUT_OF_BOUNDS')
            ->and($r->erreurs[0]->champ)->toBe('hexagone');
    });
});

describe('le dernier hexagone, incomplet', function () {
    it('a un nom de 3 004 caractères', function () {
        expect(strlen(($this->dernier)()))->toBe(Moteur::LONGUEUR_MAX_HEXAGONE);
    });

    it('n\'a qu\'une seule page : w1-s1-v1:1, la toute dernière de la bibliothèque', function () {
        $derniere = ($this->dernier)().'-w1-s1-v1:1';

        expect($this->v->valider($derniere)->valide)->toBeTrue()
            ->and($this->bib->adresse(gmp_sub($this->bib->nombreDePages, 1)))->toBe($derniere);
    });

    it('refuse toute autre page du dernier hexagone par ERR_OUT_OF_BOUNDS', function (string $suite) {
        $r = $this->v->valider(($this->dernier)().$suite);

        expect($r->valide)->toBeFalse()
            ->and($r->erreurs)->toHaveCount(1)
            ->and($r->erreurs[0]->code->value)->toBe('ERR_OUT_OF_BOUNDS')
            ->and($r->erreurs[0]->champ)->toBe('adresse');
    })->with(['-w1-s1-v1:2', '-w1-s1-v2:1', '-w1-s2-v1:1', '-w2-s1-v1:1', '-w4-s5-v32:410']);

    it('refuse aussi les livres qui n\'existent pas dans le dernier hexagone', function () {
        expect($this->v->valider(($this->dernier)().'-w1-s1-v1')->valide)->toBeTrue()
            ->and($this->v->valider(($this->dernier)().'-w1-s1-v2')->valide)->toBeFalse();
    });

    it('accepte l\'hexagone juste avant le dernier, en entier', function () {
        $avant = gmp_strval(gmp_sub($this->bib->dernierHexagone, 1), 36);

        expect($this->v->valider("{$avant}-w4-s5-v32:410")->valide)->toBeTrue();
    });

    it('colle à la définition : dernier hexagone = (29^3200 - 1) div 262 400', function () {
        expect(gmp_cmp($this->bib->dernierHexagone, gmp_div_q(gmp_sub(gmp_pow(29, 3200), 1), 4 * 5 * 32 * 410)))->toBe(0);
    });

    it('est cohérente avec le moteur : lire() accepte la dernière page et refuse la suivante', function () {
        $hex = ($this->dernier)();

        expect(strlen($this->moteur->lire("{$hex}-w1-s1-v1:1")))->toBe(Moteur::LONGUEUR_PAGE)
            ->and(fn () => $this->moteur->lire("{$hex}-w1-s1-v1:2"))->toThrow(ErreurMoteur::class);
    });
});

describe('toutes les erreurs d\'un coup', function () {
    it('renvoie une erreur par champ fautif, dans l\'ordre hexagone, mur, étagère, volume, page', function () {
        expect(($this->codes)('A-w9-s9-v99:999'))->toBe([
            'ERR_INVALID_HEX_CHARS:hexagone',
            'ERR_OUT_OF_BOUNDS:mur',
            'ERR_OUT_OF_BOUNDS:etagere',
            'ERR_OUT_OF_BOUNDS:volume',
            'ERR_OUT_OF_BOUNDS:page',
        ]);
    });

    it('mélange erreurs de forme et de limites', function () {
        expect(($this->codes)('0a-w04-s6-v1:abc'))->toBe([
            'ERR_INVALID_ADDRESS_SYNTAX:hexagone',
            'ERR_INVALID_ADDRESS_SYNTAX:mur',
            'ERR_OUT_OF_BOUNDS:etagere',
            'ERR_INVALID_ADDRESS_SYNTAX:page',
        ]);
    });

    it('rend le format d\'erreur du contrat, celui du moteur', function () {
        $r = $this->v->valider('a-w5-s1-v1:1');

        expect($r->erreursVersTableau())->toBe([
            ['code' => 'ERR_OUT_OF_BOUNDS', 'champ' => 'mur', 'message' => 'Le mur doit être compris entre 1 et 4.'],
        ]);
    });

    it('ne recopie jamais l\'entrée dans le message', function () {
        $r = $this->v->valider('SECRET-w9-s1-v1:1');

        expect(json_encode($r->erreursVersTableau()))->not->toContain('SECRET');
    });

    it('donne les mêmes erreurs que le moteur sur une adresse de page', function (string $adresse) {
        try {
            $this->moteur->lire($adresse);
            $attendues = [];
        } catch (ErreurMoteur $e) {
            $attendues = $e->versTableau();
        }

        expect($this->v->valider($adresse)->erreursVersTableau())->toBe($attendues);
    })->with([
        'a-w1-s1-v1:1',
        'A-w9-s9-v99:999',
        '0a-w04-s6-v1:abc',
        'a-W4-s1-v1:1',
        '-w1-s1-v1:1',
        'a-w1-s1-v1:411',
    ]);
});

describe('ne lève jamais d\'exception', function () {
    it('renvoie un résultat pour toute entrée', function (mixed $entree) {
        $r = $this->v->valider($entree);

        expect($r)->toBeInstanceOf(ResultatValidation::class)
            ->and($r->valide)->toBeFalse()
            ->and($r->erreurs)->not->toBeEmpty();
    })->with([
        'vide' => [''],
        'octets invalides' => ["\xFF\xFE-w1-s1-v1:1"],
        'octet nul' => ["\0"],
        'très longue' => [str_repeat('a-', 50000)],
        'grand nombre' => ['a-w'.str_repeat('9', 10000).'-s1-v1:1'],
        'null' => [null],
        'entier' => [42],
        'tableau' => [[]],
        'booléen' => [true],
    ]);

    it('ne confond jamais valide et refusée', function () {
        $ok = $this->v->valider('a-w1-s1-v1:1');
        $ko = $this->v->valider('x');

        expect([$ok->valide, $ok->niveau, $ok->erreurs])->toBe([true, 'page', []])
            ->and([$ko->valide, $ko->niveau])->toBe([false, null]);
    });
});

it('n\'a qu\'une seule écriture valide par page', function () {
    $canonique = $this->bib->adresse(gmp_init(123456789));

    expect($this->v->valider($canonique)->valide)->toBeTrue();

    // Mêmes coordonnées, autres écritures : toutes refusées.
    [$hex, $reste] = explode('-', $canonique, 2);
    foreach ([strtoupper($hex).'-'.$reste, $hex.'-'.strtoupper($reste), '0'.$hex.'-'.$reste, $hex.'-'.str_replace('w', 'w0', $reste)] as $variante) {
        if ($variante !== $canonique) {
            expect($this->v->valider($variante)->valide)->toBeFalse("{$variante} ne doit pas être acceptée");
        }
    }
});
