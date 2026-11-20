<?php

use Illuminate\Support\Facades\RateLimiter;

/*
 * A10 : au moins 60 requêtes par minute et par adresse IP appelante, puis 429 ERR_TOO_MANY_REQUESTS + Retry-After.
 */
beforeEach(function () {
    bibliothequeLocale();
    RateLimiter::clear('reseau');
});

it('accepte 60 requêtes par minute puis répond 429 au format du contrat avec Retry-After', function () {
    foreach (range(1, 60) as $i) {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])->getJson('/api/v1/identite')->assertOk();
    }

    $r = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])->getJson('/api/v1/identite');

    attendErreurContrat($r, 429, 'ERR_TOO_MANY_REQUESTS', null);
    expect((int) $r->headers->get('Retry-After'))->toBeGreaterThanOrEqual(1)->toBeLessThanOrEqual(60);
    expect($r->json('erreurs.0.message'))->toContain((string) (int) $r->headers->get('Retry-After'));
});

it('compte par adresse IP : une autre bibliothèque n\'est pas bloquée', function () {
    foreach (range(1, 61) as $i) {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])->getJson('/api/v1/identite');
    }

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.8'])->getJson('/api/v1/identite')->assertOk();
});

it('applique la même limite à toutes les routes de la porte réseau (le quota est partagé)', function () {
    foreach (range(1, 60) as $i) {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->getJson('/api/v1/collections')->assertOk();
    }

    foreach ([
        ['getJson', '/api/v1/identite', []],
        ['getJson', '/api/v1/pages/0-w1-s1-v1:1', []],
        ['postJson', '/api/v1/lectures', ['adresses' => ['0-w1-s1-v1:1']]],
        ['postJson', '/api/v1/recherches', ['texte' => 'a', 'mode' => 'exact']],
        ['getJson', '/api/v1/pages-populaires', []],
    ] as [$methode, $url, $corps]) {
        $r = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->{$methode}($url, $corps);
        attendErreurContrat($r, 429, 'ERR_TOO_MANY_REQUESTS', null);
    }
});

it('compte aussi les requêtes sans jeton réseau sur /textes-bannis', function () {
    foreach (range(1, 60) as $i) {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])->getJson('/api/v1/textes-bannis')->assertStatus(401);
    }

    attendErreurContrat(
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])->getJson('/api/v1/textes-bannis', jetonReseau()),
        429, 'ERR_TOO_MANY_REQUESTS', null,
    );
});

it('un appel à POST /lectures compte pour une seule requête', function () {
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.11'])
        ->postJson('/api/v1/lectures', ['adresses' => array_fill(0, 50, '0-w1-s1-v1:1')])->assertOk();

    foreach (range(1, 59) as $i) {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.11'])->getJson('/api/v1/identite')->assertOk();
    }
});
