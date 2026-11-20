<?php

use App\Enums\StatutTexteBanni;
use App\Models\Bibliotheque;
use App\Models\TexteBanni;
use Illuminate\Support\Facades\Log;

/*
 * GET /textes-bannis (A6, B5) : la seule route de la porte réseau protégée par le jeton réseau.
 */
beforeEach(function () {
    $this->locale = bibliothequeLocale();
});

it('liste les textes bannis ici et appliqués, au format du contrat', function () {
    TexteBanni::factory()->applique()->create([
        'texte' => 'ou est passe l ete',
        'motif' => 'Exemple de démonstration.',
        'origine_id' => $this->locale->id,
        'decide_le' => '2026-11-20 13:05:00',
    ]);

    $r = $this->getJson('/api/v1/textes-bannis', jetonReseau());

    $r->assertOk();
    expect($r->json())->toBe(['elements' => [[
        'texte' => 'ou est passe l ete',
        'empreinte' => hash('sha256', 'ou est passe l ete'),
        'motif' => 'Exemple de démonstration.',
        'banni_le' => '2026-11-20T13:05:00+00:00',
    ]]]);
    expect(array_keys($r->json('elements.0')))->toBe(['texte', 'empreinte', 'motif', 'banni_le']);
    expect($r->json('elements.0.empreinte'))->toMatch('/^[0-9a-f]{64}$/');
});

it('envoie « Cache-Control: no-store » (Symfony ajoute « private » : la directive no-store y est bien)', function () {
    $r = $this->getJson('/api/v1/textes-bannis', jetonReseau());

    expect($r->headers->getCacheControlDirective('no-store'))->toBeTrue();
    expect($r->headers->getCacheControlDirective('public'))->toBeNull();
});

it('rend un motif nul tel quel', function () {
    TexteBanni::factory()->applique()->create(['origine_id' => $this->locale->id, 'motif' => null]);

    expect($this->getJson('/api/v1/textes-bannis', jetonReseau())->json('elements.0.motif'))->toBeNull();
});

it('ne publie que les textes d\'origine locale au statut « applique »', function () {
    $autre = Bibliotheque::factory()->create();
    TexteBanni::factory()->applique()->create(['texte' => 'local applique', 'origine_id' => $this->locale->id]);
    TexteBanni::factory()->create(['texte' => 'local propose', 'origine_id' => $this->locale->id, 'statut' => StatutTexteBanni::Propose]);
    TexteBanni::factory()->create(['texte' => 'local rejete', 'origine_id' => $this->locale->id, 'statut' => StatutTexteBanni::Rejete]);
    TexteBanni::factory()->applique()->create(['texte' => 'releve chez un pair', 'origine_id' => $autre->id]);

    $r = $this->getJson('/api/v1/textes-bannis', jetonReseau());

    expect(array_column($r->json('elements'), 'texte'))->toBe(['local applique']);
});

it('renvoie une liste vide quand rien n\'est banni', function () {
    $this->getJson('/api/v1/textes-bannis', jetonReseau())->assertOk()->assertExactJson(['elements' => []]);
});

it('répond 401 ERR_INVALID_NETWORK_TOKEN avec WWW-Authenticate: Bearer, quelle que soit la raison', function (array $entetes) {
    TexteBanni::factory()->applique()->create(['origine_id' => $this->locale->id]);

    $r = $this->getJson('/api/v1/textes-bannis', $entetes);

    attendErreurContrat($r, 401, 'ERR_INVALID_NETWORK_TOKEN', null);
    expect($r->headers->get('WWW-Authenticate'))->toBe('Bearer');
    expect($r->getContent())->not->toContain('texte');
})->with([
    'sans jeton' => [[]],
    'jeton faux' => [['Authorization' => 'Bearer pas-le-bon']],
    'jeton trop court' => [['Authorization' => 'Bearer jeton']],
    'jeton avec un caractère de plus' => [['Authorization' => 'Bearer jeton-reseau-de-test!']],
    'mauvais schéma' => [['Authorization' => 'Basic jeton-reseau-de-test']],
    'jeton sans schéma' => [['Authorization' => 'jeton-reseau-de-test']],
    'Bearer vide' => [['Authorization' => 'Bearer ']],
]);

it('donne exactement la même réponse pour un jeton absent et un jeton faux', function () {
    $sans = $this->getJson('/api/v1/textes-bannis');
    $faux = $this->getJson('/api/v1/textes-bannis', jetonReseau('faux'));

    expect($sans->getContent())->toBe($faux->getContent());
});

it('refuse tout le monde quand le serveur n\'a pas de jeton configuré (même « Bearer » vide)', function (?string $configure) {
    config(['babel.network_token' => $configure]);

    $this->getJson('/api/v1/textes-bannis', ['Authorization' => 'Bearer '])->assertStatus(401);
    $this->getJson('/api/v1/textes-bannis', jetonReseau(''))->assertStatus(401);
    $this->getJson('/api/v1/textes-bannis')->assertStatus(401);
})->with([null, '']);

it('n\'accepte pas le jeton dans l\'URL', function () {
    $this->getJson('/api/v1/textes-bannis?jeton=jeton-reseau-de-test')->assertStatus(401);
    $this->getJson('/api/v1/textes-bannis?token=jeton-reseau-de-test')->assertStatus(401);
});

it('n\'écrit pas le jeton dans les journaux', function () {
    Log::spy();

    $this->getJson('/api/v1/textes-bannis', jetonReseau('jeton-secret-faux'));

    Log::shouldNotHaveReceived('error');
    Log::shouldNotHaveReceived('warning');
    Log::shouldNotHaveReceived('info');
});

it('est la seule route de la porte réseau qui exige le jeton', function () {
    foreach (['/api/v1/identite', '/api/v1/collections', '/api/v1/pages-populaires', '/api/v1/pages/0-w1-s1-v1:1'] as $url) {
        $this->getJson($url)->assertOk();
    }
});
