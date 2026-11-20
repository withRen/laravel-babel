<?php

use App\Enums\StatutTexteBanni;
use App\Models\Annotation;
use App\Models\Signalement;
use App\Models\TexteBanni;
use Babel\Moteur\Moteur;

require_once __DIR__.'/Aide.php';

/*
 * /app/moi/annotations (M6) : voir docs/openapi.yaml, tag « Annotations ».
 */
beforeEach(function () {
    $this->biblios = emBibliotheques();
    $this->membre = emConnecte();
});

it('répond 401 ERR_UNAUTHENTICATED sans jeton', function () {
    $this->refreshApplication();

    attendErreurContrat($this->getJson('/api/v1/app/moi/annotations'), 401, 'ERR_UNAUTHENTICATED', null);
    attendErreurContrat($this->postJson('/api/v1/app/moi/annotations', []), 401, 'ERR_UNAUTHENTICATED', null);
    attendErreurContrat($this->patchJson('/api/v1/app/moi/annotations/1', []), 401, 'ERR_UNAUTHENTICATED', null);
    attendErreurContrat($this->deleteJson('/api/v1/app/moi/annotations/1'), 401, 'ERR_UNAUTHENTICATED', null);
});

it('annote une page en privé (201, schéma Annotation exact)', function () {
    $r = $this->postJson('/api/v1/app/moi/annotations', ['adresse' => '0-w1-s1-v1:1', 'contenu' => 'Une page étrange.', 'est_publique' => false]);

    $r->assertCreated();
    expect(emCles($r))->toBe(['id', 'bibliotheque', 'adresse', 'contenu', 'est_publique', 'masquee', 'a_convertir', 'auteur', 'est_a_moi', 'cree_le', 'maj_le']);
    expect(emCles($r, 'auteur'))->toBe(['pseudo']);
    expect($r->json('bibliotheque'))->toBe('exemple')
        ->and($r->json('adresse'))->toBe('0-w1-s1-v1:1')
        ->and($r->json('contenu'))->toBe('Une page étrange.')
        ->and($r->json('est_publique'))->toBeFalse()
        ->and($r->json('masquee'))->toBeFalse()
        ->and($r->json('a_convertir'))->toBeFalse()
        ->and($r->json('auteur.pseudo'))->toBe($this->membre->pseudo)
        ->and($r->json('est_a_moi'))->toBeTrue();
});

it('annote en public une page d\'une autre bibliothèque (M13)', function () {
    $this->postJson('/api/v1/app/moi/annotations', ['bibliotheque' => 'babel-5', 'adresse' => '0-w1-s1-v1:1', 'contenu' => 'Chez les voisins.', 'est_publique' => true])
        ->assertCreated()->assertJsonPath('bibliotheque', 'babel-5')->assertJsonPath('est_publique', true);
});

it('accepte 2 000 caractères et refuse 2 001 (règle 9)', function () {
    $this->postJson('/api/v1/app/moi/annotations', ['adresse' => '0-w1-s1-v1:1', 'contenu' => str_repeat('é', 2000), 'est_publique' => false])->assertCreated();

    attendErreurContrat(
        $this->postJson('/api/v1/app/moi/annotations', ['adresse' => '0-w1-s1-v1:1', 'contenu' => str_repeat('é', 2001), 'est_publique' => false]),
        422, 'ERR_INVALID_PARAMETER', 'contenu',
    );
});

it('refuse une annotation incomplète ou mal typée (422)', function () {
    attendErreurContrat($this->postJson('/api/v1/app/moi/annotations', ['contenu' => 'x', 'est_publique' => false]), 422, 'ERR_INVALID_PARAMETER', 'adresse');
    attendErreurContrat($this->postJson('/api/v1/app/moi/annotations', ['adresse' => '0-w1-s1-v1:1', 'est_publique' => false]), 422, 'ERR_INVALID_PARAMETER', 'contenu');
    attendErreurContrat($this->postJson('/api/v1/app/moi/annotations', ['adresse' => '0-w1-s1-v1:1', 'contenu' => '   ', 'est_publique' => false]), 422, 'ERR_INVALID_PARAMETER', 'contenu');
    attendErreurContrat($this->postJson('/api/v1/app/moi/annotations', ['adresse' => '0-w1-s1-v1:1', 'contenu' => 'x']), 422, 'ERR_INVALID_PARAMETER', 'est_publique');
    attendErreurContrat($this->postJson('/api/v1/app/moi/annotations', ['adresse' => '0-w1-s1-v1:1', 'contenu' => 'x', 'est_publique' => 'true']), 422, 'ERR_INVALID_PARAMETER', 'est_publique');
    expect(Annotation::query()->count())->toBe(0);
});

it('contrôle l\'adresse et la bibliothèque comme les autres routes', function () {
    $corps = ['contenu' => 'x', 'est_publique' => false];

    attendErreurContrat($this->postJson('/api/v1/app/moi/annotations', $corps + ['adresse' => '0-w1-s1-v33:1']), 404, 'ERR_OUT_OF_BOUNDS', 'volume');
    attendErreurContrat($this->postJson('/api/v1/app/moi/annotations', $corps + ['adresse' => '0-w1-s1-v1']), 422, 'ERR_INVALID_ADDRESS_SYNTAX', 'page');
    attendErreurContrat($this->postJson('/api/v1/app/moi/annotations', $corps + ['adresse' => '0-w1-s1-v1:1', 'bibliotheque' => 'inconnue']), 404, 'ERR_UNKNOWN_LIBRARY', 'bibliotheque');
});

it('liste mes annotations, publiques et privées, de la plus récente à la plus ancienne, sans celles des autres', function () {
    $page = emAdresse('0-w1-s1-v1:1');
    Annotation::factory()->create(['utilisateur_id' => $this->membre->id, 'adresse_id' => $page->id, 'contenu' => 'Ancienne', 'est_publique' => true, 'cree_le' => '2026-11-01 10:00:00']);
    Annotation::factory()->create(['utilisateur_id' => $this->membre->id, 'adresse_id' => $page->id, 'contenu' => 'Récente', 'est_publique' => false, 'cree_le' => '2026-11-02 10:00:00']);
    Annotation::factory()->publique()->create(['adresse_id' => $page->id, 'contenu' => 'D\'un autre']);

    $r = $this->getJson('/api/v1/app/moi/annotations');

    $r->assertOk();
    expect(emCles($r))->toBe(['lot', 'taille', 'total', 'elements']);
    expect($r->json('total'))->toBe(2);
    expect(array_column($r->json('elements'), 'contenu'))->toBe(['Récente', 'Ancienne']);
    expect(array_unique(array_column($r->json('elements'), 'est_a_moi')))->toBe([true]);
});

it('refuse un lot ou une taille hors limites sur la liste', function () {
    attendErreurContrat($this->getJson('/api/v1/app/moi/annotations?taille=500'), 422, 'ERR_INVALID_PARAMETER', 'taille');
});

it('modifie le contenu et la visibilité, seulement ce qui est envoyé', function () {
    $a = Annotation::factory()->create(['utilisateur_id' => $this->membre->id, 'adresse_id' => emAdresse()->id, 'contenu' => 'Avant', 'est_publique' => false]);

    $this->patchJson("/api/v1/app/moi/annotations/{$a->id}", ['contenu' => 'Après'])
        ->assertOk()->assertJsonPath('contenu', 'Après')->assertJsonPath('est_publique', false);
    $this->patchJson("/api/v1/app/moi/annotations/{$a->id}", ['est_publique' => true])
        ->assertOk()->assertJsonPath('contenu', 'Après')->assertJsonPath('est_publique', true);
});

it('refuse une modification vide ou invalide (422), et ne change jamais la page', function () {
    $a = Annotation::factory()->create(['utilisateur_id' => $this->membre->id, 'adresse_id' => emAdresse()->id]);

    attendErreurContrat($this->patchJson("/api/v1/app/moi/annotations/{$a->id}", []), 422, 'ERR_INVALID_PARAMETER', null);
    attendErreurContrat($this->patchJson("/api/v1/app/moi/annotations/{$a->id}", ['contenu' => '']), 422, 'ERR_INVALID_PARAMETER', 'contenu');
    attendErreurContrat($this->patchJson("/api/v1/app/moi/annotations/{$a->id}", ['contenu' => str_repeat('a', 2001)]), 422, 'ERR_INVALID_PARAMETER', 'contenu');
    attendErreurContrat($this->patchJson("/api/v1/app/moi/annotations/{$a->id}", ['est_publique' => 1]), 422, 'ERR_INVALID_PARAMETER', 'est_publique');

    $this->patchJson("/api/v1/app/moi/annotations/{$a->id}", ['contenu' => 'ok', 'adresse' => '0-w1-s1-v1:99'])->assertOk()->assertJsonPath('adresse', '0-w1-s1-v1:1');
});

it('répond 404 ERR_NOT_FOUND pour l\'annotation d\'un autre membre, même publique (jamais 403)', function () {
    $autre = Annotation::factory()->publique()->create(['adresse_id' => emAdresse()->id, 'contenu' => 'Intacte']);

    attendErreurContrat($this->patchJson("/api/v1/app/moi/annotations/{$autre->id}", ['contenu' => 'Piratée']), 404, 'ERR_NOT_FOUND', 'id');
    attendErreurContrat($this->deleteJson("/api/v1/app/moi/annotations/{$autre->id}"), 404, 'ERR_NOT_FOUND', 'id');
    attendErreurContrat($this->deleteJson('/api/v1/app/moi/annotations/inconnue'), 404, 'ERR_NOT_FOUND', 'id');
    expect($autre->fresh()->contenu)->toBe('Intacte');
});

it('supprime l\'annotation, et ses signalements avec elle (204, règle 13)', function () {
    $a = Annotation::factory()->publique()->create(['utilisateur_id' => $this->membre->id, 'adresse_id' => emAdresse()->id]);
    Signalement::factory()->create(['annotation_id' => $a->id, 'collection_id' => null, 'lien_court_id' => null]);

    $this->deleteJson("/api/v1/app/moi/annotations/{$a->id}")->assertNoContent();

    expect(Annotation::query()->count())->toBe(0)->and(Signalement::query()->count())->toBe(0);
});

// ---- le contrôle des textes bannis à la publication --------------------------------------------------------------

it('masque l\'annotation PUBLIQUE posée sur une page qui contient un texte banni appliqué : seul son auteur la voit', function () {
    $page = app(Moteur::class)->lire('0-w1-s1-v1:1');
    $banni = TexteBanni::factory()->create(['texte' => trim(substr($page, 0, 40)), 'statut' => StatutTexteBanni::Applique]);

    $r = $this->postJson('/api/v1/app/moi/annotations', ['adresse' => '0-w1-s1-v1:1', 'contenu' => 'Publique', 'est_publique' => true]);

    $r->assertCreated()->assertJsonPath('masquee', true)->assertJsonPath('est_publique', true);
    expect(emAdresse('0-w1-s1-v1:1')->fresh()->texte_banni_id)->toBe($banni->id);
    // L'auteur la retrouve dans sa liste, marquée.
    $this->getJson('/api/v1/app/moi/annotations')->assertJsonPath('elements.0.masquee', true);
});

it('ne marque pas une annotation PRIVÉE, qui ne se publie pas, ni une page propre', function () {
    $page = app(Moteur::class)->lire('0-w1-s1-v1:1');
    TexteBanni::factory()->create(['texte' => trim(substr($page, 0, 40)), 'statut' => StatutTexteBanni::Applique]);

    $this->postJson('/api/v1/app/moi/annotations', ['adresse' => '0-w1-s1-v1:1', 'contenu' => 'Privée', 'est_publique' => false])
        ->assertCreated()->assertJsonPath('masquee', false);
    $this->postJson('/api/v1/app/moi/annotations', ['adresse' => '0-w1-s1-v1:2', 'contenu' => 'Publique, page propre', 'est_publique' => true])
        ->assertCreated()->assertJsonPath('masquee', false);
});

it('contrôle la page quand l\'annotation devient publique', function () {
    $page = app(Moteur::class)->lire('0-w1-s1-v1:1');
    TexteBanni::factory()->create(['texte' => trim(substr($page, 0, 40)), 'statut' => StatutTexteBanni::Applique]);
    $a = Annotation::factory()->create(['utilisateur_id' => $this->membre->id, 'adresse_id' => emAdresse('0-w1-s1-v1:1')->id, 'est_publique' => false]);

    $this->patchJson("/api/v1/app/moi/annotations/{$a->id}", ['est_publique' => true])->assertOk()->assertJsonPath('masquee', true);
});

it('indique « masquee » quand un administrateur a masqué l\'annotation', function () {
    $a = Annotation::factory()->publique()->create(['utilisateur_id' => $this->membre->id, 'adresse_id' => emAdresse()->id, 'masque_le' => now()]);

    $this->patchJson("/api/v1/app/moi/annotations/{$a->id}", ['contenu' => 'Corrigée'])->assertOk()->assertJsonPath('masquee', true);
});
