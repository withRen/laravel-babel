<?php

use App\Enums\EtatBibliotheque;
use App\Events\RechercheReseauResultat;
use App\Events\RechercheReseauTerminee;
use App\Jobs\InterrogerPairPourRecherche;
use App\Models\Bibliotheque;
use App\Models\Recherche;
use App\Models\User;
use App\Services\Reseau\RechercheReseau;
use Babel\Moteur\Moteur;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/*
 * La recherche sur tout le réseau (V15) : POST /app/recherches-reseau et GET /app/recherches-reseau/{identifiant}.
 * File de tâches « sync » dans phpunit.xml : les tâches tournent dans la requête, ce qui rend les résultats
 * vérifiables. Un test avec Queue::fake() montre, lui, que la réponse 202 n'attend pas les pairs.
 */

const RECHERCHE_ID = '3f2c8a1e-6b7d-4c1a-9e2f-0a1b2c3d4e5f';

function lancerRechercheReseau($test, array $corps = [], array $entetes = [])
{
    return $test->postJson('/api/v1/app/recherches-reseau', $corps + [
        'identifiant' => RECHERCHE_ID, 'texte' => "Où est passé l'été ?", 'mode' => 'exact',
    ], $entetes);
}

function pairEnLigne(string $identifiant): Bibliotheque
{
    return Bibliotheque::factory()->enLigne()->create(['identifiant' => $identifiant, 'url_actuelle' => "https://{$identifiant}.example.test"]);
}

/** Un pair qui répond bien : il renvoie « sa » adresse pour le texte reçu. */
function reponsePairTrouve(string $identifiant, string $adresse = '0-w1-s1-v1:1')
{
    return fn (Request $q) => Http::response([
        'bibliotheque' => $identifiant, 'texte_normalise' => $q['texte'], 'mode' => $q['mode'], 'adresse' => $adresse,
    ]);
}

beforeEach(function () {
    $this->locale = bibliothequeLocale();
});

/* ---------------------------------------------------------------- lancer */

it('lance la recherche : normalise le texte, cherche chez nous et répond 202 au format du contrat', function () {
    pairEnLigne('voisine');
    Http::fake(['*' => reponsePairTrouve('voisine')]);

    $r = lancerRechercheReseau($this);

    $r->assertStatus(202);
    expect(array_keys($r->json()))->toBe(['identifiant', 'texte_normalise', 'mode', 'locale', 'interroges', 'absents', 'delai_pair_ms', 'canal']);
    expect($r->json('identifiant'))->toBe(RECHERCHE_ID)
        ->and($r->json('texte_normalise'))->toBe('ou est passe l ete')
        ->and($r->json('mode'))->toBe('exact')
        ->and($r->json('locale'))->toBe(['bibliotheque' => 'exemple', 'adresse' => app(Moteur::class)->chercher('ou est passe l ete', 'exact')])
        ->and($r->json('interroges'))->toBe(['voisine'])
        ->and($r->json('absents'))->toBe([])
        ->and($r->json('delai_pair_ms'))->toBe(2000)
        ->and($r->json('canal'))->toBe('recherche-reseau.'.RECHERCHE_ID);
});

it('interroge les pairs en ligne et met tous les autres parmi les absents, sans les appeler', function () {
    pairEnLigne('voisine');
    pairEnLigne('lointaine');
    Bibliotheque::factory()->create(['identifiant' => 'eteinte', 'etat' => EtatBibliotheque::HorsLigne, 'url_actuelle' => 'https://eteinte.example.test']);
    Bibliotheque::factory()->create(['identifiant' => 'nouvelle', 'etat' => EtatBibliotheque::Inconnu, 'url_actuelle' => 'https://nouvelle.example.test']);
    Http::fake(['*' => fn (Request $q) => reponsePairTrouve(explode('.', parse_url($q->url(), PHP_URL_HOST))[0])($q)]);

    $r = lancerRechercheReseau($this);

    expect($r->json('interroges'))->toBe(['lointaine', 'voisine'])->and($r->json('absents'))->toBe(['eteinte', 'nouvelle']);
    Http::assertNotSent(fn (Request $q) => str_contains($q->url(), 'eteinte') || str_contains($q->url(), 'nouvelle'));
    Http::assertSentCount(2);
});

it('envoie aux pairs le texte DÉJÀ normalisé et le mode, sans jeton ni autre en-tête d\'identité', function () {
    pairEnLigne('voisine');
    Http::fake(['*' => reponsePairTrouve('voisine')]);

    lancerRechercheReseau($this, ['mode' => 'bruit']);

    Http::assertSent(fn (Request $q) => $q->method() === 'POST' && $q->url() === 'https://voisine.example.test/api/v1/recherches'
        && $q->data() === ['texte' => 'ou est passe l ete', 'mode' => 'bruit'] && ! $q->hasHeader('Authorization'));
});

it('lance la recherche sans pair : le bilan est publié tout de suite et l\'état est terminé', function () {
    Event::fake([RechercheReseauResultat::class, RechercheReseauTerminee::class]);
    Http::fake();

    $r = lancerRechercheReseau($this);

    $r->assertStatus(202);
    expect($r->json('interroges'))->toBe([])->and($r->json('absents'))->toBe([]);
    Event::assertNotDispatched(RechercheReseauResultat::class);
    Event::assertDispatched(RechercheReseauTerminee::class, fn ($e) => $e->identifiant === RECHERCHE_ID && $e->interroges === 0 && $e->ontRepondu === 0);
    $this->getJson('/api/v1/app/recherches-reseau/'.RECHERCHE_ID)->assertOk()->assertJsonPath('etat', 'terminee');
    Http::assertNothingSent();
});

it('répond tout de suite : les pairs sont interrogés par des tâches de fond, une par pair', function () {
    Queue::fake();
    pairEnLigne('voisine');
    pairEnLigne('lointaine');
    Http::fake();

    $r = lancerRechercheReseau($this);

    $r->assertStatus(202);
    Queue::assertPushed(InterrogerPairPourRecherche::class, 2);
    Http::assertNothingSent(); // la requête HTTP n'attend aucun pair
    $etat = $this->getJson('/api/v1/app/recherches-reseau/'.RECHERCHE_ID);
    expect($etat->json('etat'))->toBe('en_cours')->and($etat->json('resultats'))->toBe([]);
});

/* ------------------------------------------------------------ résultats */

it('publie un résultat par pair puis le bilan, sur le canal public de la recherche', function () {
    Event::fake([RechercheReseauResultat::class, RechercheReseauTerminee::class]);
    pairEnLigne('voisine');
    pairEnLigne('lointaine');
    Bibliotheque::factory()->create(['identifiant' => 'eteinte', 'etat' => EtatBibliotheque::HorsLigne]);
    Http::fake(['*' => fn (Request $q) => reponsePairTrouve(explode('.', parse_url($q->url(), PHP_URL_HOST))[0])($q)]);

    lancerRechercheReseau($this);

    Event::assertDispatchedTimes(RechercheReseauResultat::class, 2);
    Event::assertDispatched(RechercheReseauResultat::class, function (RechercheReseauResultat $e) {
        return $e->broadcastOn()->name === 'recherche-reseau.'.RECHERCHE_ID && $e->broadcastAs() === 'resultat'
            && array_keys($e->broadcastWith()) === ['identifiant', 'resultat']
            && array_keys($e->resultat) === ['bibliotheque', 'statut', 'adresse', 'texte_normalise', 'recu_le']
            && $e->resultat['statut'] === 'trouve' && $e->resultat['adresse'] === '0-w1-s1-v1:1'
            && $e->resultat['texte_normalise'] === 'ou est passe l ete';
    });
    Event::assertDispatchedTimes(RechercheReseauTerminee::class, 1);
    Event::assertDispatched(RechercheReseauTerminee::class, function (RechercheReseauTerminee $e) {
        return $e->broadcastOn()->name === 'recherche-reseau.'.RECHERCHE_ID && $e->broadcastAs() === 'terminee'
            && $e->broadcastWith() === ['identifiant' => RECHERCHE_ID, 'interroges' => 2, 'ont_repondu' => 2, 'absents' => ['eteinte']];
    });
});

it('lit l\'état : les résultats arrivés, dans l\'ordre d\'arrivée', function () {
    pairEnLigne('voisine');
    Http::fake(['*' => reponsePairTrouve('voisine', 'babel-w2-s3-v17:204')]);
    lancerRechercheReseau($this);

    $r = $this->getJson('/api/v1/app/recherches-reseau/'.RECHERCHE_ID);

    $r->assertOk();
    expect(array_keys($r->json()))->toBe(['identifiant', 'texte_normalise', 'mode', 'etat', 'locale', 'interroges', 'absents', 'resultats', 'lancee_le']);
    expect($r->json('etat'))->toBe('terminee')
        ->and($r->json('locale.bibliotheque'))->toBe('exemple')
        ->and($r->json('resultats.0'))->toMatchArray(['bibliotheque' => 'voisine', 'statut' => 'trouve', 'adresse' => 'babel-w2-s3-v17:204', 'texte_normalise' => 'ou est passe l ete'])
        ->and($r->json('resultats.0.recu_le'))->toBeString()
        ->and($r->json('lancee_le'))->toBeString();
});

it('marque un pair éteint ou trop lent « sans_reponse », compte son échec et publie quand même le bilan', function () {
    Event::fake([RechercheReseauResultat::class, RechercheReseauTerminee::class]);
    $voisine = pairEnLigne('voisine');
    pairEnLigne('lointaine');
    Http::fake([
        'https://voisine.example.test/*' => fn () => throw new ConnectionException('cURL error 28: timeout'),
        'https://lointaine.example.test/*' => reponsePairTrouve('lointaine'),
    ]);

    lancerRechercheReseau($this)->assertStatus(202);

    $etat = $this->getJson('/api/v1/app/recherches-reseau/'.RECHERCHE_ID)->json();
    $parPair = collect($etat['resultats'])->keyBy('bibliotheque');
    expect($parPair['voisine'])->toMatchArray(['statut' => 'sans_reponse', 'adresse' => null, 'texte_normalise' => null])
        ->and($parPair['lointaine']['statut'])->toBe('trouve');
    expect($voisine->fresh()->echecs_consecutifs)->toBe(1);
    Event::assertDispatched(RechercheReseauTerminee::class, fn ($e) => $e->interroges === 2 && $e->ontRepondu === 1 && $e->absents === ['voisine']);
});

it('marque un pair qui répond 429 « occupe » et ne compte AUCUN échec', function () {
    $voisine = pairEnLigne('voisine');
    $voisine->update(['echecs_consecutifs' => 2]);
    Http::fake(['*' => Http::response('', 429, ['Retry-After' => '5'])]);

    lancerRechercheReseau($this);

    $etat = $this->getJson('/api/v1/app/recherches-reseau/'.RECHERCHE_ID)->json();
    expect($etat['resultats'][0])->toMatchArray(['bibliotheque' => 'voisine', 'statut' => 'occupe', 'adresse' => null]);
    expect($voisine->fresh())->echecs_consecutifs->toBe(2)->etat->toBe(EtatBibliotheque::EnLigne);
});

it('traite la réponse d\'un pair hors contrat comme « sans_reponse », avec un échec', function (array $corps) {
    $voisine = pairEnLigne('voisine');
    Http::fake(['*' => Http::response($corps)]);

    lancerRechercheReseau($this);

    expect($this->getJson('/api/v1/app/recherches-reseau/'.RECHERCHE_ID)->json('resultats.0'))->toMatchArray(['statut' => 'sans_reponse', 'adresse' => null]);
    expect($voisine->fresh()->echecs_consecutifs)->toBe(1);
})->with([
    'champ en trop' => [['bibliotheque' => 'voisine', 'texte_normalise' => 'ou est passe l ete', 'mode' => 'exact', 'adresse' => '0-w1-s1-v1:1', 'x' => 1]],
    'adresse mal écrite' => [['bibliotheque' => 'voisine', 'texte_normalise' => 'ou est passe l ete', 'mode' => 'exact', 'adresse' => '0-W1-s1-v1:1']],
    'adresse d\'un livre' => [['bibliotheque' => 'voisine', 'texte_normalise' => 'ou est passe l ete', 'mode' => 'exact', 'adresse' => '0-w1-s1-v1']],
    'adresse hors limites' => [['bibliotheque' => 'voisine', 'texte_normalise' => 'ou est passe l ete', 'mode' => 'exact', 'adresse' => '0-w1-s1-v33:1']],
    'autre bibliothèque' => [['bibliotheque' => 'imposteur', 'texte_normalise' => 'ou est passe l ete', 'mode' => 'exact', 'adresse' => '0-w1-s1-v1:1']],
    'autre mode' => [['bibliotheque' => 'voisine', 'texte_normalise' => 'ou est passe l ete', 'mode' => 'bruit', 'adresse' => '0-w1-s1-v1:1']],
    'texte avec majuscule' => [['bibliotheque' => 'voisine', 'texte_normalise' => 'Ou est', 'mode' => 'exact', 'adresse' => '0-w1-s1-v1:1']],
    'objet vide' => [[]],
]);

it('traite une erreur HTTP d\'un pair (500, 404) comme « sans_reponse »', function (int $statut) {
    $voisine = pairEnLigne('voisine');
    Http::fake(['*' => Http::response(['erreurs' => []], $statut)]);

    lancerRechercheReseau($this);

    expect($this->getJson('/api/v1/app/recherches-reseau/'.RECHERCHE_ID)->json('resultats.0.statut'))->toBe('sans_reponse');
    expect($voisine->fresh()->echecs_consecutifs)->toBe(1);
})->with([500, 404, 422]);

it('garde le texte normalisé du pair s\'il diffère du nôtre : l\'un des deux applique mal F3', function () {
    pairEnLigne('voisine');
    Http::fake(['*' => fn (Request $q) => Http::response([
        'bibliotheque' => 'voisine', 'texte_normalise' => 'ou est passe  l ete', 'mode' => 'exact', 'adresse' => '0-w1-s1-v1:1',
    ])]);

    lancerRechercheReseau($this);

    expect($this->getJson('/api/v1/app/recherches-reseau/'.RECHERCHE_ID)->json('resultats.0.texte_normalise'))->toBe('ou est passe  l ete');
});

it('passe le pair hors ligne au troisième échec de suite, ici pendant les recherches', function () {
    $voisine = pairEnLigne('voisine');
    Http::fake(['*' => Http::response('panne', 500)]);

    foreach (['3f2c8a1e-6b7d-4c1a-9e2f-0a1b2c3d4e51', '3f2c8a1e-6b7d-4c1a-9e2f-0a1b2c3d4e52', '3f2c8a1e-6b7d-4c1a-9e2f-0a1b2c3d4e53'] as $id) {
        lancerRechercheReseau($this, ['identifiant' => $id]);
    }
    expect($voisine->fresh())->echecs_consecutifs->toBe(3)->etat->toBe(EtatBibliotheque::HorsLigne);

    // La recherche suivante ne l'interroge plus : il est parmi les absents.
    Http::fake();
    $r = lancerRechercheReseau($this, ['identifiant' => '3f2c8a1e-6b7d-4c1a-9e2f-0a1b2c3d4e54']);
    expect($r->json('interroges'))->toBe([])->and($r->json('absents'))->toBe(['voisine']);
    Http::assertNothingSent();
});

it('range chaque résultat sans écraser les autres, et ne compte pas deux fois un résultat rejoué', function () {
    Queue::fake();
    pairEnLigne('voisine');
    pairEnLigne('lointaine');
    lancerRechercheReseau($this);
    Event::fake([RechercheReseauResultat::class, RechercheReseauTerminee::class]);
    $recherche = app(RechercheReseau::class);
    $resultat = fn (string $b) => ['bibliotheque' => $b, 'statut' => 'trouve', 'adresse' => '0-w1-s1-v1:1', 'texte_normalise' => 'ou est passe l ete'];

    $recherche->enregistrer(RECHERCHE_ID, $resultat('voisine'));
    $recherche->enregistrer(RECHERCHE_ID, $resultat('voisine')); // rejoué : ignoré
    expect($this->getJson('/api/v1/app/recherches-reseau/'.RECHERCHE_ID)->json())->etat->toBe('en_cours');
    Event::assertNotDispatched(RechercheReseauTerminee::class);

    $recherche->enregistrer(RECHERCHE_ID, $resultat('lointaine'));
    $etat = $this->getJson('/api/v1/app/recherches-reseau/'.RECHERCHE_ID)->json();
    expect($etat['etat'])->toBe('terminee')->and($etat['resultats'])->toHaveCount(2);
    Event::assertDispatchedTimes(RechercheReseauResultat::class, 2);
    Event::assertDispatchedTimes(RechercheReseauTerminee::class, 1);
});

it('garde l\'état lisible même si Reverb est éteint', function () {
    pairEnLigne('voisine');
    Http::fake(['*' => reponsePairTrouve('voisine')]);
    Event::listen(RechercheReseauResultat::class, fn () => throw new RuntimeException('Reverb est éteint'));
    Event::listen(RechercheReseauTerminee::class, fn () => throw new RuntimeException('Reverb est éteint'));

    lancerRechercheReseau($this)->assertStatus(202);

    $this->getJson('/api/v1/app/recherches-reseau/'.RECHERCHE_ID)->assertOk()->assertJsonPath('etat', 'terminee')->assertJsonPath('resultats.0.statut', 'trouve');
});

it('garde l\'état au moins 10 minutes', function () {
    Http::fake();
    lancerRechercheReseau($this)->assertStatus(202);

    $this->travel(11)->minutes();
    $this->getJson('/api/v1/app/recherches-reseau/'.RECHERCHE_ID)->assertOk();

    $this->travel(20)->minutes();
    attendErreurContrat($this->getJson('/api/v1/app/recherches-reseau/'.RECHERCHE_ID), 404, 'ERR_NOT_FOUND', 'identifiant');
});

/* ------------------------------------------------------------- refus */

it('refuse un identifiant qui n\'est pas un UUID v4 (422, ERR_INVALID_PARAMETER sur « identifiant »)', function (mixed $identifiant) {
    Http::fake();

    attendErreurContrat(lancerRechercheReseau($this, ['identifiant' => $identifiant]), 422, 'ERR_INVALID_PARAMETER', 'identifiant');
    Http::assertNothingSent();
})->with([
    'absent' => [null],
    'vide' => [''],
    'pas un uuid' => ['abc'],
    'version 1' => ['3f2c8a1e-6b7d-1c1a-9e2f-0a1b2c3d4e5f'],
    'majuscules' => ['3F2C8A1E-6B7D-4C1A-9E2F-0A1B2C3D4E5F'],
    'un nombre' => [12],
]);

it('refuse le texte et le mode avec les codes du moteur : une seule erreur, dans l\'ordre mode, vide, longueur', function () {
    Http::fake();
    $id = fn (int $n) => '3f2c8a1e-6b7d-4c1a-9e2f-0a1b2c3d4e'.str_pad((string) $n, 2, '0', STR_PAD_LEFT);

    attendErreurContrat(lancerRechercheReseau($this, ['identifiant' => $id(10), 'mode' => 'Exact']), 422, 'ERR_UNKNOWN_MODE', 'mode');
    attendErreurContrat(lancerRechercheReseau($this, ['identifiant' => $id(11), 'texte' => '?!']), 422, 'ERR_EMPTY_QUERY', 'texte');
    attendErreurContrat(lancerRechercheReseau($this, ['identifiant' => $id(12), 'texte' => str_repeat('a', 3201)]), 422, 'ERR_TEXT_TOO_LONG', 'texte');
    // Mode inconnu ET texte vide : le mode d'abord.
    $r = lancerRechercheReseau($this, ['identifiant' => $id(13), 'mode' => 'x', 'texte' => '']);
    attendErreurContrat($r, 422, 'ERR_UNKNOWN_MODE', 'mode');
    expect($r->json('erreurs'))->toHaveCount(1);
    // Ni texte ni mode dans le corps : traités comme vides.
    attendErreurContrat($this->postJson('/api/v1/app/recherches-reseau', ['identifiant' => $id(14)]), 422, 'ERR_UNKNOWN_MODE', 'mode');
    Http::assertNothingSent();
});

it('ne consomme pas l\'identifiant quand la demande est refusée : on peut le réutiliser corrigée', function () {
    Http::fake();

    lancerRechercheReseau($this, ['texte' => '?!'])->assertStatus(422);

    lancerRechercheReseau($this)->assertStatus(202);
});

it('répond 409 ERR_ALREADY_EXISTS quand l\'identifiant sert déjà, sans toucher à la première recherche', function () {
    Queue::fake();
    pairEnLigne('voisine');
    lancerRechercheReseau($this)->assertStatus(202);

    $r = lancerRechercheReseau($this, ['texte' => 'autre chose']);

    attendErreurContrat($r, 409, 'ERR_ALREADY_EXISTS', 'identifiant');
    Queue::assertPushed(InterrogerPairPourRecherche::class, 1);
    expect($this->getJson('/api/v1/app/recherches-reseau/'.RECHERCHE_ID)->json('texte_normalise'))->toBe('ou est passe l ete');
});

it('accepte le mode page : 3 200 caractères exactement, sans toucher aux espaces doubles', function () {
    Http::fake();
    $page = substr(str_repeat('abc  d ', 500), 0, 3200);

    $r = lancerRechercheReseau($this, ['mode' => 'page', 'texte' => $page]);

    $r->assertStatus(202);
    expect($r->json('texte_normalise'))->toBe($page)->and($r->json('mode'))->toBe('page');
});

/* --------------------------------------------------------- état : 404 */

it('répond 404 pour une recherche inconnue ou un identifiant mal écrit, de la même façon', function (string $identifiant) {
    attendErreurContrat($this->getJson('/api/v1/app/recherches-reseau/'.$identifiant), 404, 'ERR_NOT_FOUND', 'identifiant');
})->with(['3f2c8a1e-6b7d-4c1a-9e2f-000000000000', 'pas-un-uuid', '12345']);

/* ------------------------------------------------- historique d'un membre */

it('enregistre la recherche dans l\'historique d\'un membre, avec la portée « reseau »', function () {
    Http::fake();
    $membre = User::factory()->create();
    $jeton = $membre->createToken('test')->plainTextToken;

    lancerRechercheReseau($this, [], ['Authorization' => 'Bearer '.$jeton])->assertStatus(202);

    $recherche = Recherche::sole();
    expect($recherche->utilisateur_id)->toBe($membre->id)->and($recherche->texte)->toBe('ou est passe l ete')
        ->and($recherche->mode->value)->toBe('exact')->and($recherche->portee->value)->toBe('reseau');
});

it('ne garde rien d\'un visiteur, ni d\'un jeton faux, ni d\'une recherche refusée, ni du mode page', function () {
    Http::fake();
    $membre = User::factory()->create();
    $jeton = $membre->createToken('test')->plainTextToken;

    lancerRechercheReseau($this)->assertStatus(202); // visiteur
    lancerRechercheReseau($this, ['identifiant' => '3f2c8a1e-6b7d-4c1a-9e2f-0a1b2c3d4e61'], ['Authorization' => 'Bearer faux'])->assertStatus(202);
    lancerRechercheReseau($this, ['identifiant' => '3f2c8a1e-6b7d-4c1a-9e2f-0a1b2c3d4e62', 'texte' => '?!'], ['Authorization' => 'Bearer '.$jeton])->assertStatus(422);
    lancerRechercheReseau($this, ['identifiant' => '3f2c8a1e-6b7d-4c1a-9e2f-0a1b2c3d4e63', 'mode' => 'page', 'texte' => str_repeat('a', 3200)], ['Authorization' => 'Bearer '.$jeton])->assertStatus(202);

    expect(Recherche::count())->toBe(0);
});
