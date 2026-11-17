<?php

use App\Enums\EtatBibliotheque;
use App\Jobs\ControlerPair;
use App\Models\Bibliotheque;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

const EMPREINTE_A = 'f85a8e8ae6a8a1ceb96d8884d2d45c00c3cc73c95e1bdf4e5cba35664bb0c637';
const EMPREINTE_B = '0000000000000000000000000000000000000000000000000000000000000001';

function identitePair(array $surcharge = []): array
{
    return $surcharge + [
        'identifiant' => 'voisine',
        'nom' => 'La voisine',
        'description' => 'Une voisine.',
        'empreinte_cle' => EMPREINTE_A,
        'algorithme' => 'babel-moteur/v1',
        'contrat' => '1.1.0',
        'application_web' => 'https://voisine-web.example.test',
    ];
}

function pairVoisine(array $attributs = []): Bibliotheque
{
    return Bibliotheque::factory()->create($attributs + [
        'identifiant' => 'voisine', 'url_actuelle' => 'https://voisine.example.test', 'empreinte_cle' => null,
    ]);
}

function controler(Bibliotheque $pair): Bibliotheque
{
    (new ControlerPair($pair->id))->handle(app(App\Services\Annuaire\ControleurDePair::class));

    return $pair->fresh();
}

it("lit l'identité du pair sur /api/v1/identite avec 2 secondes au plus", function () {
    Http::fake(['*' => Http::response(identitePair())]);

    controler(pairVoisine());

    Http::assertSent(fn (Request $r) => $r->method() === 'GET' && $r->url() === 'https://voisine.example.test/api/v1/identite'
        && ! $r->hasHeader('Authorization')); // le secret d'annonce ne sert jamais entre bibliothèques
});

it("passe le pair en ligne, garde l'identité et l'empreinte, remet le compteur à zéro", function () {
    Http::fake(['*' => Http::response(identitePair())]);

    $pair = controler(pairVoisine(['echecs_consecutifs' => 2, 'etat' => EtatBibliotheque::Inconnu]));

    expect($pair->etat)->toBe(EtatBibliotheque::EnLigne)
        ->and($pair->echecs_consecutifs)->toBe(0)
        ->and($pair->nom)->toBe('La voisine')
        ->and($pair->empreinte_cle)->toBe(EMPREINTE_A)
        ->and($pair->dernier_contact_le)->not->toBeNull()
        ->and($pair->cle_changee_le)->toBeNull(); // première empreinte vue : pas un changement
});

it('ne date pas cle_changee_le quand l\'empreinte est la même', function () {
    Http::fake(['*' => Http::response(identitePair())]);

    expect(controler(pairVoisine(['empreinte_cle' => EMPREINTE_A]))->cle_changee_le)->toBeNull();
});

it("date cle_changee_le et garde la nouvelle empreinte quand l'empreinte change", function () {
    Http::fake(['*' => Http::response(identitePair(['empreinte_cle' => EMPREINTE_B]))]);

    $pair = controler(pairVoisine(['empreinte_cle' => EMPREINTE_A]));

    expect($pair->empreinte_cle)->toBe(EMPREINTE_B)->and($pair->cle_changee_le)->not->toBeNull();
});

it('passe hors ligne au troisième échec de suite, pas avant', function () {
    Http::fake(['*' => Http::response('', 500)]);
    $pair = pairVoisine(['etat' => EtatBibliotheque::EnLigne]);

    $pair = controler($pair);
    expect($pair->echecs_consecutifs)->toBe(1)->and($pair->etat)->toBe(EtatBibliotheque::EnLigne);
    $pair = controler($pair);
    expect($pair->echecs_consecutifs)->toBe(2)->and($pair->etat)->toBe(EtatBibliotheque::EnLigne);
    $pair = controler($pair);
    expect($pair->echecs_consecutifs)->toBe(3)->and($pair->etat)->toBe(EtatBibliotheque::HorsLigne);
});

it('remet en ligne un pair hors ligne dès un contrôle réussi', function () {
    Http::fake(['*' => Http::response(identitePair())]);

    $pair = controler(pairVoisine(['etat' => EtatBibliotheque::HorsLigne, 'echecs_consecutifs' => 5]));

    expect($pair->etat)->toBe(EtatBibliotheque::EnLigne)->and($pair->echecs_consecutifs)->toBe(0);
});

it("compte un pair injoignable ou trop lent comme un échec", function () {
    Http::fake(fn () => throw new ConnectionException('cURL error 28: timeout'));

    expect(controler(pairVoisine())->echecs_consecutifs)->toBe(1);
});

it("ne compte pas un 429 comme un échec : rien ne bouge", function () {
    Http::fake(['*' => Http::response(['erreurs' => [['code' => 'ERR_TOO_MANY_REQUESTS', 'champ' => null, 'message' => 'Trop']]], 429, ['Retry-After' => '5'])]);
    $pair = pairVoisine(['echecs_consecutifs' => 2, 'etat' => EtatBibliotheque::EnLigne]);

    $pair = controler($pair);

    expect($pair->echecs_consecutifs)->toBe(2)->and($pair->etat)->toBe(EtatBibliotheque::EnLigne);
});

it('compte une réponse hors contrat comme un échec', function (string $cas) {
    $reponse = match ($cas) {
        'pas du JSON' => Http::response('<html>bonjour</html>', 200),
        'une liste' => Http::response([1, 2]),
        'champ manquant' => Http::response(array_diff_key(identitePair(), ['contrat' => 1])),
        'empreinte mal formée' => Http::response(identitePair(['empreinte_cle' => 'ABC'])),
        'algorithme inconnu' => Http::response(identitePair(['algorithme' => 'autre/v9'])),
        'champ en trop' => Http::response(identitePair(['cle' => 'secret'])),
        'description absente' => Http::response(array_diff_key(identitePair(), ['description' => 1])),
        'mauvais identifiant (l\'URL ne désigne plus ce pair)' => Http::response(identitePair(['identifiant' => 'autre-biblio'])),
    };
    Http::fake(['*' => $reponse]);

    $pair = controler(pairVoisine(['empreinte_cle' => EMPREINTE_A, 'nom' => 'Nom gardé']));

    expect($pair->echecs_consecutifs)->toBe(1)
        ->and($pair->nom)->toBe('Nom gardé')
        ->and($pair->cle_changee_le)->toBeNull();
})->with([
    'pas du JSON', 'une liste', 'champ manquant', 'empreinte mal formée', 'algorithme inconnu',
    'champ en trop', 'description absente', 'mauvais identifiant (l\'URL ne désigne plus ce pair)',
]);

it("ne se contrôle pas soi-même", function () {
    Http::fake();

    controler(bibliothequeLocale());

    Http::assertNothingSent();
});

it('dépose une tâche par pair, hors ligne compris, et aucune pour nous', function () {
    Queue::fake();
    bibliothequeLocale();
    $a = pairVoisine();
    $b = Bibliotheque::factory()->create(['etat' => EtatBibliotheque::HorsLigne]);

    $this->artisan('babel:controler-pairs')->assertSuccessful();

    Queue::assertPushed(ControlerPair::class, 2);
    Queue::assertPushed(ControlerPair::class, fn ($j) => $j->bibliothequeId === $a->id);
    Queue::assertPushed(ControlerPair::class, fn ($j) => $j->bibliothequeId === $b->id);
});
