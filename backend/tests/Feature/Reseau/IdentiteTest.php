<?php

use Babel\Moteur\Moteur;

/*
 * GET /identite (B6, A9). La clé de test A (phpunit.xml) donne l'empreinte de l'exemple du contrat.
 */
it('renvoie l\'identité exacte du contrat, avec l\'empreinte de la clé de test A', function () {
    bibliothequeLocale(['description' => 'Une bibliothèque de démonstration, avec la clé de test A du moteur.']);

    $r = $this->getJson('/api/v1/identite');

    $r->assertOk();
    expect($r->json())->toBe([
        'identifiant' => 'exemple',
        'nom' => "La bibliothèque d'exemple",
        'description' => 'Une bibliothèque de démonstration, avec la clé de test A du moteur.',
        'empreinte_cle' => 'f85a8e8ae6a8a1ceb96d8884d2d45c00c3cc73c95e1bdf4e5cba35664bb0c637',
        'algorithme' => 'babel-moteur/v1',
        'contrat' => '1.1.0',
        'application_web' => 'http://localhost:4200',
    ]);
});

it('n\'est pas enveloppée dans « data » et ne contient que les champs prévus', function () {
    bibliothequeLocale();

    $r = $this->getJson('/api/v1/identite');

    expect(array_keys($r->json()))->toBe(['identifiant', 'nom', 'description', 'empreinte_cle', 'algorithme', 'contrat', 'application_web']);
    expect($r->json('empreinte_cle'))->toMatch('/^[0-9a-f]{64}$/');
    expect($r->json('contrat'))->toMatch('/^1\.[0-9]+\.[0-9]+$/');
});

it('renvoie une description vide (jamais null) quand la bibliothèque n\'en a pas', function () {
    bibliothequeLocale(['description' => null]);

    $this->getJson('/api/v1/identite')->assertOk()->assertJsonPath('description', '');
});

it('répond sans jeton ni compte', function () {
    bibliothequeLocale();

    $this->getJson('/api/v1/identite')->assertOk();
});

it('répond avec la configuration si la base n\'est pas encore amorcée', function () {
    config(['babel.identifiant' => 'babel-3', 'babel.nom' => 'Babel 3']);

    $this->getJson('/api/v1/identite')
        ->assertOk()
        ->assertJsonPath('identifiant', 'babel-3')
        ->assertJsonPath('nom', 'Babel 3')
        ->assertJsonPath('description', '');
});

it('ne laisse jamais voir la clé dans la réponse', function () {
    bibliothequeLocale();
    $cle = config('babel.cle');

    $corps = $this->getJson('/api/v1/identite')->getContent();

    expect($corps)->not->toContain($cle)->not->toContain('AAECAwQFBgcICQoLDA0O');
});

it('répond 500 (et pas 422) quand la clé du moteur est absente', function () {
    bibliothequeLocale();
    config(['babel.cle' => null]);
    app()->forgetInstance(Moteur::class);

    $this->getJson('/api/v1/identite')->assertStatus(500);
});
