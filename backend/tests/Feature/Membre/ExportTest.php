<?php

use App\Models\Annotation;
use App\Models\Collection;
use App\Models\LienCourt;
use App\Models\MarquePage;
use App\Models\Recherche;
use App\Models\User;
use App\Models\Vote;

require_once __DIR__.'/Aide.php';

/*
 * GET /app/moi/export (M11) : le fichier « babel-export/v1 ».
 */
beforeEach(function () {
    $this->biblios = emBibliotheques();
    $this->membre = emConnecte(User::factory()->create(['pseudo' => 'ada', 'email' => 'ada@babel.test', 'cree_le' => '2026-10-01 09:00:00']));
});

/** Un membre avec un peu de tout, dont une page d'une autre bibliothèque. */
function emRemplir(User $membre): void
{
    $biblios = emBibliotheques();
    MarquePage::factory()->create(['utilisateur_id' => $membre->id, 'adresse_id' => emAdresse('0-w1-s1-v1:1')->id, 'titre' => 'Le début', 'cree_le' => '2026-11-01 10:00:00']);
    MarquePage::factory()->create(['utilisateur_id' => $membre->id, 'adresse_id' => emAdresse('0-w1-s1-v1:2', $biblios['autre'])->id, 'titre' => null, 'cree_le' => '2026-11-02 10:00:00']);

    $collection = Collection::factory()->create(['utilisateur_id' => $membre->id, 'nom' => 'Ma liste', 'description' => 'Pour lire', 'est_publique' => true, 'cree_le' => '2026-11-03 10:00:00']);
    $collection->pages()->create(['position' => 1, 'adresse_id' => emAdresse('0-w1-s1-v1:3')->id]);
    $collection->pages()->create(['position' => 2, 'adresse_id' => emAdresse('0-w1-s1-v1:2', $biblios['autre'])->id]);

    Annotation::factory()->create(['utilisateur_id' => $membre->id, 'adresse_id' => emAdresse('0-w1-s1-v1:1')->id, 'contenu' => 'Étrange.', 'est_publique' => true, 'cree_le' => '2026-11-04 10:00:00', 'maj_le' => '2026-11-05 10:00:00']);
    Vote::query()->insert(['utilisateur_id' => $membre->id, 'adresse_id' => emAdresse('0-w1-s1-v1:1')->id, 'valeur' => -1, 'cree_le' => '2026-11-06 10:00:00']);
    Recherche::factory()->create(['utilisateur_id' => $membre->id, 'texte' => 'bonjour', 'mode' => 'exact', 'portee' => 'reseau', 'cree_le' => '2026-11-07 10:00:00']);
    LienCourt::factory()->create(['utilisateur_id' => $membre->id, 'adresse_id' => emAdresse('0-w1-s1-v1:1')->id, 'collection_id' => null, 'slug' => 'aaaa-'.$membre->id, 'passage' => 'le début', 'visites' => 4, 'expire_le' => null]);
    LienCourt::factory()->create(['utilisateur_id' => $membre->id, 'adresse_id' => null, 'collection_id' => $collection->id, 'slug' => 'bbbb-'.$membre->id, 'passage' => null, 'visites' => 0, 'expire_le' => null]);
}

it('répond 401 ERR_UNAUTHENTICATED sans jeton', function () {
    $this->refreshApplication();

    attendErreurContrat($this->getJson('/api/v1/app/moi/export'), 401, 'ERR_UNAUTHENTICATED', null);
});

it('exporte un compte vide : les listes sont vides et le format est babel-export/v1', function () {
    $r = $this->getJson('/api/v1/app/moi/export');

    $r->assertOk();
    expect(emCles($r))->toBe(['format', 'exporte_le', 'bibliotheque', 'profil', 'marque_pages', 'collections', 'annotations', 'votes', 'recherches', 'liens_courts']);
    expect($r->json('format'))->toBe('babel-export/v1')
        ->and($r->json('bibliotheque'))->toBe('exemple')
        ->and($r->json('profil'))->toBe(['pseudo' => 'ada', 'email' => 'ada@babel.test', 'cree_le' => '2026-10-01T09:00:00+00:00'])
        ->and($r->json('marque_pages'))->toBe([])
        ->and($r->json('liens_courts'))->toBe([]);
});

it('porte un Content-Disposition pour que le navigateur télécharge le fichier', function () {
    $r = $this->getJson('/api/v1/app/moi/export');

    expect($r->headers->get('Content-Disposition'))->toBe('attachment; filename="babel-export-exemple-'.now()->format('Y-m-d').'.json"');
});

it('exporte tout, chaque adresse avec l\'identifiant de sa bibliothèque', function () {
    emRemplir($this->membre);

    $r = $this->getJson('/api/v1/app/moi/export');

    expect($r->json('marque_pages'))->toBe([
        ['bibliotheque' => 'exemple', 'adresse' => '0-w1-s1-v1:1', 'titre' => 'Le début', 'cree_le' => '2026-11-01T10:00:00+00:00'],
        ['bibliotheque' => 'babel-5', 'adresse' => '0-w1-s1-v1:2', 'titre' => null, 'cree_le' => '2026-11-02T10:00:00+00:00'],
    ]);
    expect($r->json('collections'))->toBe([[
        'nom' => 'Ma liste', 'description' => 'Pour lire', 'est_publique' => true,
        'pages' => [
            ['position' => 1, 'bibliotheque' => 'exemple', 'adresse' => '0-w1-s1-v1:3'],
            ['position' => 2, 'bibliotheque' => 'babel-5', 'adresse' => '0-w1-s1-v1:2'],
        ],
        'cree_le' => '2026-11-03T10:00:00+00:00',
    ]]);
    expect($r->json('annotations'))->toBe([[
        'bibliotheque' => 'exemple', 'adresse' => '0-w1-s1-v1:1', 'contenu' => 'Étrange.', 'est_publique' => true,
        'cree_le' => '2026-11-04T10:00:00+00:00', 'maj_le' => '2026-11-05T10:00:00+00:00',
    ]]);
    expect($r->json('votes'))->toBe([['bibliotheque' => 'exemple', 'adresse' => '0-w1-s1-v1:1', 'valeur' => -1, 'cree_le' => '2026-11-06T10:00:00+00:00']]);
    expect($r->json('recherches'))->toBe([['texte' => 'bonjour', 'mode' => 'exact', 'portee' => 'reseau', 'cree_le' => '2026-11-07T10:00:00+00:00']]);
});

it('désigne la cible d\'un lien court : une adresse, ou une collection par son rang dans le fichier', function () {
    emRemplir($this->membre);

    $liens = $this->getJson('/api/v1/app/moi/export')->json('liens_courts');

    expect($liens)->toHaveCount(2);
    expect($liens[0]['cible'])->toBe(['type' => 'adresse', 'bibliotheque' => 'exemple', 'adresse' => '0-w1-s1-v1:1']);
    expect($liens[0])->toMatchArray(['slug' => 'aaaa-'.$this->membre->id, 'passage' => 'le début', 'visites' => 4, 'expire_le' => null]);
    expect($liens[1]['cible'])->toBe(['type' => 'collection', 'collection' => 0]);
    expect($liens[1]['passage'])->toBeNull();
});

it('n\'exporte jamais le mot de passe ni les données des autres membres', function () {
    emRemplir($this->membre);
    emRemplir(User::factory()->create());

    $r = $this->getJson('/api/v1/app/moi/export');

    expect($r->json('marque_pages'))->toHaveCount(2)->and($r->json('collections'))->toHaveCount(1)
        ->and($r->json('liens_courts'))->toHaveCount(2);
    $texte = $r->getContent();
    expect($texte)->not->toContain('password')->not->toContain('mot_de_passe')->not->toContain($this->membre->password);
});
