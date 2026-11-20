<?php

use App\Enums\StatutSignalement;
use App\Models\Annotation;
use App\Models\Collection;
use App\Models\LienCourt;
use App\Models\Signalement;
use App\Models\User;

require_once __DIR__.'/Appel.php';

/* POST /app/signalements (V17). */

beforeEach(function () {
    $this->locale = bibliothequeLocale();
    $this->ada = User::factory()->create();
});

it('signale une annotation publique : 201, schéma Signalement exact, en attente', function () {
    $annotation = Annotation::factory()->publique()->create();

    $r = appel('POST', '/signalements', ['cible' => ['type' => 'annotation', 'id' => (string) $annotation->id], 'motif' => 'Propos insultants.'], $this->ada);

    $r->assertCreated();
    expect(array_keys($r->json()))->toBe(['id', 'cible', 'motif', 'statut', 'cree_le']);
    expect($r->json('cible'))->toBe(['type' => 'annotation', 'id' => (string) $annotation->id]);
    expect($r->json('motif'))->toBe('Propos insultants.')->and($r->json('statut'))->toBe('en_attente');
    expect(Signalement::query()->sole())->auteur_id->toBe($this->ada->id)->annotation_id->toBe($annotation->id);
});

it('un visiteur peut signaler : l\'auteur reste nul, et chaque signalement est un nouveau (pas de dédoublonnage possible)', function () {
    $collection = Collection::factory()->publique()->create();
    $corps = ['cible' => ['type' => 'collection', 'id' => (string) $collection->id], 'motif' => 'Hors sujet.'];

    appel('POST', '/signalements', $corps)->assertCreated()->assertJsonPath('cible', ['type' => 'collection', 'id' => (string) $collection->id]);
    appel('POST', '/signalements', $corps)->assertCreated();

    expect(Signalement::query()->whereNull('auteur_id')->count())->toBe(2);
});

it('signale un lien court : la cible porte son slug, pas son id', function () {
    $lien = LienCourt::factory()->create(['slug' => 'k7Hq2xAb']);

    $r = appel('POST', '/signalements', ['cible' => ['type' => 'lien_court', 'id' => 'k7Hq2xAb'], 'motif' => 'Spam.']);

    $r->assertCreated()->assertJsonPath('cible', ['type' => 'lien_court', 'id' => 'k7Hq2xAb']);
    expect(Signalement::query()->sole()->lien_court_id)->toBe($lien->id);
});

it('un membre qui signale deux fois le même contenu reçoit son signalement existant : 200, pas de doublon', function () {
    $annotation = Annotation::factory()->publique()->create();
    $corps = ['cible' => ['type' => 'annotation', 'id' => (string) $annotation->id], 'motif' => 'Premier motif.'];

    $premier = appel('POST', '/signalements', $corps, $this->ada)->assertCreated();
    $second = appel('POST', '/signalements', ['motif' => 'Autre motif.'] + $corps, $this->ada)->assertOk();

    expect($second->json('id'))->toBe($premier->json('id'))->and($second->json('motif'))->toBe('Premier motif.');
    expect(Signalement::query()->count())->toBe(1);

    // Un autre membre signale le même contenu : c'est son propre signalement.
    appel('POST', '/signalements', $corps, User::factory()->create())->assertCreated();
    expect(Signalement::query()->count())->toBe(2);
});

it('ne signale que du contenu visible : privé, masqué, inconnu, ou mal écrit répondent tous 404', function (mixed $cible) {
    // Pest peut passer la fonction ou, déjà, son résultat : dans les deux cas on a la cible en main.
    $cible = $cible instanceof Closure ? $cible() : $cible;

    attendErreurContrat(appel('POST', '/signalements', ['cible' => $cible, 'motif' => 'Non.']), 404, 'ERR_NOT_FOUND', 'cible');
    expect(Signalement::query()->count())->toBe(0);
})->with([
    'annotation privée' => fn () => ['type' => 'annotation', 'id' => (string) Annotation::factory()->create(['est_publique' => false])->id],
    'annotation masquée' => fn () => ['type' => 'annotation', 'id' => (string) Annotation::factory()->publique()->create(['masque_le' => now()])->id],
    'annotation inconnue' => fn () => ['type' => 'annotation', 'id' => '99999'],
    'collection privée' => fn () => ['type' => 'collection', 'id' => (string) Collection::factory()->create()->id],
    'collection masquée' => fn () => ['type' => 'collection', 'id' => (string) Collection::factory()->publique()->create(['masque_le' => now()])->id],
    'lien masqué' => fn () => ['type' => 'lien_court', 'id' => LienCourt::factory()->create(['slug' => 'masqueAb', 'masque_le' => now()])->slug],
    'lien inconnu' => fn () => ['type' => 'lien_court', 'id' => 'nexistepas'],
    'id mal écrit (12abc n\'est pas 12)' => fn () => ['type' => 'annotation', 'id' => Annotation::factory()->publique()->create()->id.'abc'],
]);

it('refuse une demande mal formée : 422 ERR_INVALID_PARAMETER, une erreur par champ', function (array $corps, array $champs) {
    $r = appel('POST', '/signalements', $corps);

    $r->assertStatus(422);
    expect(array_column($r->json('erreurs'), 'champ'))->toBe($champs);
    expect(array_unique(array_column($r->json('erreurs'), 'code')))->toBe(['ERR_INVALID_PARAMETER']);
})->with([
    'corps vide' => [[], ['cible', 'motif']],
    'motif absent' => [['cible' => ['type' => 'annotation', 'id' => '1']], ['motif']],
    'motif vide' => [['cible' => ['type' => 'annotation', 'id' => '1'], 'motif' => ''], ['motif']],
    'motif d\'espaces' => [['cible' => ['type' => 'annotation', 'id' => '1'], 'motif' => '   '], ['motif']],
    'motif de 501 caractères' => [['cible' => ['type' => 'annotation', 'id' => '1'], 'motif' => str_repeat('é', 501)], ['motif']],
    'on ne signale pas une page' => [['cible' => ['type' => 'page', 'id' => '0-w1-s1-v1:1'], 'motif' => 'x'], ['cible']],
    'id absent' => [['cible' => ['type' => 'annotation'], 'motif' => 'x'], ['cible']],
]);

it('accepte un motif de 500 caractères exactement', function () {
    $annotation = Annotation::factory()->publique()->create();

    appel('POST', '/signalements', ['cible' => ['type' => 'annotation', 'id' => (string) $annotation->id], 'motif' => str_repeat('é', 500)])
        ->assertCreated();
});

it('un signalement traité garde sa place : le membre le retrouve (200) au lieu d\'en créer un autre', function () {
    $annotation = Annotation::factory()->publique()->create();
    Signalement::factory()->create(['annotation_id' => $annotation->id, 'auteur_id' => $this->ada->id, 'statut' => StatutSignalement::Rejete]);

    appel('POST', '/signalements', ['cible' => ['type' => 'annotation', 'id' => (string) $annotation->id], 'motif' => 'Encore.'], $this->ada)
        ->assertOk()->assertJsonPath('statut', 'rejete');
});

it('est limité à 20 publications par minute', function () {
    for ($i = 0; $i < 20; $i++) {
        appel('POST', '/signalements', [])->assertStatus(422);
    }

    attendErreurContrat(appel('POST', '/signalements', []), 429, 'ERR_TOO_MANY_REQUESTS', null);
});
