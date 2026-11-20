<?php

use App\Models\User;
use App\Services\Engine\Valideur;

require_once __DIR__.'/Appel.php';

/* GET /app/hasard (F7, V9). */

beforeEach(fn () => bibliothequeLocale());

it('renvoie une adresse de page valide de la bibliothèque locale, sans mise en cache', function () {
    $r = appel('GET', '/hasard');

    $r->assertOk();
    expect(array_keys($r->json()))->toBe(['bibliotheque', 'adresse']);
    expect($r->json('bibliotheque'))->toBe('exemple');

    $validation = app(Valideur::class)->valider($r->json('adresse'));
    expect($validation->valide)->toBeTrue()->and($validation->niveau)->toBe('page');

    // Un tirage n'est pas reproductible : on ne le laisse pas dans un cache.
    expect($r->headers->get('Cache-Control'))->toContain('no-store');
});

it('ne tire pas deux fois la même adresse (une chance sur 29^3200)', function () {
    expect(appel('GET', '/hasard')->json('adresse'))->not->toBe(appel('GET', '/hasard')->json('adresse'));
});

it('accepte un jeton de membre, un faux jeton ou aucun jeton : le tirage est le même pour tous', function () {
    $membre = User::factory()->create();

    appel('GET', '/hasard', membre: $membre)->assertOk();
    appel('GET', '/hasard', entetes: ['Authorization' => 'Bearer faux-jeton'])->assertOk();
    appel('GET', '/hasard')->assertOk();
});

it('répond 429 au contrat quand le quota de lecture est dépassé', function () {
    for ($i = 0; $i < 120; $i++) {
        appel('GET', '/hasard')->assertOk();
    }

    $r = appel('GET', '/hasard');
    attendErreurContrat($r, 429, 'ERR_TOO_MANY_REQUESTS', null);
    expect((int) $r->headers->get('Retry-After'))->toBeGreaterThan(0);
});
