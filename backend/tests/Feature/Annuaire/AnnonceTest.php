<?php

use App\Enums\EtatBibliotheque;
use App\Models\Bibliotheque;
use App\Services\Annuaire\AnnuaireHttp;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config([
        'babel.annuaire_url' => 'https://annuaire.example.test/api',
        'babel.announce_secret' => 'secret-d-annonce-de-test',
        'babel.url_publique' => 'https://nous.example.test',
    ]);
    bibliothequeLocale();
});

it("s'annonce avec l'identifiant, l'URL du moment et le secret d'annonce", function () {
    Http::fake([
        '*/annonce' => Http::response('', 204),
        '*/bibliotheques' => Http::response(['elements' => []]),
    ]);

    $this->artisan('babel:annoncer')->assertSuccessful();

    Http::assertSent(fn (Request $r) => $r->method() === 'POST'
        && $r->url() === 'https://annuaire.example.test/api'.AnnuaireHttp::CHEMIN_ANNONCE
        && $r->header('Authorization') === ['Bearer secret-d-annonce-de-test']
        && $r['identifiant'] === 'exemple'
        && $r['url'] === 'https://nous.example.test');
});

it("range les pairs de l'annuaire dans la table, sans nous y remettre", function () {
    Http::fake([
        '*/annonce' => Http::response('', 204),
        '*/bibliotheques' => Http::response(['elements' => [
            ['identifiant' => 'exemple', 'url' => 'https://nous.example.test'],
            ['identifiant' => 'voisine', 'url' => 'https://voisine.example.test/', 'nom' => 'La voisine'],
            ['identifiant' => 'Mauvais Id', 'url' => 'https://x.example.test'],
            ['identifiant' => 'sans-schema', 'url' => 'ftp://x.example.test'],
            'pas un objet',
        ]]),
    ]);

    $this->artisan('babel:annoncer')->expectsOutputToContain('1 pair(s)')->assertSuccessful();

    expect(Bibliotheque::count())->toBe(2);
    $voisine = Bibliotheque::where('identifiant', 'voisine')->first();
    expect($voisine->url_actuelle)->toBe('https://voisine.example.test')
        ->and($voisine->nom)->toBe('La voisine')
        ->and($voisine->etat)->toBe(EtatBibliotheque::Inconnu)
        ->and($voisine->vue_annuaire_le)->not->toBeNull()
        ->and($voisine->est_locale)->toBeFalse();
});

it("met à jour l'URL d'un pair connu sans toucher à son état ni à son compteur", function () {
    Bibliotheque::factory()->create([
        'identifiant' => 'voisine', 'url_actuelle' => 'https://ancienne.example.test',
        'etat' => EtatBibliotheque::HorsLigne, 'echecs_consecutifs' => 3,
    ]);
    Http::fake([
        '*/annonce' => Http::response('', 204),
        '*/bibliotheques' => Http::response(['elements' => [['identifiant' => 'voisine', 'url' => 'https://nouvelle.example.test']]]),
    ]);

    $this->artisan('babel:annoncer')->assertSuccessful();

    $voisine = Bibliotheque::where('identifiant', 'voisine')->first();
    expect(Bibliotheque::where('identifiant', 'voisine')->count())->toBe(1)
        ->and($voisine->url_actuelle)->toBe('https://nouvelle.example.test')
        ->and($voisine->etat)->toBe(EtatBibliotheque::HorsLigne)
        ->and($voisine->echecs_consecutifs)->toBe(3);
});

it("refuse clairement quand l'annuaire n'est pas configuré, sans rien envoyer", function () {
    config(['babel.annuaire_url' => null]);
    Http::fake();

    $this->artisan('babel:annoncer')->expectsOutputToContain('BABEL_ANNUAIRE_URL')->assertFailed();

    Http::assertNothingSent();
});

it("refuse clairement quand le secret d'annonce manque, sans rien envoyer", function () {
    config(['babel.announce_secret' => '']);
    Http::fake();

    $this->artisan('babel:annoncer')->expectsOutputToContain('BABEL_ANNOUNCE_SECRET')->assertFailed();

    Http::assertNothingSent();
});

it("échoue proprement quand l'annuaire refuse l'annonce, répond de travers ou est injoignable", function (string $cas) {
    Http::fake(match ($cas) {
        'annonce refusée' => ['*/annonce' => Http::response('', 401)],
        'liste sans elements' => ['*/annonce' => Http::response('', 204), '*/bibliotheques' => Http::response(['x' => 1])],
        'annuaire injoignable' => fn () => throw new Illuminate\Http\Client\ConnectionException('timeout'),
    });

    $this->artisan('babel:annoncer')->assertFailed();
    expect(Bibliotheque::count())->toBe(1);
})->with(['annonce refusée', 'liste sans elements', 'annuaire injoignable']);

it('dépose un contrôle par pair avec --controle', function () {
    Queue::fake();
    Http::fake([
        '*/annonce' => Http::response('', 204),
        '*/bibliotheques' => Http::response(['elements' => [
            ['identifiant' => 'voisine', 'url' => 'https://voisine.example.test'],
            ['identifiant' => 'lointaine', 'url' => 'https://lointaine.example.test'],
        ]]),
    ]);

    $this->artisan('babel:annoncer', ['--controle' => true])->assertSuccessful();

    Queue::assertPushed(App\Jobs\ControlerPair::class, 2);
});

it('planifie le contrôle des pairs et, si l\'annuaire est configuré, l\'annonce', function () {
    Artisan::call('schedule:list');

    expect(Artisan::output())->toContain('babel:annoncer')->toContain('babel:controler-pairs');
});
