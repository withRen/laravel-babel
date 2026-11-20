<?php

use App\Models\Adresse;
use App\Models\MarquePage;
use App\Models\Vote;
use App\Services\Reseau\PagesPopulaires;

require_once __DIR__.'/Aide.php';

/*
 * Les votes (M7) : PUT et DELETE /app/pages/{adresse}/vote, GET /app/moi/votes.
 */
beforeEach(function () {
    $this->biblios = emBibliotheques();
    $this->membre = emConnecte();
});

it('répond 401 ERR_UNAUTHENTICATED sans jeton', function () {
    $this->refreshApplication();

    attendErreurContrat($this->putJson('/api/v1/app/pages/0-w1-s1-v1:1/vote', ['valeur' => 1]), 401, 'ERR_UNAUTHENTICATED', null);
    attendErreurContrat($this->deleteJson('/api/v1/app/pages/0-w1-s1-v1:1/vote'), 401, 'ERR_UNAUTHENTICATED', null);
    attendErreurContrat($this->getJson('/api/v1/app/moi/votes'), 401, 'ERR_UNAUTHENTICATED', null);
});

it('enregistre un vote pour (schéma EtatVote exact)', function () {
    $r = $this->putJson('/api/v1/app/pages/0-w1-s1-v1:1/vote', ['valeur' => 1]);

    $r->assertOk();
    expect(emCles($r))->toBe(['bibliotheque', 'adresse', 'mon_vote', 'score']);
    expect($r->json())->toBe(['bibliotheque' => 'exemple', 'adresse' => '0-w1-s1-v1:1', 'mon_vote' => 1, 'score' => 1]);
});

it('enregistre un vote contre : le score baisse', function () {
    $this->putJson('/api/v1/app/pages/0-w1-s1-v1:1/vote', ['valeur' => -1])
        ->assertOk()->assertJsonPath('mon_vote', -1)->assertJsonPath('score', -1);
});

it('REMPLACE le vote quand on vote une deuxième fois : un seul vote par membre et par page (règle 10)', function () {
    $this->putJson('/api/v1/app/pages/0-w1-s1-v1:1/vote', ['valeur' => 1])->assertOk();

    $this->putJson('/api/v1/app/pages/0-w1-s1-v1:1/vote', ['valeur' => -1])
        ->assertOk()->assertJsonPath('mon_vote', -1)->assertJsonPath('score', -1);

    expect(Vote::query()->count())->toBe(1);
});

it('additionne les votes de tous les membres dans le score, marque-pages compris', function () {
    $page = emAdresse('0-w1-s1-v1:1');
    $this->putJson('/api/v1/app/pages/0-w1-s1-v1:1/vote', ['valeur' => 1])->assertOk();
    emConnecte();
    $this->putJson('/api/v1/app/pages/0-w1-s1-v1:1/vote', ['valeur' => 1])->assertOk()->assertJsonPath('score', 2);
    MarquePage::factory()->create(['adresse_id' => $page->id]);

    $r = $this->putJson('/api/v1/app/pages/0-w1-s1-v1:1/vote', ['valeur' => -1]);

    // 1 (pour) + (-1) (contre) + 1 marque-page = 1
    $r->assertOk()->assertJsonPath('score', 1);
});

it('donne le même score que les pages populaires (B4)', function () {
    $this->putJson('/api/v1/app/pages/0-w1-s1-v1:1/vote', ['valeur' => 1])->assertOk();
    MarquePage::factory()->create(['adresse_id' => emAdresse('0-w1-s1-v1:1')->id]);
    $r = $this->putJson('/api/v1/app/pages/0-w1-s1-v1:1/vote', ['valeur' => 1]);

    $populaire = app(PagesPopulaires::class)->lister(10)->first();

    expect($r->json('score'))->toBe((int) $populaire->score)->toBe(2);
});

it('vote pour une page d\'une autre bibliothèque (?bibliotheque=)', function () {
    $r = $this->putJson('/api/v1/app/pages/0-w1-s1-v1:1/vote?bibliotheque=babel-5', ['valeur' => 1]);

    $r->assertOk()->assertJsonPath('bibliotheque', 'babel-5');
    // Et ce n'est pas la même page que celle d'ici.
    $this->putJson('/api/v1/app/pages/0-w1-s1-v1:1/vote', ['valeur' => -1])->assertOk()->assertJsonPath('score', -1);
});

it('refuse une valeur autre que 1 ou -1 (422)', function () {
    foreach ([['valeur' => 0], ['valeur' => 2], ['valeur' => '1'], ['valeur' => true], ['valeur' => null], []] as $corps) {
        attendErreurContrat($this->putJson('/api/v1/app/pages/0-w1-s1-v1:1/vote', $corps), 422, 'ERR_INVALID_PARAMETER', 'valeur');
    }
    expect(Vote::query()->count())->toBe(0);
});

it('contrôle l\'adresse et la bibliothèque comme les autres routes', function () {
    attendErreurContrat($this->putJson('/api/v1/app/pages/ABC-w9-s1-v1:1/vote', ['valeur' => 1]), 422, 'ERR_INVALID_HEX_CHARS', 'hexagone');
    attendErreurContrat($this->putJson('/api/v1/app/pages/0-w1-s1-v33:1/vote', ['valeur' => 1]), 404, 'ERR_OUT_OF_BOUNDS', 'volume');
    attendErreurContrat($this->putJson('/api/v1/app/pages/0-w1-s1-v1/vote', ['valeur' => 1]), 422, 'ERR_INVALID_ADDRESS_SYNTAX', 'page');
    attendErreurContrat($this->putJson('/api/v1/app/pages/0-w1-s1-v1:1/vote?bibliotheque=inconnue', ['valeur' => 1]), 404, 'ERR_UNKNOWN_LIBRARY', 'bibliotheque');
    attendErreurContrat($this->deleteJson('/api/v1/app/pages/0-w1-s1-v33:1/vote'), 404, 'ERR_OUT_OF_BOUNDS', 'volume');
});

it('retire mon vote : le vote des autres reste', function () {
    $this->putJson('/api/v1/app/pages/0-w1-s1-v1:1/vote', ['valeur' => 1])->assertOk();
    $autre = emConnecte();
    $this->putJson('/api/v1/app/pages/0-w1-s1-v1:1/vote', ['valeur' => 1])->assertOk();

    $r = $this->deleteJson('/api/v1/app/pages/0-w1-s1-v1:1/vote');

    $r->assertOk();
    expect($r->json())->toBe(['bibliotheque' => 'exemple', 'adresse' => '0-w1-s1-v1:1', 'mon_vote' => null, 'score' => 1]);
    expect(Vote::query()->where('utilisateur_id', $autre->id)->count())->toBe(0)
        ->and(Vote::query()->count())->toBe(1);
});

it('répond pareil quand il n\'y a pas de vote à retirer, sans enregistrer la page', function () {
    $avant = Adresse::query()->count();

    $this->deleteJson('/api/v1/app/pages/5-w2-s2-v2:2/vote')
        ->assertOk()->assertJsonPath('mon_vote', null)->assertJsonPath('score', 0);

    expect(Adresse::query()->count())->toBe($avant);
});

it('liste mes votes, du plus récent au plus ancien, sans ceux des autres', function () {
    $this->putJson('/api/v1/app/pages/0-w1-s1-v1:1/vote', ['valeur' => 1])->assertOk();
    $this->putJson('/api/v1/app/pages/0-w1-s1-v1:2/vote?bibliotheque=babel-5', ['valeur' => -1])->assertOk();
    Vote::query()->where('utilisateur_id', $this->membre->id)->where('adresse_id', Adresse::query()->where('page', 1)->value('id'))
        ->update(['cree_le' => '2020-01-01 00:00:00']);
    emConnecte();
    $this->putJson('/api/v1/app/pages/0-w1-s1-v1:9/vote', ['valeur' => 1])->assertOk();
    emConnecte($this->membre);

    $r = $this->getJson('/api/v1/app/moi/votes');

    $r->assertOk();
    expect(emCles($r))->toBe(['lot', 'taille', 'total', 'elements']);
    expect($r->json('total'))->toBe(2);
    expect(emCles($r, 'elements.0'))->toBe(['bibliotheque', 'adresse', 'valeur', 'cree_le']);
    expect(array_column($r->json('elements'), 'adresse'))->toBe(['0-w1-s1-v1:2', '0-w1-s1-v1:1']);
    expect($r->json('elements.0.valeur'))->toBe(-1)->and($r->json('elements.0.bibliotheque'))->toBe('babel-5');
});

it('refuse un lot ou une taille hors limites sur la liste des votes', function () {
    attendErreurContrat($this->getJson('/api/v1/app/moi/votes?taille=0'), 422, 'ERR_INVALID_PARAMETER', 'taille');
});
