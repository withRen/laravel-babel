<?php

use App\Models\Adresse;
use App\Models\Bibliotheque;
use App\Models\Collection;
use App\Models\TexteBanni;

/*
 * GET /collections et GET /collections/{id} (B3).
 */
beforeEach(function () {
    $this->locale = bibliothequeLocale();
});

/** Une collection avec ses pages, dans l'ordre. */
function collectionAvec(array $attributs = [], array $adresses = []): Collection
{
    $collection = Collection::factory()->publique()->create($attributs + ['nom' => 'Les premières pages', 'description' => 'Le tout début.']);
    foreach (array_values($adresses) as $i => $adresse) {
        $collection->pages()->create(['position' => $i + 1, 'adresse_id' => $adresse->id]);
    }

    return $collection;
}

function adressePage(Bibliotheque $biblio, string $hexagone = '0', int $page = 1, array $plus = []): Adresse
{
    return Adresse::factory()->create($plus + [
        'bibliotheque_id' => $biblio->id, 'hexagone' => $hexagone, 'mur' => 1, 'etagere' => 1, 'volume' => 1, 'page' => $page,
    ]);
}

/* ------------------------------------------------------------ liste */

it('liste les collections publiques au format du contrat', function () {
    $c = collectionAvec(['maj_le' => '2026-11-20 13:05:00'], [adressePage($this->locale, '0', 1), adressePage($this->locale, '0', 2)]);

    $r = $this->getJson('/api/v1/collections');

    $r->assertOk();
    expect(array_keys($r->json()))->toBe(['lot', 'taille', 'total', 'elements']);
    expect($r->json('lot'))->toBe(1)->and($r->json('taille'))->toBe(20)->and($r->json('total'))->toBe(1);
    expect($r->json('elements.0'))->toBe([
        'id' => (string) $c->id,
        'nom' => 'Les premières pages',
        'description' => 'Le tout début.',
        'nombre_pages' => 2,
        'maj_le' => '2026-11-20T13:05:00+00:00',
    ]);
    expect(array_keys($r->json('elements.0')))->toBe(['id', 'nom', 'description', 'nombre_pages', 'maj_le']);
});

it('renvoie l\'id en chaîne et une description vide (jamais null)', function () {
    collectionAvec(['description' => null]);

    $r = $this->getJson('/api/v1/collections');

    expect($r->json('elements.0.id'))->toBeString();
    expect($r->json('elements.0.description'))->toBe('');
});

it('n\'affiche ni les collections privées ni les masquées, et ne publie pas l\'auteur', function () {
    collectionAvec(['nom' => 'Publique']);
    Collection::factory()->create(['nom' => 'Privée']);
    Collection::factory()->publique()->create(['nom' => 'Masquée', 'masque_le' => now()]);

    $r = $this->getJson('/api/v1/collections');

    expect($r->json('total'))->toBe(1);
    expect(array_column($r->json('elements'), 'nom'))->toBe(['Publique']);
    expect($r->getContent())->not->toContain('utilisateur')->not->toContain('auteur');
});

it('trie de la plus récemment modifiée à la plus ancienne', function () {
    collectionAvec(['nom' => 'ancienne', 'maj_le' => '2026-01-01 10:00:00']);
    collectionAvec(['nom' => 'récente', 'maj_le' => '2026-03-01 10:00:00']);
    collectionAvec(['nom' => 'milieu', 'maj_le' => '2026-02-01 10:00:00']);

    expect(array_column($this->getJson('/api/v1/collections')->json('elements'), 'nom'))->toBe(['récente', 'milieu', 'ancienne']);
});

it('découpe en lots : lot, taille, total', function () {
    foreach (range(1, 5) as $i) {
        collectionAvec(['nom' => "c{$i}", 'maj_le' => "2026-01-0{$i} 10:00:00"]);
    }

    $l1 = $this->getJson('/api/v1/collections?taille=2&lot=1');
    $l3 = $this->getJson('/api/v1/collections?taille=2&lot=3');
    $l9 = $this->getJson('/api/v1/collections?taille=2&lot=9');

    expect($l1->json('lot'))->toBe(1)->and($l1->json('taille'))->toBe(2)->and($l1->json('total'))->toBe(5);
    expect(array_column($l1->json('elements'), 'nom'))->toBe(['c5', 'c4']);
    expect(array_column($l3->json('elements'), 'nom'))->toBe(['c1']);
    // au-delà du dernier lot : une liste vide, pas une erreur
    $l9->assertOk();
    expect($l9->json('elements'))->toBe([])->and($l9->json('total'))->toBe(5);
});

it('refuse les paramètres mal écrits ou hors limites : 422 ERR_INVALID_PARAMETER sur le bon champ', function (string $requete, string $champ) {
    attendErreurContrat($this->getJson('/api/v1/collections?'.$requete), 422, 'ERR_INVALID_PARAMETER', $champ);
})->with([
    ['taille=0', 'taille'],
    ['taille=51', 'taille'],
    ['taille=abc', 'taille'],
    ['taille=1.5', 'taille'],
    ['taille=-3', 'taille'],
    ['taille=', 'taille'],
    ['lot=0', 'lot'],
    ['lot=-1', 'lot'],
    ['lot=abc', 'lot'],
    ['lot=99999999999999999999', 'lot'],
    ['lot=', 'lot'],
]);

it('renvoie une erreur par champ fautif, toutes d\'un coup', function () {
    $r = $this->getJson('/api/v1/collections?lot=0&taille=99');

    $r->assertStatus(422);
    expect(array_column($r->json('erreurs'), 'champ'))->toEqualCanonicalizing(['lot', 'taille']);
});

it('accepte les bornes taille=1 et taille=50', function () {
    $this->getJson('/api/v1/collections?taille=1')->assertOk()->assertJsonPath('taille', 1);
    $this->getJson('/api/v1/collections?taille=50')->assertOk()->assertJsonPath('taille', 50);
});

it('ne compte pas les pages marquées par un texte banni appliqué', function () {
    $banni = TexteBanni::factory()->applique()->create(['origine_id' => $this->locale->id]);
    collectionAvec([], [
        adressePage($this->locale, '0', 1),
        adressePage($this->locale, '0', 2, ['texte_banni_id' => $banni->id]),
        adressePage($this->locale, '0', 3),
    ]);

    expect($this->getJson('/api/v1/collections')->json('elements.0.nombre_pages'))->toBe(2);
});

/* ------------------------------------------------------------ détail */

it('renvoie une collection avec ses pages dans l\'ordre, chacune avec sa bibliothèque', function () {
    $voisine = Bibliotheque::factory()->create(['identifiant' => 'voisine']);
    $c = collectionAvec(['maj_le' => '2026-11-20 13:05:00'], [
        adressePage($this->locale, '0', 1),
        adressePage($voisine, 'babel', 2),
    ]);

    $r = $this->getJson('/api/v1/collections/'.$c->id);

    $r->assertOk();
    expect(array_keys($r->json()))->toBe(['id', 'nom', 'description', 'nombre_pages', 'maj_le', 'pages']);
    expect($r->json('id'))->toBe((string) $c->id)->and($r->json('nombre_pages'))->toBe(2);
    expect($r->json('pages'))->toBe([
        ['position' => 1, 'bibliotheque' => 'exemple', 'adresse' => '0-w1-s1-v1:1'],
        ['position' => 2, 'bibliotheque' => 'voisine', 'adresse' => 'babel-w1-s1-v1:2'],
    ]);
    expect(array_keys($r->json('pages.0')))->toBe(['position', 'bibliotheque', 'adresse']);
});

it('retire les pages à texte banni mais garde la position des autres', function () {
    $banni = TexteBanni::factory()->applique()->create(['origine_id' => $this->locale->id]);
    $c = collectionAvec([], [
        adressePage($this->locale, '0', 1),
        adressePage($this->locale, '0', 2, ['texte_banni_id' => $banni->id]),
        adressePage($this->locale, '0', 3),
    ]);

    $r = $this->getJson('/api/v1/collections/'.$c->id);

    expect(array_column($r->json('pages'), 'position'))->toBe([1, 3]);
    expect($r->json('nombre_pages'))->toBe(2);
});

it('répond la même 404 ERR_NOT_FOUND pour une collection privée, masquée, inconnue ou mal écrite', function (string $id) {
    $privee = Collection::factory()->create();
    $masquee = Collection::factory()->publique()->create(['masque_le' => now()]);
    $identifiant = match ($id) {
        'privée' => $privee->id, 'masquée' => $masquee->id, default => $id
    };

    $r = $this->getJson('/api/v1/collections/'.$identifiant);

    attendErreurContrat($r, 404, 'ERR_NOT_FOUND', 'id');
})->with([
    'privée' => ['privée'],
    'masquée' => ['masquée'],
    'inconnue' => ['99999'],
    'pas un nombre' => ['abc'],
    'nombre suivi de lettres' => ['1abc'],
    'trop grand pour un entier' => ['99999999999999999999999'],
    'avec un signe' => ['-1'],
]);

it('ne confond pas « 12abc » avec la collection 12', function () {
    $c = collectionAvec();

    $this->getJson('/api/v1/collections/'.$c->id.'abc')->assertNotFound();
});
