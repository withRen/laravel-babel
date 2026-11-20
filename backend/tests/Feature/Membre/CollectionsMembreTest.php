<?php

use App\Enums\StatutTexteBanni;
use App\Models\Collection;
use App\Models\LienCourt;
use App\Models\TexteBanni;
use App\Models\User;
use Babel\Moteur\Moteur;

require_once __DIR__.'/Aide.php';

/*
 * /app/moi/collections (M4, M5, M13) : voir docs/openapi.yaml, tag « Collections ».
 */
beforeEach(function () {
    $this->biblios = emBibliotheques();
    $this->membre = emConnecte();
});

/** Une collection du membre connecté, avec ses pages (adresses d'ici) dans l'ordre. */
function emCollection(User $membre, array $adresses = [], array $attributs = []): Collection
{
    $collection = Collection::factory()->create($attributs + ['utilisateur_id' => $membre->id, 'nom' => 'Ma collection', 'description' => 'Pour lire']);
    foreach (array_values($adresses) as $i => $adresse) {
        $collection->pages()->create(['position' => $i + 1, 'adresse_id' => emAdresse($adresse)->id, 'a_convertir' => false]);
    }

    return $collection;
}

function emAdressesDe($reponse): array
{
    return array_column($reponse->json('pages'), 'adresse');
}

it('répond 401 ERR_UNAUTHENTICATED sans jeton', function () {
    $this->refreshApplication();

    attendErreurContrat($this->getJson('/api/v1/app/moi/collections'), 401, 'ERR_UNAUTHENTICATED', null);
    attendErreurContrat($this->postJson('/api/v1/app/moi/collections', ['nom' => 'x']), 401, 'ERR_UNAUTHENTICATED', null);
    attendErreurContrat($this->putJson('/api/v1/app/moi/collections/1/pages', ['pages' => []]), 401, 'ERR_UNAUTHENTICATED', null);
});

// ---- création et lecture -----------------------------------------------------------------------------

it('crée une collection vide, PRIVÉE par défaut (201, schéma MaCollection exact)', function () {
    $r = $this->postJson('/api/v1/app/moi/collections', ['nom' => 'Mes trouvailles', 'description' => 'Pour plus tard']);

    $r->assertCreated();
    expect(emCles($r))->toBe(['id', 'nom', 'description', 'est_publique', 'masquee', 'nombre_pages', 'cree_le', 'maj_le', 'pages']);
    expect($r->json('nom'))->toBe('Mes trouvailles')
        ->and($r->json('est_publique'))->toBeFalse()
        ->and($r->json('masquee'))->toBeFalse()
        ->and($r->json('nombre_pages'))->toBe(0)
        ->and($r->json('pages'))->toBe([]);
    expect($this->membre->collections()->count())->toBe(1);
});

it('crée une collection publique, et une description absente vaut une chaîne vide', function () {
    $r = $this->postJson('/api/v1/app/moi/collections', ['nom' => 'Publique', 'est_publique' => true]);

    $r->assertCreated()->assertJsonPath('est_publique', true)->assertJsonPath('description', '');
});

it('refuse une collection sans nom, avec un nom trop long ou un booléen mal écrit (422)', function () {
    attendErreurContrat($this->postJson('/api/v1/app/moi/collections', []), 422, 'ERR_INVALID_PARAMETER', 'nom');
    attendErreurContrat($this->postJson('/api/v1/app/moi/collections', ['nom' => str_repeat('a', 101)]), 422, 'ERR_INVALID_PARAMETER', 'nom');
    attendErreurContrat($this->postJson('/api/v1/app/moi/collections', ['nom' => 'a', 'description' => str_repeat('a', 1001)]), 422, 'ERR_INVALID_PARAMETER', 'description');
    attendErreurContrat($this->postJson('/api/v1/app/moi/collections', ['nom' => 'a', 'est_publique' => 'oui']), 422, 'ERR_INVALID_PARAMETER', 'est_publique');
    attendErreurContrat($this->postJson('/api/v1/app/moi/collections', ['nom' => 'a', 'est_publique' => 1]), 422, 'ERR_INVALID_PARAMETER', 'est_publique');
});

it('liste mes collections, publiques et privées, de la plus récemment modifiée à la plus ancienne', function () {
    emCollection($this->membre, ['0-w1-s1-v1:1', '0-w1-s1-v1:2'], ['nom' => 'Ancienne', 'maj_le' => '2026-11-01 10:00:00']);
    emCollection($this->membre, [], ['nom' => 'Récente', 'est_publique' => true, 'maj_le' => '2026-11-10 10:00:00']);
    emCollection(User::factory()->create(), ['0-w1-s1-v1:1'], ['nom' => 'D\'un autre']);

    $r = $this->getJson('/api/v1/app/moi/collections');

    $r->assertOk();
    expect(emCles($r))->toBe(['lot', 'taille', 'total', 'elements']);
    expect($r->json('total'))->toBe(2);
    expect(array_column($r->json('elements'), 'nom'))->toBe(['Récente', 'Ancienne']);
    expect(emCles($r, 'elements.0'))->toBe(['id', 'nom', 'description', 'est_publique', 'masquee', 'nombre_pages', 'cree_le', 'maj_le']);
    expect($r->json('elements.1.nombre_pages'))->toBe(2);
});

it('compte dans « nombre_pages » TOUTES les pages, y compris celles que le public ne voit pas', function () {
    $c = emCollection($this->membre, ['0-w1-s1-v1:1', '0-w1-s1-v1:2']);
    $banni = TexteBanni::factory()->create(['statut' => StatutTexteBanni::Applique]);
    emAdresse('0-w1-s1-v1:2')->update(['texte_banni_id' => $banni->id]);

    $this->getJson('/api/v1/app/moi/collections')->assertJsonPath('elements.0.nombre_pages', 2);
    $r = $this->getJson("/api/v1/app/moi/collections/{$c->id}");

    expect($r->json('nombre_pages'))->toBe(2);
    expect(array_column($r->json('pages'), 'masquee'))->toBe([false, true]);
});

it('lit une de mes collections avec toutes ses pages (schéma exact)', function () {
    $c = emCollection($this->membre, ['0-w1-s1-v1:1', '0-w1-s1-v1:2']);
    $c->pages()->where('position', 2)->update(['adresse_id' => emAdresse('0-w1-s1-v1:2', $this->biblios['autre'])->id, 'a_convertir' => true]);

    $r = $this->getJson("/api/v1/app/moi/collections/{$c->id}");

    $r->assertOk();
    expect(emCles($r, 'pages.0'))->toBe(['position', 'bibliotheque', 'adresse', 'masquee', 'a_convertir']);
    expect($r->json('pages'))->toBe([
        ['position' => 1, 'bibliotheque' => 'exemple', 'adresse' => '0-w1-s1-v1:1', 'masquee' => false, 'a_convertir' => false],
        ['position' => 2, 'bibliotheque' => 'babel-5', 'adresse' => '0-w1-s1-v1:2', 'masquee' => false, 'a_convertir' => true],
    ]);
});

it('indique « masquee » quand un administrateur a masqué la collection', function () {
    $c = emCollection($this->membre, [], ['masque_le' => now()]);

    $this->getJson("/api/v1/app/moi/collections/{$c->id}")->assertJsonPath('masquee', true);
});

it('répond 404 ERR_NOT_FOUND pour la collection d\'un autre membre, même publique (jamais 403)', function () {
    $autre = emCollection(User::factory()->create(), ['0-w1-s1-v1:1'], ['est_publique' => true]);

    attendErreurContrat($this->getJson("/api/v1/app/moi/collections/{$autre->id}"), 404, 'ERR_NOT_FOUND', 'id');
    attendErreurContrat($this->patchJson("/api/v1/app/moi/collections/{$autre->id}", ['nom' => 'Pris']), 404, 'ERR_NOT_FOUND', 'id');
    attendErreurContrat($this->deleteJson("/api/v1/app/moi/collections/{$autre->id}"), 404, 'ERR_NOT_FOUND', 'id');
    attendErreurContrat($this->postJson("/api/v1/app/moi/collections/{$autre->id}/pages", ['adresse' => '0-w1-s1-v1:5']), 404, 'ERR_NOT_FOUND', 'id');
    attendErreurContrat($this->putJson("/api/v1/app/moi/collections/{$autre->id}/pages", ['pages' => []]), 404, 'ERR_NOT_FOUND', 'id');
    attendErreurContrat($this->deleteJson("/api/v1/app/moi/collections/{$autre->id}/pages/1"), 404, 'ERR_NOT_FOUND', 'id');
    attendErreurContrat($this->getJson('/api/v1/app/moi/collections/abc'), 404, 'ERR_NOT_FOUND', 'id');
    expect($autre->fresh()->nom)->not->toBe('Pris')->and($autre->pages()->count())->toBe(1);
});

// ---- modification et suppression ---------------------------------------------------------------------------

it('modifie seulement les champs envoyés, et publie ou dépublie (M5)', function () {
    $c = emCollection($this->membre, ['0-w1-s1-v1:1'], ['nom' => 'Avant', 'description' => 'Garder']);

    $r = $this->patchJson("/api/v1/app/moi/collections/{$c->id}", ['est_publique' => true]);
    $r->assertOk()->assertJsonPath('est_publique', true)->assertJsonPath('nom', 'Avant')->assertJsonPath('description', 'Garder');

    $r = $this->patchJson("/api/v1/app/moi/collections/{$c->id}", ['nom' => 'Après', 'est_publique' => false]);
    $r->assertOk()->assertJsonPath('nom', 'Après')->assertJsonPath('est_publique', false);
    expect($r->json('pages'))->toHaveCount(1);
});

it('refuse un PATCH vide ou invalide (422)', function () {
    $c = emCollection($this->membre);

    attendErreurContrat($this->patchJson("/api/v1/app/moi/collections/{$c->id}", []), 422, 'ERR_INVALID_PARAMETER', null);
    attendErreurContrat($this->patchJson("/api/v1/app/moi/collections/{$c->id}", ['nom' => '']), 422, 'ERR_INVALID_PARAMETER', 'nom');
    attendErreurContrat($this->patchJson("/api/v1/app/moi/collections/{$c->id}", ['est_publique' => 'true']), 422, 'ERR_INVALID_PARAMETER', 'est_publique');
});

it('supprime la collection, ses pages et les liens courts qui la visent (204)', function () {
    $c = emCollection($this->membre, ['0-w1-s1-v1:1']);
    LienCourt::factory()->create(['collection_id' => $c->id, 'adresse_id' => null, 'utilisateur_id' => $this->membre->id]);

    $this->deleteJson("/api/v1/app/moi/collections/{$c->id}")->assertNoContent();

    expect(Collection::query()->count())->toBe(0)->and(LienCourt::query()->count())->toBe(0);
    expect(DB::table('collection_pages')->count())->toBe(0);
});

// ---- les pages : ajouter ---------------------------------------------------------------------------------

it('ajoute une page à la fin, par défaut', function () {
    $c = emCollection($this->membre, ['0-w1-s1-v1:1']);

    $r = $this->postJson("/api/v1/app/moi/collections/{$c->id}/pages", ['adresse' => '0-w1-s1-v1:2']);

    $r->assertOk()->assertJsonPath('nombre_pages', 2);
    expect(emAdressesDe($r))->toBe(['0-w1-s1-v1:1', '0-w1-s1-v1:2']);
    expect(array_column($r->json('pages'), 'position'))->toBe([1, 2]);
});

it('insère à la position demandée et décale les suivantes, sans trou', function () {
    $c = emCollection($this->membre, ['0-w1-s1-v1:1', '0-w1-s1-v1:2', '0-w1-s1-v1:3']);

    $r = $this->postJson("/api/v1/app/moi/collections/{$c->id}/pages", ['adresse' => '0-w1-s1-v1:9', 'position' => 2]);

    expect(emAdressesDe($r))->toBe(['0-w1-s1-v1:1', '0-w1-s1-v1:9', '0-w1-s1-v1:2', '0-w1-s1-v1:3']);
    expect(array_column($r->json('pages'), 'position'))->toBe([1, 2, 3, 4]);

    $r = $this->postJson("/api/v1/app/moi/collections/{$c->id}/pages", ['adresse' => '0-w1-s1-v1:8', 'position' => 1]);
    expect(emAdressesDe($r)[0])->toBe('0-w1-s1-v1:8');

    $r = $this->postJson("/api/v1/app/moi/collections/{$c->id}/pages", ['adresse' => '0-w1-s1-v1:7', 'position' => 6]); // dernière + 1
    expect(emAdressesDe($r)[5])->toBe('0-w1-s1-v1:7');
});

it('refuse une position au-delà de dernière + 1, ou invalide (422)', function () {
    $c = emCollection($this->membre, ['0-w1-s1-v1:1']);

    attendErreurContrat($this->postJson("/api/v1/app/moi/collections/{$c->id}/pages", ['adresse' => '0-w1-s1-v1:2', 'position' => 3]), 422, 'ERR_INVALID_PARAMETER', 'position');
    attendErreurContrat($this->postJson("/api/v1/app/moi/collections/{$c->id}/pages", ['adresse' => '0-w1-s1-v1:2', 'position' => 0]), 422, 'ERR_INVALID_PARAMETER', 'position');
    attendErreurContrat($this->postJson("/api/v1/app/moi/collections/{$c->id}/pages", ['adresse' => '0-w1-s1-v1:2', 'position' => '2']), 422, 'ERR_INVALID_PARAMETER', 'position');
    expect($c->pages()->count())->toBe(1);
});

it('laisse une même page revenir plusieurs fois (règle 8)', function () {
    $c = emCollection($this->membre, ['0-w1-s1-v1:1']);

    $r = $this->postJson("/api/v1/app/moi/collections/{$c->id}/pages", ['adresse' => '0-w1-s1-v1:1']);

    $r->assertOk();
    expect(emAdressesDe($r))->toBe(['0-w1-s1-v1:1', '0-w1-s1-v1:1']);
});

it('ajoute une page d\'une autre bibliothèque (M13) et refuse une adresse invalide', function () {
    $c = emCollection($this->membre);

    $r = $this->postJson("/api/v1/app/moi/collections/{$c->id}/pages", ['bibliotheque' => 'babel-5', 'adresse' => '0-w1-s1-v1:1']);
    $r->assertOk()->assertJsonPath('pages.0.bibliotheque', 'babel-5');

    attendErreurContrat($this->postJson("/api/v1/app/moi/collections/{$c->id}/pages", ['adresse' => '0-w1-s1-v1']), 422, 'ERR_INVALID_ADDRESS_SYNTAX', 'page');
    attendErreurContrat($this->postJson("/api/v1/app/moi/collections/{$c->id}/pages", ['adresse' => '0-w1-s1-v33:1']), 404, 'ERR_OUT_OF_BOUNDS', 'volume');
    attendErreurContrat($this->postJson("/api/v1/app/moi/collections/{$c->id}/pages", ['adresse' => '0-w1-s1-v1:1', 'bibliotheque' => 'inconnue']), 404, 'ERR_UNKNOWN_LIBRARY', 'bibliotheque');
    expect($c->pages()->count())->toBe(1);
});

it('date la modification : la collection remonte en tête de la liste', function () {
    $c = emCollection($this->membre, [], ['nom' => 'Vieille', 'maj_le' => '2026-01-01 00:00:00']);
    emCollection($this->membre, [], ['nom' => 'Neuve', 'maj_le' => '2026-06-01 00:00:00']);

    $this->postJson("/api/v1/app/moi/collections/{$c->id}/pages", ['adresse' => '0-w1-s1-v1:1'])->assertOk();

    expect($this->getJson('/api/v1/app/moi/collections')->json('elements.0.nom'))->toBe('Vieille');
});

// ---- les pages : réordonner et retirer ----------------------------------------------------------------------

it('réordonne les pages : la liste envoyée remplace l\'ancienne (PUT)', function () {
    $c = emCollection($this->membre, ['0-w1-s1-v1:1', '0-w1-s1-v1:2', '0-w1-s1-v1:3']);

    $r = $this->putJson("/api/v1/app/moi/collections/{$c->id}/pages", ['pages' => [
        ['adresse' => '0-w1-s1-v1:3'], ['adresse' => '0-w1-s1-v1:1'], ['adresse' => '0-w1-s1-v1:2'],
    ]]);

    $r->assertOk();
    expect(emAdressesDe($r))->toBe(['0-w1-s1-v1:3', '0-w1-s1-v1:1', '0-w1-s1-v1:2']);
    expect(array_column($r->json('pages'), 'position'))->toBe([1, 2, 3]);
});

it('remplace aussi la liste quand elle change (page retirée, page ajoutée, page doublée) et accepte une liste vide', function () {
    $c = emCollection($this->membre, ['0-w1-s1-v1:1', '0-w1-s1-v1:2']);

    $r = $this->putJson("/api/v1/app/moi/collections/{$c->id}/pages", ['pages' => [
        ['adresse' => '0-w1-s1-v1:2'], ['bibliotheque' => 'babel-5', 'adresse' => '0-w1-s1-v1:2'], ['adresse' => '0-w1-s1-v1:2'],
    ]]);
    expect(array_column($r->json('pages'), 'bibliotheque'))->toBe(['exemple', 'babel-5', 'exemple']);

    $this->putJson("/api/v1/app/moi/collections/{$c->id}/pages", ['pages' => []])->assertOk()->assertJsonPath('nombre_pages', 0);
});

it('garde « à convertir » des pages qui restent dans la collection', function () {
    $c = emCollection($this->membre, ['0-w1-s1-v1:1']);
    $c->pages()->update(['adresse_id' => emAdresse('9-w1-s1-v1:1', $this->biblios['autre'])->id, 'a_convertir' => true]);

    $r = $this->putJson("/api/v1/app/moi/collections/{$c->id}/pages", ['pages' => [
        ['adresse' => '0-w1-s1-v1:5'], ['bibliotheque' => 'babel-5', 'adresse' => '9-w1-s1-v1:1'],
    ]]);

    expect(array_column($r->json('pages'), 'a_convertir'))->toBe([false, true]);
});

it('n\'écrit rien si une des adresses envoyées est invalide : tout ou rien', function () {
    $c = emCollection($this->membre, ['0-w1-s1-v1:1', '0-w1-s1-v1:2']);

    $r = $this->putJson("/api/v1/app/moi/collections/{$c->id}/pages", ['pages' => [['adresse' => '0-w1-s1-v1:3'], ['adresse' => 'ABC-w9-s1-v1:1']]]);

    $r->assertStatus(422);
    expect(DB::table('collection_pages')->where('collection_id', $c->id)->orderBy('position')->count())->toBe(2);
    expect(emAdressesDe($this->getJson("/api/v1/app/moi/collections/{$c->id}")))->toBe(['0-w1-s1-v1:1', '0-w1-s1-v1:2']);
});

it('refuse un corps de réordonnancement mal formé (422)', function () {
    $c = emCollection($this->membre);

    attendErreurContrat($this->putJson("/api/v1/app/moi/collections/{$c->id}/pages", []), 422, 'ERR_INVALID_PARAMETER', 'pages');
    attendErreurContrat($this->putJson("/api/v1/app/moi/collections/{$c->id}/pages", ['pages' => 'x']), 422, 'ERR_INVALID_PARAMETER', 'pages');
    attendErreurContrat($this->putJson("/api/v1/app/moi/collections/{$c->id}/pages", ['pages' => [['bibliotheque' => 'babel-5']]]), 422, 'ERR_INVALID_PARAMETER', 'pages');
});

it('retire la page à une position : les suivantes remontent d\'un rang', function () {
    $c = emCollection($this->membre, ['0-w1-s1-v1:1', '0-w1-s1-v1:2', '0-w1-s1-v1:3']);

    $r = $this->deleteJson("/api/v1/app/moi/collections/{$c->id}/pages/2");

    $r->assertOk();
    expect(emAdressesDe($r))->toBe(['0-w1-s1-v1:1', '0-w1-s1-v1:3']);
    expect(array_column($r->json('pages'), 'position'))->toBe([1, 2]);
});

it('répond 404 quand la position n\'existe pas', function () {
    $c = emCollection($this->membre, ['0-w1-s1-v1:1']);

    foreach (['2', '0', 'abc', '-1'] as $position) {
        attendErreurContrat($this->deleteJson("/api/v1/app/moi/collections/{$c->id}/pages/{$position}"), 404, 'ERR_NOT_FOUND', 'position');
    }
    expect($c->pages()->count())->toBe(1);
});

// ---- le contrôle des textes bannis à la publication --------------------------------------------------------------

it('marque la page ajoutée à une collection PUBLIQUE quand elle contient un texte banni appliqué (règle 18)', function () {
    $page = app(Moteur::class)->lire('0-w1-s1-v1:1');
    $banni = TexteBanni::factory()->create(['texte' => trim(substr($page, 0, 40)), 'statut' => StatutTexteBanni::Applique]);
    $publique = emCollection($this->membre, [], ['est_publique' => true]);

    $r = $this->postJson("/api/v1/app/moi/collections/{$publique->id}/pages", ['adresse' => '0-w1-s1-v1:1']);

    $r->assertOk()->assertJsonPath('pages.0.masquee', true);
    expect(emAdresse('0-w1-s1-v1:1')->fresh()->texte_banni_id)->toBe($banni->id);
});

it('ne vérifie pas une page ajoutée à une collection PRIVÉE, et ignore un texte seulement proposé', function () {
    $page = app(Moteur::class)->lire('0-w1-s1-v1:1');
    TexteBanni::factory()->create(['texte' => trim(substr($page, 0, 40)), 'statut' => StatutTexteBanni::Applique]);
    $privee = emCollection($this->membre);
    $publique = emCollection($this->membre, [], ['est_publique' => true]);

    $this->postJson("/api/v1/app/moi/collections/{$privee->id}/pages", ['adresse' => '0-w1-s1-v1:1'])->assertOk()->assertJsonPath('pages.0.masquee', false);

    TexteBanni::query()->update(['statut' => StatutTexteBanni::Propose]);
    $this->postJson("/api/v1/app/moi/collections/{$publique->id}/pages", ['adresse' => '0-w1-s1-v1:1'])->assertOk()->assertJsonPath('pages.0.masquee', false);
});

it('contrôle les pages d\'ici quand une collection devient publique', function () {
    $page = app(Moteur::class)->lire('0-w1-s1-v1:1');
    TexteBanni::factory()->create(['texte' => trim(substr($page, 0, 40)), 'statut' => StatutTexteBanni::Applique]);
    $c = emCollection($this->membre, ['0-w1-s1-v1:1', '0-w1-s1-v1:2']);

    $r = $this->patchJson("/api/v1/app/moi/collections/{$c->id}", ['est_publique' => true]);

    expect(array_column($r->json('pages'), 'masquee'))->toBe([true, false]);
});
