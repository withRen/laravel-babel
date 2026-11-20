<?php

use App\Models\Recherche;
use Babel\Moteur\Moteur;

/*
 * POST /recherches (F2, B2). Exemple du contrat : « Où est passé l'été ? » en mode exact avec la clé de test A.
 */
beforeEach(fn () => bibliothequeLocale());

function chercher($test, array $corps)
{
    return $test->postJson('/api/v1/recherches', $corps);
}

it('normalise le texte et renvoie l\'adresse de l\'exemple du contrat', function () {
    $r = chercher($this, ['texte' => "Où est passé l'été ?", 'mode' => 'exact']);

    $r->assertOk();
    expect(array_keys($r->json()))->toBe(['bibliotheque', 'texte_normalise', 'mode', 'adresse']);
    expect($r->json('bibliotheque'))->toBe('exemple');
    expect($r->json('texte_normalise'))->toBe('ou est passe l ete');
    expect($r->json('mode'))->toBe('exact');
    expect($r->json('adresse'))->toStartWith('43sxzqy307xbxpf7l6hy6ydxcoestni3da')->toEndWith('-w2-s1-v16:352');
});

it('renvoie une adresse qui, lue, contient bien le texte (exact : le texte puis des espaces)', function () {
    $adresse = chercher($this, ['texte' => 'Bonjour, Babel.', 'mode' => 'exact'])->json('adresse');

    $texte = app(Moteur::class)->lire($adresse);
    expect($texte)->toStartWith('bonjour, babel.')->toHaveLength(3200);
    expect(trim($texte))->toBe('bonjour, babel.');
});

it('donne toujours la même adresse pour le même texte et le même mode, y compris en bruit', function (string $mode) {
    $a = chercher($this, ['texte' => 'une phrase', 'mode' => $mode])->json('adresse');
    $b = chercher($this, ['texte' => 'UNE   phrase !', 'mode' => $mode])->json('adresse');

    expect($a)->toBe($b);
})->with(['exact', 'bruit']);

it('en mode bruit, la page contient le texte quelque part', function () {
    $adresse = chercher($this, ['texte' => 'une phrase', 'mode' => 'bruit'])->json('adresse');

    expect(app(Moteur::class)->lire($adresse))->toContain('une phrase');
});

it('en mode page, ne réduit pas les espaces : 3 200 caractères exactement', function () {
    $page = app(Moteur::class)->lire('0-w1-s1-v1:1');   // contient des espaces doubles et en bord
    $r = chercher($this, ['texte' => strtoupper($page), 'mode' => 'page']);

    $r->assertOk();
    expect($r->json('texte_normalise'))->toBe($page)->toHaveLength(3200);
    expect($r->json('adresse'))->toBe('0-w1-s1-v1:1');
    expect($r->json('mode'))->toBe('page');
});

it('conserve les espaces de bord du texte de page (TrimStrings exclu pour « texte »)', function () {
    $page = ' '.substr(app(Moteur::class)->lire('0-w1-s1-v1:1'), 1);
    $r = chercher($this, ['texte' => $page, 'mode' => 'page']);

    $r->assertOk();
    expect($r->json('texte_normalise'))->toHaveLength(3200);
});

it('refuse un mode inconnu avant tout le reste (la casse compte) : 422 ERR_UNKNOWN_MODE', function (array $corps) {
    attendErreurContrat(chercher($this, $corps), 422, 'ERR_UNKNOWN_MODE', 'mode');
})->with([
    'majuscule' => [['texte' => 'abc', 'mode' => 'Exact']],
    'inconnu' => [['texte' => 'abc', 'mode' => 'autre']],
    'absent' => [['texte' => 'abc']],
    'null' => [['texte' => 'abc', 'mode' => null]],
    'nombre' => [['texte' => 'abc', 'mode' => 1]],
    'mode inconnu ET texte vide : le mode d\'abord' => [['texte' => '', 'mode' => 'x']],
]);

it('refuse un texte vide, absent, null ou vide une fois normalisé : 422 ERR_EMPTY_QUERY', function (array $corps) {
    attendErreurContrat(chercher($this, $corps), 422, 'ERR_EMPTY_QUERY', 'texte');
})->with([
    'vide' => [['texte' => '', 'mode' => 'exact']],
    'absent' => [['mode' => 'exact']],
    'null' => [['texte' => null, 'mode' => 'bruit']],
    'que des espaces' => [['texte' => "   \n ", 'mode' => 'exact']],
    'vide après normalisation' => [['texte' => '?!', 'mode' => 'exact']],
    'pas une chaîne' => [['texte' => 12, 'mode' => 'exact']],
    'un tableau' => [['texte' => ['a'], 'mode' => 'exact']],
]);

it('refuse un texte de plus de 3 200 caractères : 422 ERR_TEXT_TOO_LONG', function (string $mode) {
    attendErreurContrat(chercher($this, ['texte' => str_repeat('a', 3201), 'mode' => $mode]), 422, 'ERR_TEXT_TOO_LONG', 'texte');
})->with(['exact', 'bruit']);

it('accepte 3 200 caractères en exact', function () {
    chercher($this, ['texte' => str_repeat('a', 3200), 'mode' => 'exact'])->assertOk();
});

it('refuse le mode page si le texte ne fait pas 3 200 caractères : 422 ERR_INVALID_PAGE_LENGTH', function () {
    attendErreurContrat(chercher($this, ['texte' => 'court', 'mode' => 'page']), 422, 'ERR_INVALID_PAGE_LENGTH', 'texte');
});

it('ne laisse jamais sortir ERR_INVALID_ALPHABET, quel que soit le texte', function (string $texte) {
    $r = chercher($this, ['texte' => $texte, 'mode' => 'exact']);

    expect($r->status())->toBeIn([200, 422]);
    if ($r->status() === 422) {
        expect($r->json('erreurs.0.code'))->not->toBe('ERR_INVALID_ALPHABET');
    }
})->with(['😀 émoji', "tab\there", 'ÀÉÎÕÜ', 'œuvre æ', '日本語 texte', "ligne\nsuivante", "nul\0octet"]);

it('ne modifie rien en base : aucune recherche n\'est enregistrée', function () {
    chercher($this, ['texte' => 'une phrase', 'mode' => 'exact'])->assertOk();

    expect(Recherche::count())->toBe(0);
});
