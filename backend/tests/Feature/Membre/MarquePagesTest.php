<?php

use App\Models\MarquePage;

require_once __DIR__.'/Aide.php';

/*
 * /app/moi/marque-pages (M2, M3, M13) : voir docs/openapi.yaml, tag « Marque-pages ».
 */
beforeEach(function () {
    $this->biblios = emBibliotheques();
    $this->membre = emConnecte();
});

it('répond 401 ERR_UNAUTHENTICATED sans jeton', function () {
    $this->refreshApplication();
    foreach ([['getJson', ''], ['postJson', ''], ['patchJson', '/1'], ['deleteJson', '/1']] as [$methode, $suite]) {
        attendErreurContrat($this->$methode('/api/v1/app/moi/marque-pages'.$suite, []), 401, 'ERR_UNAUTHENTICATED', null);
    }
});

// ---- POST ----------------------------------------------------------------------------------------

it('garde une page d\'ici (201, schéma MarquePage exact)', function () {
    $r = $this->postJson('/api/v1/app/moi/marque-pages', ['adresse' => '0-w1-s1-v1:1', 'titre' => 'Le début']);

    $r->assertCreated();
    expect(emCles($r))->toBe(['id', 'bibliotheque', 'adresse', 'titre', 'a_convertir', 'cree_le']);
    expect($r->json('bibliotheque'))->toBe('exemple')
        ->and($r->json('adresse'))->toBe('0-w1-s1-v1:1')
        ->and($r->json('titre'))->toBe('Le début')
        ->and($r->json('a_convertir'))->toBeFalse()
        ->and($r->json('id'))->toBeString();
    expect($this->membre->marquePages()->count())->toBe(1);
});

it('garde une page sans titre : « titre » vaut null', function () {
    $this->postJson('/api/v1/app/moi/marque-pages', ['adresse' => 'babel-w2-s3-v17:204'])
        ->assertCreated()->assertJsonPath('titre', null);
});

it('garde une page d\'une autre bibliothèque du réseau (M13)', function () {
    $r = $this->postJson('/api/v1/app/moi/marque-pages', ['bibliotheque' => 'babel-5', 'adresse' => '0-w1-s1-v1:1']);

    $r->assertCreated()->assertJsonPath('bibliotheque', 'babel-5')->assertJsonPath('a_convertir', false);
});

it('répond 404 ERR_UNKNOWN_LIBRARY pour une bibliothèque hors du réseau', function () {
    attendErreurContrat(
        $this->postJson('/api/v1/app/moi/marque-pages', ['bibliotheque' => 'inconnue', 'adresse' => '0-w1-s1-v1:1']),
        404, 'ERR_UNKNOWN_LIBRARY', 'bibliotheque',
    );
});

it('répond 409 ERR_ALREADY_EXISTS quand la page est déjà gardée (règle 7)', function () {
    $this->postJson('/api/v1/app/moi/marque-pages', ['adresse' => '0-w1-s1-v1:1'])->assertCreated();

    attendErreurContrat($this->postJson('/api/v1/app/moi/marque-pages', ['adresse' => '0-w1-s1-v1:1']), 409, 'ERR_ALREADY_EXISTS', 'adresse');
    expect($this->membre->marquePages()->count())->toBe(1);
});

it('accepte la même adresse dans une autre bibliothèque (ce n\'est pas la même page)', function () {
    $this->postJson('/api/v1/app/moi/marque-pages', ['adresse' => '0-w1-s1-v1:1'])->assertCreated();
    $this->postJson('/api/v1/app/moi/marque-pages', ['bibliotheque' => 'babel-5', 'adresse' => '0-w1-s1-v1:1'])->assertCreated();
});

it('laisse deux membres garder la même page', function () {
    $this->postJson('/api/v1/app/moi/marque-pages', ['adresse' => '0-w1-s1-v1:1'])->assertCreated();
    emConnecte();

    $this->postJson('/api/v1/app/moi/marque-pages', ['adresse' => '0-w1-s1-v1:1'])->assertCreated();
});

it('refuse une adresse mal écrite avec les erreurs du moteur (422)', function () {
    $r = $this->postJson('/api/v1/app/moi/marque-pages', ['adresse' => 'ABC-w9-s1-v1:1']);

    $r->assertStatus(422);
    expect(array_column($r->json('erreurs'), 'code'))->toBe(['ERR_INVALID_HEX_CHARS', 'ERR_OUT_OF_BOUNDS']);
    expect($this->membre->marquePages()->count())->toBe(0);
});

it('répond 404 ERR_OUT_OF_BOUNDS quand l\'adresse est bien écrite mais n\'existe pas', function () {
    attendErreurContrat($this->postJson('/api/v1/app/moi/marque-pages', ['adresse' => '0-w1-s1-v33:1']), 404, 'ERR_OUT_OF_BOUNDS', 'volume');
});

it('refuse l\'adresse d\'un livre : un marque-page vise toujours une page (règle 4)', function () {
    attendErreurContrat($this->postJson('/api/v1/app/moi/marque-pages', ['adresse' => '0-w1-s1-v1']), 422, 'ERR_INVALID_ADDRESS_SYNTAX', 'page');
});

it('refuse une adresse « corrigée » : espaces, majuscules et zéros de tête ne sont pas arrangés', function () {
    foreach ([' 0-w1-s1-v1:1', '0-W1-s1-v1:1', '0-w01-s1-v1:1'] as $adresse) {
        $this->postJson('/api/v1/app/moi/marque-pages', ['adresse' => $adresse])->assertStatus(422);
    }
    expect($this->membre->marquePages()->count())->toBe(0);
});

it('refuse un corps incomplet ou mal typé avec ERR_INVALID_PARAMETER', function () {
    attendErreurContrat($this->postJson('/api/v1/app/moi/marque-pages', []), 422, 'ERR_INVALID_PARAMETER', 'adresse');
    attendErreurContrat($this->postJson('/api/v1/app/moi/marque-pages', ['adresse' => 12]), 422, 'ERR_INVALID_PARAMETER', 'adresse');
    attendErreurContrat($this->postJson('/api/v1/app/moi/marque-pages', ['adresse' => '0-w1-s1-v1:1', 'titre' => str_repeat('a', 101)]), 422, 'ERR_INVALID_PARAMETER', 'titre');
    attendErreurContrat($this->postJson('/api/v1/app/moi/marque-pages', ['adresse' => '0-w1-s1-v1:1', 'bibliotheque' => 'Pas Valide']), 422, 'ERR_INVALID_PARAMETER', 'bibliotheque');
});

// ---- GET -----------------------------------------------------------------------------------------

it('liste mes marque-pages, du plus récent au plus ancien, sans ceux des autres', function () {
    $a = emAdresse('0-w1-s1-v1:1');
    $b = emAdresse('0-w1-s1-v1:2');
    MarquePage::factory()->create(['utilisateur_id' => $this->membre->id, 'adresse_id' => $a->id, 'cree_le' => '2026-11-01 10:00:00']);
    MarquePage::factory()->create(['utilisateur_id' => $this->membre->id, 'adresse_id' => $b->id, 'cree_le' => '2026-11-02 10:00:00']);
    MarquePage::factory()->create(['adresse_id' => $a->id]); // celui d'un autre membre

    $r = $this->getJson('/api/v1/app/moi/marque-pages');

    $r->assertOk();
    expect(emCles($r))->toBe(['lot', 'taille', 'total', 'elements']);
    expect($r->json('lot'))->toBe(1)->and($r->json('taille'))->toBe(20)->and($r->json('total'))->toBe(2);
    expect(array_column($r->json('elements'), 'adresse'))->toBe(['0-w1-s1-v1:2', '0-w1-s1-v1:1']);
    expect(emCles($r, 'elements.0'))->toBe(['id', 'bibliotheque', 'adresse', 'titre', 'a_convertir', 'cree_le']);
});

it('découpe la liste en lots', function () {
    foreach (range(1, 5) as $page) {
        MarquePage::factory()->create(['utilisateur_id' => $this->membre->id, 'adresse_id' => emAdresse("0-w1-s1-v1:$page")->id, 'cree_le' => "2026-11-0$page 10:00:00"]);
    }

    $r = $this->getJson('/api/v1/app/moi/marque-pages?taille=2&lot=3');

    expect($r->json('lot'))->toBe(3)->and($r->json('taille'))->toBe(2)->and($r->json('total'))->toBe(5);
    expect(array_column($r->json('elements'), 'adresse'))->toBe(['0-w1-s1-v1:1']);
});

it('refuse un lot ou une taille hors limites (422)', function () {
    attendErreurContrat($this->getJson('/api/v1/app/moi/marque-pages?taille=51'), 422, 'ERR_INVALID_PARAMETER', 'taille');
    attendErreurContrat($this->getJson('/api/v1/app/moi/marque-pages?lot=0'), 422, 'ERR_INVALID_PARAMETER', 'lot');
    attendErreurContrat($this->getJson('/api/v1/app/moi/marque-pages?lot=abc'), 422, 'ERR_INVALID_PARAMETER', 'lot');
});

it('filtre par bibliothèque', function () {
    MarquePage::factory()->create(['utilisateur_id' => $this->membre->id, 'adresse_id' => emAdresse('0-w1-s1-v1:1')->id]);
    MarquePage::factory()->create(['utilisateur_id' => $this->membre->id, 'adresse_id' => emAdresse('0-w1-s1-v1:1', $this->biblios['autre'])->id]);

    $r = $this->getJson('/api/v1/app/moi/marque-pages?bibliotheque=babel-5');

    expect($r->json('total'))->toBe(1)->and($r->json('elements.0.bibliotheque'))->toBe('babel-5');
});

it('filtre par livre : tous les marque-pages d\'un même livre (règle 3)', function () {
    foreach (['0-w1-s1-v1:1', '0-w1-s1-v1:200', '0-w1-s1-v2:1'] as $adresse) {
        MarquePage::factory()->create(['utilisateur_id' => $this->membre->id, 'adresse_id' => emAdresse($adresse)->id]);
    }
    MarquePage::factory()->create(['utilisateur_id' => $this->membre->id, 'adresse_id' => emAdresse('0-w1-s1-v1:7', $this->biblios['autre'])->id]);

    $r = $this->getJson('/api/v1/app/moi/marque-pages?livre=0-w1-s1-v1');

    expect(array_column($r->json('elements'), 'adresse'))->toEqualCanonicalizing(['0-w1-s1-v1:1', '0-w1-s1-v1:200']);

    // Le livre d'une autre bibliothèque se demande avec sa bibliothèque.
    $r = $this->getJson('/api/v1/app/moi/marque-pages?livre=0-w1-s1-v1&bibliotheque=babel-5');
    expect(array_column($r->json('elements'), 'adresse'))->toBe(['0-w1-s1-v1:7']);
});

it('refuse un filtre « livre » qui n\'est pas une adresse de livre', function () {
    attendErreurContrat($this->getJson('/api/v1/app/moi/marque-pages?livre=0-w1-s1-v1:1'), 422, 'ERR_INVALID_PARAMETER', 'livre');
    attendErreurContrat($this->getJson('/api/v1/app/moi/marque-pages?livre=nimporte'), 422, 'ERR_INVALID_PARAMETER', 'livre');
});

// ---- PATCH / DELETE ------------------------------------------------------------------------------

it('renomme un marque-page, et « null » retire le titre', function () {
    $mp = MarquePage::factory()->create(['utilisateur_id' => $this->membre->id, 'adresse_id' => emAdresse()->id, 'titre' => 'Avant']);

    $this->patchJson("/api/v1/app/moi/marque-pages/{$mp->id}", ['titre' => 'Après'])
        ->assertOk()->assertJsonPath('titre', 'Après')->assertJsonPath('id', (string) $mp->id);
    $this->patchJson("/api/v1/app/moi/marque-pages/{$mp->id}", ['titre' => null])->assertOk()->assertJsonPath('titre', null);
    expect($mp->fresh()->titre)->toBeNull();
});

it('refuse un PATCH sans « titre » ou trop long (422)', function () {
    $mp = MarquePage::factory()->create(['utilisateur_id' => $this->membre->id, 'adresse_id' => emAdresse()->id]);

    attendErreurContrat($this->patchJson("/api/v1/app/moi/marque-pages/{$mp->id}", []), 422, 'ERR_INVALID_PARAMETER', 'titre');
    attendErreurContrat($this->patchJson("/api/v1/app/moi/marque-pages/{$mp->id}", ['titre' => str_repeat('a', 101)]), 422, 'ERR_INVALID_PARAMETER', 'titre');
});

it('répond 404 pour le marque-page d\'un autre membre ou un id inconnu (jamais 403)', function () {
    $autre = MarquePage::factory()->create(['adresse_id' => emAdresse()->id, 'titre' => 'Secret']);

    attendErreurContrat($this->patchJson("/api/v1/app/moi/marque-pages/{$autre->id}", ['titre' => 'Pris']), 404, 'ERR_NOT_FOUND', 'id');
    attendErreurContrat($this->deleteJson("/api/v1/app/moi/marque-pages/{$autre->id}"), 404, 'ERR_NOT_FOUND', 'id');
    attendErreurContrat($this->deleteJson('/api/v1/app/moi/marque-pages/99999'), 404, 'ERR_NOT_FOUND', 'id');
    attendErreurContrat($this->deleteJson('/api/v1/app/moi/marque-pages/12abc'), 404, 'ERR_NOT_FOUND', 'id');
    expect($autre->fresh()->titre)->toBe('Secret');
});

it('retire un marque-page (204)', function () {
    $mp = MarquePage::factory()->create(['utilisateur_id' => $this->membre->id, 'adresse_id' => emAdresse()->id]);

    $this->deleteJson("/api/v1/app/moi/marque-pages/{$mp->id}")->assertNoContent();

    expect(MarquePage::query()->count())->toBe(0);
});

it('refuse un membre suspendu (403 ERR_ACCOUNT_SUSPENDED)', function () {
    $this->membre->forceFill(['suspendu_le' => now()])->save();

    attendErreurContrat($this->getJson('/api/v1/app/moi/marque-pages'), 403, 'ERR_ACCOUNT_SUSPENDED', null);
});
