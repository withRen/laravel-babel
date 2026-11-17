<?php

use App\Models\Adresse;
use App\Models\Bibliotheque;
use App\Models\TexteBanni;
use Babel\Moteur\Moteur;

/*
 * GET /pages/{adresse} (F1, B1) et POST /lectures (B1).
 * Textes de référence : la clé de test A du moteur, comme dans les exemples du contrat.
 */
const PAGE_UN = '0-w1-s1-v1:1';

beforeEach(fn () => bibliothequeLocale());

it('lit la page 0-w1-s1-v1:1 : 200, 3 champs, 3 200 caractères parmi les 29 symboles', function () {
    $r = $this->getJson('/api/v1/pages/'.PAGE_UN);

    $r->assertOk();
    expect(array_keys($r->json()))->toBe(['bibliotheque', 'adresse', 'texte']);
    expect($r->json('bibliotheque'))->toBe('exemple');
    expect($r->json('adresse'))->toBe(PAGE_UN);
    expect($r->json('texte'))->toHaveLength(3200)->toMatch('/^[a-z ,.]{3200}$/');
    expect($r->json('texte'))->toStartWith('tnznd.olonj xxqvbefublhf');
});

it('renvoie le même texte que le moteur', function () {
    $r = $this->getJson('/api/v1/pages/0-w2-s3-v4:5');

    expect($r->json('texte'))->toBe(app(Moteur::class)->lire('0-w2-s3-v4:5'));
});

it('lit une page avec un hexagone de plusieurs milliers de caractères (adresse de 3 018 caractères)', function () {
    // L'adresse renvoyée par une recherche du contrat est de cette taille : on s'en sert.
    $adresse = $this->postJson('/api/v1/recherches', ['texte' => 'ou est passe l ete', 'mode' => 'exact'])->json('adresse');
    expect(strlen($adresse))->toBeGreaterThan(3000);

    $this->getJson('/api/v1/pages/'.$adresse)->assertOk()->assertJsonPath('adresse', $adresse);
});

it('renvoie toutes les erreurs d\'un coup, dans l\'ordre hexagone, mur : 422', function () {
    $r = $this->getJson('/api/v1/pages/ABC-w9-s1-v1:1');

    $r->assertStatus(422);
    expect($r->json('erreurs'))->toHaveCount(2);
    expect(array_column($r->json('erreurs'), 'code'))->toBe(['ERR_INVALID_HEX_CHARS', 'ERR_OUT_OF_BOUNDS']);
    expect(array_column($r->json('erreurs'), 'champ'))->toBe(['hexagone', 'mur']);
});

it('refuse une adresse de livre (sans « :page ») : 422 ERR_INVALID_ADDRESS_SYNTAX sur « page »', function () {
    attendErreurContrat($this->getJson('/api/v1/pages/0-w1-s1-v1'), 422, 'ERR_INVALID_ADDRESS_SYNTAX', 'page');
});

it('répond 404 ERR_OUT_OF_BOUNDS quand l\'adresse est bien écrite mais la page n\'existe pas', function () {
    attendErreurContrat($this->getJson('/api/v1/pages/0-w1-s1-v33:1'), 404, 'ERR_OUT_OF_BOUNDS', 'volume');
    attendErreurContrat($this->getJson('/api/v1/pages/0-w1-s1-v1:411'), 404, 'ERR_OUT_OF_BOUNDS', 'page');
    attendErreurContrat($this->getJson('/api/v1/pages/0-w1-s1-v1:0'), 404, 'ERR_OUT_OF_BOUNDS', 'page');
});

it('refuse sans corriger : majuscules, zéro de tête, espace en bord', function (string $adresse) {
    $r = $this->get('/api/v1/pages/'.rawurlencode($adresse), ['Accept' => 'application/json']);

    $r->assertStatus(422);
    expect(array_keys($r->json()))->toBe(['erreurs']);
})->with([
    'hexagone en majuscules' => ['ABC-w1-s1-v1:1'],
    'zéro de tête sur le mur' => ['0-w01-s1-v1:1'],
    'mur en majuscule' => ['0-W1-s1-v1:1'],
    'espace avant' => [' 0-w1-s1-v1:1'],
    'espace après' => ['0-w1-s1-v1:1 '],
    'page signée' => ['0-w1-s1-v1:+1'],
    'page avec un zéro de tête' => ['0-w1-s1-v1:01'],
    'n\'importe quoi' => ['bonjour'],
]);

it('répond une erreur du contrat (pas une page HTML) pour une adresse qui n\'a aucune forme', function () {
    $r = $this->getJson('/api/v1/pages/n-importe-quoi');

    $r->assertStatus(422);
    attendErreurContrat($r, 422, 'ERR_INVALID_ADDRESS_SYNTAX', 'adresse');
});

it('refuse sans calcul un hexagone démesuré', function () {
    $hexagone = str_repeat('z', 5000);

    attendErreurContrat($this->getJson("/api/v1/pages/{$hexagone}-w1-s1-v1:1"), 404, 'ERR_OUT_OF_BOUNDS', 'hexagone');
});

it('lit toujours la page, même si elle est marquée par un texte banni appliqué (on ne masque jamais une page)', function () {
    $texteBanni = TexteBanni::factory()->applique()->create(['origine_id' => Bibliotheque::locale()->id]);
    Adresse::factory()->create([
        'bibliotheque_id' => Bibliotheque::locale()->id,
        'hexagone' => '0', 'mur' => 1, 'etagere' => 1, 'volume' => 1, 'page' => 1,
        'texte_banni_id' => $texteBanni->id,
    ]);

    $this->getJson('/api/v1/pages/'.PAGE_UN)->assertOk()->assertJsonPath('texte', app(Moteur::class)->lire(PAGE_UN));
});

/* ---------------------------------------------------------------- POST /lectures */

it('lit plusieurs pages dans l\'ordre de la demande et garde les erreurs à leur place (200)', function () {
    $r = $this->postJson('/api/v1/lectures', ['adresses' => [PAGE_UN, '0-w1-s1-v33:1', '0-w1-s1-v1']]);

    $r->assertOk();
    expect(array_keys($r->json()))->toBe(['bibliotheque', 'elements']);
    expect($r->json('bibliotheque'))->toBe('exemple');
    $e = $r->json('elements');
    expect($e)->toHaveCount(3);
    foreach ($e as $element) {
        expect(array_keys($element))->toBe(['adresse', 'texte', 'erreurs']);
    }
    expect($e[0]['adresse'])->toBe(PAGE_UN);
    expect($e[0]['texte'])->toStartWith('tnznd.olonj')->toHaveLength(3200);
    expect($e[0]['erreurs'])->toBeNull();

    expect($e[1]['texte'])->toBeNull();
    expect($e[1]['erreurs'])->toBe([[
        'code' => 'ERR_OUT_OF_BOUNDS',
        'champ' => 'volume',
        'message' => 'Le volume doit être compris entre 1 et 32.',
    ]]);

    // une adresse de livre est refusée comme sur GET /pages/{adresse}
    expect($e[2]['texte'])->toBeNull();
    expect($e[2]['erreurs'][0]['code'])->toBe('ERR_INVALID_ADDRESS_SYNTAX');
    expect($e[2]['erreurs'][0]['champ'])->toBe('page');
});

it('rend l\'adresse demandée telle quelle, y compris vide ou avec des espaces', function () {
    $r = $this->postJson('/api/v1/lectures', ['adresses' => ['', ' '.PAGE_UN, PAGE_UN.' ']]);

    $r->assertOk();
    expect(array_column($r->json('elements'), 'adresse'))->toBe(['', ' '.PAGE_UN, PAGE_UN.' ']);
    foreach ($r->json('elements') as $element) {
        expect($element['texte'])->toBeNull();
        expect($element['erreurs'])->not->toBeEmpty();
    }
});

it('accepte 50 adresses et refuse 51 : ERR_INVALID_PARAMETER sur « adresses »', function () {
    $this->postJson('/api/v1/lectures', ['adresses' => array_fill(0, 50, PAGE_UN)])->assertOk();

    attendErreurContrat(
        $this->postJson('/api/v1/lectures', ['adresses' => array_fill(0, 51, PAGE_UN)]),
        422, 'ERR_INVALID_PARAMETER', 'adresses',
    );
});

it('refuse un corps sans liste exploitable : absente, vide, pas une liste, éléments qui ne sont pas des chaînes', function (array $corps) {
    attendErreurContrat($this->postJson('/api/v1/lectures', $corps), 422, 'ERR_INVALID_PARAMETER', 'adresses');
})->with([
    'corps vide' => [[]],
    'liste vide' => [['adresses' => []]],
    'une chaîne' => [['adresses' => PAGE_UN]],
    'un objet' => [['adresses' => ['a' => PAGE_UN]]],
    'un nombre dans la liste' => [['adresses' => [PAGE_UN, 5]]],
    'null dans la liste' => [['adresses' => [PAGE_UN, null]]],
    'une liste dans la liste' => [['adresses' => [[PAGE_UN]]]],
]);
