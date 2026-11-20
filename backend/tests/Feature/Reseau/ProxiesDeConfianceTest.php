<?php

use App\Providers\AppServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
 * Derrière ngrok (A10) : tous les pairs arrivent de l'adresse du proxy. Sans confiance envers X-Forwarded-For,
 * $request->ip() serait la même pour tous et leurs 60 requêtes/minute seraient PARTAGÉES.
 */

beforeEach(function () {
    bibliothequeLocale();
    Route::get('/sonde-ip', fn (Request $r) => $r->ip());
});

it('croit X-Forwarded-For quand la connexion vient de la boucle locale (l\'agent ngrok de la même machine)', function () {
    $r = $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])->get('/sonde-ip', ['X-Forwarded-For' => '203.0.113.5']);

    expect($r->getContent())->toBe('203.0.113.5');
});

it('ne croit PAS X-Forwarded-For quand la connexion ne vient pas d\'un proxy de confiance', function () {
    // Un client direct qui écrit l'en-tête lui-même ne peut pas se faire passer pour une autre adresse.
    $r = $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])->get('/sonde-ip', ['X-Forwarded-For' => '203.0.113.5']);

    expect($r->getContent())->toBe('198.51.100.7');
});

it('ne se laisse pas tromper par une adresse inscrite d\'avance dans X-Forwarded-For', function () {
    // ngrok AJOUTE l'adresse réelle à la fin : celle qu'un client a écrite avant n'est pas retenue.
    $r = $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])->get('/sonde-ip', ['X-Forwarded-For' => '1.2.3.4, 203.0.113.5']);

    expect($r->getContent())->toBe('203.0.113.5');
});

it('sans X-Forwarded-For, l\'adresse reste celle de la connexion', function () {
    $r = $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])->get('/sonde-ip');

    expect($r->getContent())->toBe('127.0.0.1');
});

it('donne à chaque pair son propre quota de 60 requêtes par minute derrière le proxy', function () {
    $proxy = ['REMOTE_ADDR' => '127.0.0.1'];

    for ($i = 0; $i < 60; $i++) {
        $this->withServerVariables($proxy)->getJson('/api/v1/identite', ['X-Forwarded-For' => '203.0.113.5'])->assertOk();
    }
    // Le 61e appel du pair A est refusé…
    attendErreurContrat($this->withServerVariables($proxy)->getJson('/api/v1/identite', ['X-Forwarded-For' => '203.0.113.5']), 429, 'ERR_TOO_MANY_REQUESTS', null);
    // … mais le pair B, venu par le même proxy, n'est pas touché.
    $this->withServerVariables($proxy)->getJson('/api/v1/identite', ['X-Forwarded-For' => '203.0.113.6'])->assertOk();
});

it('lit les proxies de confiance dans la configuration, et « * » veut dire tous', function () {
    config(['babel.trusted_proxies' => ['*']]);
    (new AppServiceProvider($this->app))->boot();

    $r = $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])->get('/sonde-ip', ['X-Forwarded-For' => '203.0.113.5']);

    expect($r->getContent())->toBe('203.0.113.5');
});

it('ne fait confiance à personne quand la liste est vide', function () {
    config(['babel.trusted_proxies' => []]);
    (new AppServiceProvider($this->app))->boot();

    $r = $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])->get('/sonde-ip', ['X-Forwarded-For' => '203.0.113.5']);

    expect($r->getContent())->toBe('127.0.0.1');
});

it('a la boucle locale pour valeur par défaut', function () {
    expect(config('babel.trusted_proxies'))->toBe(['127.0.0.1', '::1']);
});
