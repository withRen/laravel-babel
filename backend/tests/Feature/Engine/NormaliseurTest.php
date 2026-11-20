<?php

use App\Services\Engine\Normaliseur;
use Babel\Moteur\Moteur;

/*
 * F3 : normaliser(texte). Règle du contrat : minuscules, accents retirés (œ → oe, æ → ae),
 * hors-alphabet → espace, espaces réduites, bords retirés. Mode page : étapes 1 à 3 seulement.
 */
beforeEach(function () {
    $this->n = app(Normaliseur::class); // injecté par le conteneur, jamais new
});

it('normalise l\'exemple du contrat', function () {
    expect($this->n->normaliser('Où est passé l\'été ?'))->toBe('ou est passe l ete');
});

it('transforme l\'apostrophe en espace et non en rien', function () {
    expect($this->n->normaliser('l\'été'))->toBe('l ete')->not->toBe('lete');
});

it('applique chaque étape', function (string $entree, string $attendu) {
    expect($this->n->normaliser($entree))->toBe($attendu);
})->with([
    'minuscules' => ['BONJOUR Monde', 'bonjour monde'],
    'accents' => ['àâäéèêëîïôöùûüÿ', 'aaaeeeeiioouuuy'],
    'cédille' => ['Français Garçon', 'francais garcon'],
    'tilde' => ['señor', 'senor'],
    'lettres liées' => ['Œuvre cœur æther', 'oeuvre coeur aether'],
    'majuscule liée' => ['ŒIL Æ', 'oeil ae'],
    'virgule et point conservés' => ['a, b. c', 'a, b. c'],
    'chiffres hors alphabet' => ['route 66', 'route'],
    'ponctuation hors alphabet' => ['quoi ?! (oui) ; non : « x »', 'quoi oui non x'],
    'saut de ligne et tabulation' => ["a\nb\tc\r\nd", 'a b c d'],
    'espaces multiples' => ['a    b', 'a b'],
    'espaces créées par le hors-alphabet' => ['a -- b', 'a b'],
    'bords retirés' => ["  \n a b \t ", 'a b'],
    'tiret et underscore' => ['a-b_c', 'a b c'],
    'espace insécable' => ["a\u{00A0}b", 'a b'],
    'emoji et écritures non latines' => ['a 😀 б 日本 z', 'a z'],
    'ß hors alphabet' => ['straße', 'stra e'],
    'vide' => ['', ''],
    'que du hors alphabet' => ['?!', ''],
    'ponctuation seule gardée' => [',', ','],
]);

it('ne garde que les 29 symboles', function () {
    $sortie = $this->n->normaliser("Ça va ? L'été à Zürich, 100 % œuvres… « ñ » \u{1F600}\n\x00\xFF fin.");

    expect($sortie)->toMatch('/\A['.preg_quote(Moteur::ALPHABET, '/').']*\z/');
});

it('est idempotente : normaliser deux fois revient à normaliser une fois', function (string $entree) {
    $une = $this->n->normaliser($entree);

    expect($this->n->normaliser($une))->toBe($une);
})->with([
    'Où est passé l\'été ?',
    "  Œuvre\n\n  ŒIL , . ,  ",
    '',
    '   ',
    "\u{0130}stanbul",
    "e\u{0301}te\u{0301}",
    "\xFF\xFEmauvais utf-8",
]);

it('accepte de l\'UTF-8 invalide sans lever d\'exception', function () {
    expect($this->n->normaliser("ab\xFFcd"))->toBe('ab cd');
});

it('traite pareil une lettre accentuée composée ou décomposée', function () {
    expect($this->n->normaliser("\u{00E9}"))->toBe('e')
        ->and($this->n->normaliser("e\u{0301}"))->toBe('e');
});

describe('mode page (étapes 1 à 3)', function () {
    it('garde les espaces doubles et les espaces de bord', function () {
        expect($this->n->normaliser('  a   b  ', etapesDePage: true))->toBe('  a   b  ');
    });

    it('applique quand même minuscules, accents et hors-alphabet', function () {
        expect($this->n->normaliser("Été\n,  Ça.", etapesDePage: true))->toBe('ete ,  ca.');
    });

    it('laisse une page intacte : 3 200 caractères restent 3 200 caractères', function () {
        $page = substr(str_repeat('abc xyz,. ', 400), 0, Moteur::LONGUEUR_PAGE);

        expect($this->n->normaliser($page, etapesDePage: true))->toBe($page)
            ->and(strlen($this->n->normaliser($page, etapesDePage: true)))->toBe(Moteur::LONGUEUR_PAGE);
    });

    it('est idempotente', function () {
        $une = $this->n->normaliser(" Où  ?\n été ", etapesDePage: true);

        expect($this->n->normaliser($une, etapesDePage: true))->toBe($une);
    });

    it('diffère du mode complet seulement par les étapes 4 et 5', function () {
        $texte = '  Où   est ';

        expect($this->n->normaliser($texte))->toBe('ou est')
            ->and($this->n->normaliser($texte, etapesDePage: true))->toBe('  ou   est ');
    });
});
