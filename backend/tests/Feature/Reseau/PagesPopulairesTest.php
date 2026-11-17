<?php

use App\Models\Adresse;
use App\Models\Bibliotheque;
use App\Models\MarquePage;
use App\Models\TexteBanni;
use App\Models\User;
use App\Models\Vote;

/*
 * GET /pages-populaires (B4). Score = somme des votes (+1/-1) + nombre de marque-pages.
 */
beforeEach(function () {
    $this->locale = bibliothequeLocale();
});

function pageAvecScore(Bibliotheque $b, string $hexagone, int $votesPour, int $votesContre = 0, int $marquePages = 0, array $plus = []): Adresse
{
    $a = Adresse::factory()->create($plus + ['bibliotheque_id' => $b->id, 'hexagone' => $hexagone, 'mur' => 1, 'etagere' => 1, 'volume' => 1, 'page' => 1]);
    foreach (User::factory()->count($votesPour)->create() as $u) {
        Vote::create(['utilisateur_id' => $u->id, 'adresse_id' => $a->id, 'valeur' => 1]);
    }
    foreach (User::factory()->count($votesContre)->create() as $u) {
        Vote::create(['utilisateur_id' => $u->id, 'adresse_id' => $a->id, 'valeur' => -1]);
    }
    foreach (User::factory()->count($marquePages)->create() as $u) {
        MarquePage::factory()->create(['utilisateur_id' => $u->id, 'adresse_id' => $a->id]);
    }

    return $a;
}

it('classe par score décroissant et renvoie bibliothèque, adresse, score (entier)', function () {
    $voisine = Bibliotheque::factory()->create(['identifiant' => 'voisine']);
    pageAvecScore($this->locale, 'a', votesPour: 2);
    pageAvecScore($voisine, 'b', votesPour: 3, marquePages: 2);
    pageAvecScore($this->locale, 'c', votesPour: 1, marquePages: 1);

    $r = $this->getJson('/api/v1/pages-populaires');

    $r->assertOk();
    expect(array_keys($r->json()))->toBe(['elements']);
    expect($r->json('elements'))->toBe([
        ['bibliotheque' => 'voisine', 'adresse' => 'b-w1-s1-v1:1', 'score' => 5],
        ['bibliotheque' => 'exemple', 'adresse' => 'a-w1-s1-v1:1', 'score' => 2],
        ['bibliotheque' => 'exemple', 'adresse' => 'c-w1-s1-v1:1', 'score' => 2],
    ]);
    expect(array_keys($r->json('elements.0')))->toBe(['bibliotheque', 'adresse', 'score']);
});

it('compte un vote contre comme -1', function () {
    pageAvecScore($this->locale, 'a', votesPour: 3, votesContre: 1);   // 3 - 1 = 2

    expect($this->getJson('/api/v1/pages-populaires')->json('elements.0.score'))->toBe(2);
});

it('n\'affiche pas les pages dont le score est nul ou négatif', function () {
    pageAvecScore($this->locale, 'a', votesPour: 1, votesContre: 1);     // 0
    pageAvecScore($this->locale, 'b', votesPour: 0, votesContre: 2);     // -2
    Adresse::factory()->create(['bibliotheque_id' => $this->locale->id]); // aucun vote
    pageAvecScore($this->locale, 'c', votesPour: 1);

    expect(array_column($this->getJson('/api/v1/pages-populaires')->json('elements'), 'adresse'))->toBe(['c-w1-s1-v1:1']);
});

it('n\'affiche pas une page marquée par un texte banni appliqué', function () {
    $banni = TexteBanni::factory()->applique()->create(['origine_id' => $this->locale->id]);
    pageAvecScore($this->locale, 'a', votesPour: 5, plus: ['texte_banni_id' => $banni->id]);
    pageAvecScore($this->locale, 'b', votesPour: 1);

    expect(array_column($this->getJson('/api/v1/pages-populaires')->json('elements'), 'adresse'))->toBe(['b-w1-s1-v1:1']);
});

it('n\'affiche pas un livre entier (une page, pas un livre)', function () {
    $livre = pageAvecScore($this->locale, 'a', votesPour: 3);
    $livre->forceFill(['page' => null])->save();

    expect($this->getJson('/api/v1/pages-populaires')->json('elements'))->toBe([]);
});

it('ne montre jamais qui a voté ou gardé la page', function () {
    $a = pageAvecScore($this->locale, 'a', votesPour: 1, marquePages: 1);
    $corps = $this->getJson('/api/v1/pages-populaires')->getContent();

    foreach (User::all() as $u) {
        expect($corps)->not->toContain($u->email)->not->toContain((string) $u->pseudo);
    }
    expect($a->exists)->toBeTrue();
});

it('respecte « taille » (au plus) et son défaut de 20', function () {
    foreach (range(1, 25) as $i) {
        pageAvecScore($this->locale, 'h'.$i, votesPour: 1);
    }

    expect($this->getJson('/api/v1/pages-populaires?taille=3')->json('elements'))->toHaveCount(3);
    expect($this->getJson('/api/v1/pages-populaires')->json('elements'))->toHaveCount(20);
    expect($this->getJson('/api/v1/pages-populaires?taille=50')->json('elements'))->toHaveCount(25);
});

it('refuse une taille mal écrite ou hors limites : 422 ERR_INVALID_PARAMETER', function (string $taille) {
    attendErreurContrat($this->getJson('/api/v1/pages-populaires?taille='.$taille), 422, 'ERR_INVALID_PARAMETER', 'taille');
})->with(['0', '51', 'abc', '2.5', '-1']);

it('renvoie une liste vide quand il n\'y a rien', function () {
    $this->getJson('/api/v1/pages-populaires')->assertOk()->assertExactJson(['elements' => []]);
});

it('donne un ordre stable à score égal', function () {
    foreach (['a', 'b', 'c'] as $h) {
        pageAvecScore($this->locale, $h, votesPour: 2);
    }

    $premier = $this->getJson('/api/v1/pages-populaires')->json('elements');

    expect($this->getJson('/api/v1/pages-populaires')->json('elements'))->toBe($premier);
});
