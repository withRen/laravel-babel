<?php

use App\Models\Recherche;
use App\Models\User;

require_once __DIR__.'/Aide.php';

/*
 * /app/moi/recherches (M8) : l'historique des recherches du membre.
 */
beforeEach(function () {
    $this->membre = emConnecte();
});

it('répond 401 ERR_UNAUTHENTICATED sans jeton', function () {
    $this->refreshApplication();

    attendErreurContrat($this->getJson('/api/v1/app/moi/recherches'), 401, 'ERR_UNAUTHENTICATED', null);
    attendErreurContrat($this->deleteJson('/api/v1/app/moi/recherches'), 401, 'ERR_UNAUTHENTICATED', null);
    attendErreurContrat($this->deleteJson('/api/v1/app/moi/recherches/1'), 401, 'ERR_UNAUTHENTICATED', null);
});

it('liste mes recherches, de la plus récente à la plus ancienne, sans celles des autres (schéma exact)', function () {
    Recherche::factory()->create(['utilisateur_id' => $this->membre->id, 'texte' => 'ancienne', 'mode' => 'exact', 'portee' => 'locale', 'cree_le' => '2026-11-01 10:00:00']);
    Recherche::factory()->create(['utilisateur_id' => $this->membre->id, 'texte' => 'récente', 'mode' => 'bruit', 'portee' => 'reseau', 'cree_le' => '2026-11-02 10:00:00']);
    Recherche::factory()->create(['utilisateur_id' => User::factory()->create()->id, 'texte' => 'd un autre']);

    $r = $this->getJson('/api/v1/app/moi/recherches');

    $r->assertOk();
    expect(emCles($r))->toBe(['lot', 'taille', 'total', 'elements']);
    expect($r->json('total'))->toBe(2);
    expect(emCles($r, 'elements.0'))->toBe(['id', 'texte', 'mode', 'portee', 'cree_le']);
    expect($r->json('elements.0'))->toMatchArray(['texte' => 'récente', 'mode' => 'bruit', 'portee' => 'reseau']);
    expect($r->json('elements.0.id'))->toBeString();
    expect(array_column($r->json('elements'), 'texte'))->toBe(['récente', 'ancienne']);
});

it('découpe en lots et refuse les paramètres hors limites', function () {
    Recherche::factory()->count(3)->create(['utilisateur_id' => $this->membre->id]);

    $r = $this->getJson('/api/v1/app/moi/recherches?taille=2&lot=2');

    expect($r->json('total'))->toBe(3)->and($r->json('elements'))->toHaveCount(1);
    attendErreurContrat($this->getJson('/api/v1/app/moi/recherches?taille=51'), 422, 'ERR_INVALID_PARAMETER', 'taille');
});

it('efface une recherche (204), et seulement la mienne (404 sinon)', function () {
    $mienne = Recherche::factory()->create(['utilisateur_id' => $this->membre->id]);
    $autre = Recherche::factory()->create();

    attendErreurContrat($this->deleteJson("/api/v1/app/moi/recherches/{$autre->id}"), 404, 'ERR_NOT_FOUND', 'id');
    attendErreurContrat($this->deleteJson('/api/v1/app/moi/recherches/abc'), 404, 'ERR_NOT_FOUND', 'id');
    $this->deleteJson("/api/v1/app/moi/recherches/{$mienne->id}")->assertNoContent();

    expect(Recherche::query()->count())->toBe(1)->and(Recherche::query()->first()->id)->toBe($autre->id);
});

it('vide tout mon historique sans toucher à celui des autres (204)', function () {
    Recherche::factory()->count(3)->create(['utilisateur_id' => $this->membre->id]);
    $autre = Recherche::factory()->create();

    $this->deleteJson('/api/v1/app/moi/recherches')->assertNoContent();

    expect(Recherche::query()->count())->toBe(1)->and(Recherche::query()->first()->id)->toBe($autre->id);
    $this->deleteJson('/api/v1/app/moi/recherches')->assertNoContent(); // déjà vide : même réponse
});
