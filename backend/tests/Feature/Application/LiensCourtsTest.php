<?php

use App\Models\Adresse;
use App\Models\Bibliotheque;
use App\Models\Collection;
use App\Models\LienCourt;
use App\Models\Reglage;
use App\Models\Signalement;
use App\Models\TexteBanni;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/Appel.php';

/* POST /app/liens-courts (V11), GET /app/liens-courts/{slug} (V12), GET et DELETE /app/moi/liens-courts. */

beforeEach(function () {
    $this->locale = bibliothequeLocale();
    $this->ada = User::factory()->create(['pseudo' => 'ada']);
});

/** Une demande de lien vers une adresse. */
function versAdresse(string $adresse = PAGE_EXEMPLE, array $en_plus = [], ?string $bibliotheque = null): array
{
    return ['cible' => array_filter(['type' => 'adresse', 'adresse' => $adresse, 'bibliotheque' => $bibliotheque])] + $en_plus;
}

// ---- Créer ------------------------------------------------------------------------------------------------------

it('crée un lien vers une page pour un membre : 201, sans expiration par défaut, schéma LienCourt exact', function () {
    $r = appel('POST', '/liens-courts', versAdresse(), $this->ada);

    $r->assertCreated();
    expect(array_keys($r->json()))->toBe(['slug', 'cible', 'passage', 'visites', 'expire_le', 'cree_le']);
    expect($r->json('slug'))->toMatch('/^[A-Za-z0-9_-]{4,32}$/');
    expect($r->json('cible'))->toBe(['type' => 'adresse', 'bibliotheque' => 'exemple', 'adresse' => PAGE_EXEMPLE, 'niveau' => 'page']);
    expect($r->json('passage'))->toBeNull()->and($r->json('visites'))->toBe(0)->and($r->json('expire_le'))->toBeNull();

    $lien = LienCourt::query()->sole();
    expect($lien->utilisateur_id)->toBe($this->ada->id);
});

it('un visiteur peut créer un lien : sans date, il expire à la durée maximale (30 jours par défaut)', function () {
    $r = appel('POST', '/liens-courts', versAdresse());

    $r->assertCreated();
    $expire = CarbonImmutable::parse($r->json('expire_le'));
    expect($expire->between(now()->addDays(30)->subMinute(), now()->addDays(30)->addMinute()))->toBeTrue();
    expect(LienCourt::query()->sole()->utilisateur_id)->toBeNull();
});

it('la durée maximale d\'un lien de visiteur vient du réglage de l\'administrateur', function () {
    Reglage::query()->create(['cle' => 'duree_lien_visiteur_jours', 'valeur' => '7']);

    $r = appel('POST', '/liens-courts', versAdresse());
    expect(CarbonImmutable::parse($r->json('expire_le'))->between(now()->addDays(7)->subMinute(), now()->addDays(7)->addMinute()))->toBeTrue();

    // 8 jours : au-delà, refusé.
    attendErreurContrat(
        appel('POST', '/liens-courts', versAdresse(en_plus: ['expire_le' => now()->addDays(8)->toIso8601String()])),
        422, 'ERR_INVALID_PARAMETER', 'expire_le',
    );
});

it('un visiteur choisit une date plus courte ; un membre peut aller au-delà, ou ne rien mettre', function () {
    $court = now()->addDays(2)->startOfSecond();

    $visiteur = appel('POST', '/liens-courts', versAdresse(en_plus: ['expire_le' => $court->toIso8601String()]))->assertCreated();
    expect(CarbonImmutable::parse($visiteur->json('expire_le'))->equalTo($court))->toBeTrue();

    appel('POST', '/liens-courts', versAdresse(en_plus: ['expire_le' => now()->addYear()->toIso8601String()]), $this->ada)->assertCreated();
    appel('POST', '/liens-courts', versAdresse(en_plus: ['expire_le' => null]), $this->ada)->assertCreated()->assertJsonPath('expire_le', null);
});

it('refuse une date d\'expiration passée ou mal écrite', function (mixed $date) {
    $date = $date instanceof Closure ? $date() : $date;
    $r = appel('POST', '/liens-courts', versAdresse(en_plus: ['expire_le' => $date]), $this->ada);

    attendErreurContrat($r, 422, 'ERR_INVALID_PARAMETER', 'expire_le');
    expect(LienCourt::query()->count())->toBe(0);
})->with(['hier' => fn () => now()->subDay()->toIso8601String(), 'texte' => 'demain', 'sans fuseau' => '2099-01-01T10:00:00', 'nombre' => 12]);

it('retient un passage qui figure dans la page, repris tel quel', function () {
    $r = appel('POST', '/liens-courts', versAdresse(en_plus: ['passage' => PASSAGE_EXEMPLE]), $this->ada);

    $r->assertCreated()->assertJsonPath('passage', PASSAGE_EXEMPLE);
});

it('refuse un passage qui ne figure pas dans la page (on ne devine pas) : ERR_INVALID_PARAMETER sur « passage »', function (string $passage) {
    attendErreurContrat(appel('POST', '/liens-courts', versAdresse(en_plus: ['passage' => $passage]), $this->ada), 422, 'ERR_INVALID_PARAMETER', 'passage');
})->with(['absent' => ['zzzzzzzzzz'], 'espaces en trop' => ['olonj  xxqvbefublhf'], 'majuscules' => ['OLONJ']]);

it('un passage ne va qu\'avec une page : ni un livre, ni une collection', function () {
    $collection = Collection::factory()->publique()->create();

    attendErreurContrat(appel('POST', '/liens-courts', versAdresse(LIVRE_EXEMPLE, ['passage' => 'a']), $this->ada), 422, 'ERR_INVALID_PARAMETER', 'passage');
    attendErreurContrat(appel('POST', '/liens-courts', [
        'cible' => ['type' => 'collection', 'id' => (string) $collection->id], 'passage' => 'a',
    ], $this->ada), 422, 'ERR_INVALID_PARAMETER', 'passage');
});

it('crée un lien vers un livre entier : niveau « livre », sa ligne d\'adresse n\'a pas de page', function () {
    $r = appel('POST', '/liens-courts', versAdresse(LIVRE_EXEMPLE), $this->ada);

    $r->assertCreated()->assertJsonPath('cible', ['type' => 'adresse', 'bibliotheque' => 'exemple', 'adresse' => LIVRE_EXEMPLE, 'niveau' => 'livre']);
    expect(Adresse::query()->sole()->page)->toBeNull();
});

it('n\'enregistre une adresse qu\'une fois, quel que soit le nombre de liens (règle 2)', function () {
    appel('POST', '/liens-courts', versAdresse(), $this->ada)->assertCreated();
    appel('POST', '/liens-courts', versAdresse());
    appel('POST', '/liens-courts', versAdresse(PAGE_EXEMPLE, ['passage' => PASSAGE_EXEMPLE]), $this->ada)->assertCreated();

    expect(Adresse::query()->count())->toBe(1)->and(LienCourt::query()->count())->toBe(3);
    expect(LienCourt::query()->pluck('slug')->unique())->toHaveCount(3);
});

it('crée un lien vers une collection publique, ou vers une collection du membre, mais pas celle d\'un autre', function () {
    $publique = Collection::factory()->publique()->create();
    $sienne = Collection::factory()->create(['utilisateur_id' => $this->ada->id]);
    $privee = Collection::factory()->create();
    $masquee = Collection::factory()->publique()->create(['masque_le' => now()]);

    $r = appel('POST', '/liens-courts', ['cible' => ['type' => 'collection', 'id' => (string) $publique->id]]);
    $r->assertCreated()->assertJsonPath('cible', ['type' => 'collection', 'id' => (string) $publique->id]);

    appel('POST', '/liens-courts', ['cible' => ['type' => 'collection', 'id' => $sienne->id]], $this->ada)->assertCreated();
    appel('POST', '/liens-courts', ['cible' => ['type' => 'collection', 'id' => (string) $sienne->id]])->assertNotFound();
    appel('POST', '/liens-courts', ['cible' => ['type' => 'collection', 'id' => (string) $privee->id]], $this->ada)->assertNotFound();
    appel('POST', '/liens-courts', ['cible' => ['type' => 'collection', 'id' => (string) $masquee->id]], $this->ada)->assertNotFound();
    appel('POST', '/liens-courts', ['cible' => ['type' => 'collection', 'id' => '12abc']], $this->ada)->assertNotFound();
});

it('refuse une adresse mal écrite ou inexistante avec les erreurs du moteur, sans rien enregistrer', function () {
    attendErreurContrat(appel('POST', '/liens-courts', versAdresse('0-w1-s1-v33:1')), 404, 'ERR_OUT_OF_BOUNDS', 'volume');
    appel('POST', '/liens-courts', versAdresse('0-W1-s1-v1:1'))->assertStatus(422)->assertJsonPath('erreurs.0.code', 'ERR_INVALID_ADDRESS_SYNTAX');
    // Une adresse ne se corrige jamais, pas même d'un espace en bord.
    appel('POST', '/liens-courts', versAdresse(' '.PAGE_EXEMPLE))->assertStatus(422);

    expect(LienCourt::query()->count())->toBe(0)->and(Adresse::query()->count())->toBe(0);
});

it('une bibliothèque inconnue donne 404 ERR_UNKNOWN_LIBRARY', function () {
    attendErreurContrat(appel('POST', '/liens-courts', versAdresse(bibliotheque: 'inconnue')), 404, 'ERR_UNKNOWN_LIBRARY', 'bibliotheque');
});

it('crée un lien vers la page d\'un pair sans passage, sans l\'appeler', function () {
    Http::fake();
    $pair = Bibliotheque::factory()->enLigne()->create(['identifiant' => 'voisin']);

    appel('POST', '/liens-courts', versAdresse(bibliotheque: 'voisin'))->assertCreated()->assertJsonPath('cible.bibliotheque', 'voisin');

    expect(Adresse::query()->sole()->bibliotheque_id)->toBe($pair->id);
    Http::assertNothingSent();
});

it('vérifie le passage d\'une page de pair chez lui : 502 s\'il ne répond pas', function () {
    Bibliotheque::factory()->enLigne()->create(['identifiant' => 'voisin']);
    Http::fake(['*' => fn () => throw new ConnectionException('timeout')]);

    attendErreurContrat(
        appel('POST', '/liens-courts', versAdresse(en_plus: ['passage' => 'a'], bibliotheque: 'voisin')),
        502, 'ERR_PEER_UNREACHABLE', 'bibliotheque',
    );
    expect(LienCourt::query()->count())->toBe(0);
});

it('refuse une demande mal formée : une erreur ERR_INVALID_PARAMETER par champ', function (array $corps, array $champs) {
    $r = appel('POST', '/liens-courts', $corps);

    $r->assertStatus(422);
    expect(array_column($r->json('erreurs'), 'champ'))->toBe($champs);
})->with([
    'corps vide' => [[], ['cible']],
    'type inconnu' => [['cible' => ['type' => 'livre', 'adresse' => 'x']], ['cible']],
    'adresse absente' => [['cible' => ['type' => 'adresse']], ['cible']],
    'adresse vide' => [['cible' => ['type' => 'adresse', 'adresse' => '']], ['cible']],
    'id de collection absent' => [['cible' => ['type' => 'collection']], ['cible']],
    'passage trop long' => [['cible' => ['type' => 'adresse', 'adresse' => PAGE_EXEMPLE], 'passage' => str_repeat('a', 3201)], ['passage']],
    'cible et expire_le' => [['cible' => 'x', 'expire_le' => 'bientôt'], ['cible', 'expire_le']],
]);

// ---- Textes bannis ----------------------------------------------------------------------------------------------

it('à la création, marque l\'adresse si la page contient un texte banni appliqué : le lien est créé mais ne s\'ouvrira pas', function () {
    TexteBanni::factory()->applique()->create(['texte' => PASSAGE_EXEMPLE]);

    $r = appel('POST', '/liens-courts', versAdresse(), $this->ada);

    $r->assertCreated(); // on ne dit pas à celui qui publie qu'il est tombé sur un texte banni
    expect(Adresse::query()->sole()->texte_banni_id)->not->toBeNull();
    attendErreurContrat(appel('GET', '/liens-courts/'.$r->json('slug')), 404, 'ERR_NOT_FOUND', 'slug');
});

it('ignore un texte banni seulement proposé ou rejeté', function () {
    TexteBanni::factory()->create(['texte' => PASSAGE_EXEMPLE]); // statut « propose »

    appel('POST', '/liens-courts', versAdresse(), $this->ada)->assertCreated();

    expect(Adresse::query()->sole()->texte_banni_id)->toBeNull();
});

it('pour un livre entier, lit ses 410 pages et marque l\'adresse du LIVRE', function () {
    TexteBanni::factory()->applique()->create(['texte' => PASSAGE_EXEMPLE]); // dans la page 1 du livre

    appel('POST', '/liens-courts', versAdresse(LIVRE_EXEMPLE), $this->ada)->assertCreated();

    $livre = Adresse::query()->sole();
    expect($livre->page)->toBeNull()->and($livre->texte_banni_id)->not->toBeNull();
});

// ---- Ouvrir -----------------------------------------------------------------------------------------------------

it('ouvre un lien vers une page : 200, schéma LienCourtOuvert exact, une visite de plus', function () {
    $lien = appel('POST', '/liens-courts', versAdresse(en_plus: ['passage' => PASSAGE_EXEMPLE]), $this->ada)->json();

    $r = appel('GET', '/liens-courts/'.$lien['slug']);

    $r->assertOk();
    expect(array_keys($r->json()))->toBe(['slug', 'cible', 'passage', 'expire_le']);
    expect($r->json('cible'))->toBe([
        'type' => 'adresse', 'bibliotheque' => 'exemple', 'adresse' => PAGE_EXEMPLE, 'niveau' => 'page', 'adresse_a_ouvrir' => PAGE_EXEMPLE,
    ]);
    expect($r->json('passage'))->toBe(PASSAGE_EXEMPLE);

    appel('GET', '/liens-courts/'.$lien['slug'], membre: User::factory()->create())->assertOk();
    expect(LienCourt::query()->sole()->visites)->toBe(2);
});

it('un lien vers un livre s\'ouvre sur sa page 1', function () {
    $slug = appel('POST', '/liens-courts', versAdresse(LIVRE_EXEMPLE))->json('slug');

    appel('GET', '/liens-courts/'.$slug)->assertOk()->assertJsonPath('cible', [
        'type' => 'adresse', 'bibliotheque' => 'exemple', 'adresse' => LIVRE_EXEMPLE, 'niveau' => 'livre', 'adresse_a_ouvrir' => LIVRE_EXEMPLE.':1',
    ]);
});

it('un lien vers une collection renvoie la collection entière, pages filtrées comme GET /collections/{id}', function () {
    $collection = Collection::factory()->publique()->create(['nom' => 'Mes pages']);
    $banni = TexteBanni::factory()->applique()->create();
    $ok = Adresse::factory()->create(['bibliotheque_id' => $this->locale->id]);
    $retiree = Adresse::factory()->create(['bibliotheque_id' => $this->locale->id, 'texte_banni_id' => $banni->id]);
    $collection->pages()->create(['position' => 1, 'adresse_id' => $ok->id]);
    $collection->pages()->create(['position' => 2, 'adresse_id' => $retiree->id]);
    $slug = LienCourt::factory()->versCollection()->create(['collection_id' => $collection->id])->slug;

    $r = appel('GET', '/liens-courts/'.$slug);

    $r->assertOk();
    expect($r->json('cible.type'))->toBe('collection');
    expect($r->json('cible.collection'))->toBe($this->getJson('/api/v1/collections/'.$collection->id)->json());
    expect($r->json('cible.collection.nombre_pages'))->toBe(1);
});

it('un lien expiré répond 410 ERR_SHORT_LINK_EXPIRED et ne compte pas de visite', function () {
    $lien = LienCourt::factory()->visiteur()->create(['slug' => 'perimeAb', 'expire_le' => now()->subMinute()]);

    attendErreurContrat(appel('GET', '/liens-courts/perimeAb'), 410, 'ERR_SHORT_LINK_EXPIRED', 'slug');
    expect($lien->refresh()->visites)->toBe(0);
});

it('inconnu, mal écrit, masqué, ou dont la cible n\'est plus montrée : toujours la même 404', function (string $slug) {
    attendErreurContrat(appel('GET', '/liens-courts/'.$slug), 404, 'ERR_NOT_FOUND', 'slug');
})->with(['inconnu' => ['nexistePas'], 'trop court' => ['abc'], 'caractères interdits' => ['ab%20cd.ef']]);

it('404 si le lien est masqué, ou si sa collection est masquée ou devenue privée', function () {
    LienCourt::factory()->create(['slug' => 'masqueAb', 'masque_le' => now()]);
    LienCourt::factory()->versCollection()->create(['slug' => 'collMasqu', 'collection_id' => Collection::factory()->publique()->create(['masque_le' => now()])->id]);
    LienCourt::factory()->versCollection()->create(['slug' => 'collPriv1', 'collection_id' => Collection::factory()->create()->id]);

    foreach (['masqueAb', 'collMasqu', 'collPriv1'] as $slug) {
        attendErreurContrat(appel('GET', '/liens-courts/'.$slug), 404, 'ERR_NOT_FOUND', 'slug');
    }
});

it('est limité à 120 lectures par minute', function () {
    for ($i = 0; $i < 120; $i++) {
        appel('GET', '/liens-courts/nexistePas')->assertNotFound();
    }

    attendErreurContrat(appel('GET', '/liens-courts/nexistePas'), 429, 'ERR_TOO_MANY_REQUESTS', null);
});

// ---- Mes liens --------------------------------------------------------------------------------------------------

it('liste mes liens, du plus récent au plus ancien, avec leurs visites ; ceux des autres n\'y sont pas', function () {
    $ancien = LienCourt::factory()->create(['utilisateur_id' => $this->ada->id, 'slug' => 'ancienAb', 'visites' => 5, 'cree_le' => now()->subDay()]);
    $recent = LienCourt::factory()->create(['utilisateur_id' => $this->ada->id, 'slug' => 'recentAb', 'cree_le' => now()]);
    LienCourt::factory()->create(['slug' => 'autreAbc']);
    LienCourt::factory()->visiteur()->create(['slug' => 'visitAbc']);

    $r = appel('GET', '/moi/liens-courts', membre: $this->ada);

    $r->assertOk();
    expect(array_keys($r->json()))->toBe(['lot', 'taille', 'total', 'elements']);
    expect($r->json('total'))->toBe(2)->and($r->json('lot'))->toBe(1)->and($r->json('taille'))->toBe(20);
    expect(array_column($r->json('elements'), 'slug'))->toBe(['recentAb', 'ancienAb']);
    expect($r->json('elements.1.visites'))->toBe(5);
});

it('découpe la liste en lots et refuse un lot ou une taille mal écrits', function () {
    LienCourt::factory()->count(3)->create(['utilisateur_id' => $this->ada->id]);

    $r = appel('GET', '/moi/liens-courts?taille=2&lot=2', membre: $this->ada);
    expect($r->json('elements'))->toHaveCount(1)->and($r->json('total'))->toBe(3)->and($r->json('lot'))->toBe(2);

    attendErreurContrat(appel('GET', '/moi/liens-courts?taille=51', membre: $this->ada), 422, 'ERR_INVALID_PARAMETER', 'taille');
    attendErreurContrat(appel('GET', '/moi/liens-courts?lot=0', membre: $this->ada), 422, 'ERR_INVALID_PARAMETER', 'lot');
});

it('la liste et la suppression demandent un membre connecté : 401 sinon', function () {
    attendErreurContrat(appel('GET', '/moi/liens-courts'), 401, 'ERR_UNAUTHENTICATED', null);
    attendErreurContrat(appel('DELETE', '/moi/liens-courts/abcdefgh'), 401, 'ERR_UNAUTHENTICATED', null);
});

it('supprime mon lien : 204, et ses signalements partent avec lui (règle 13)', function () {
    $lien = LienCourt::factory()->create(['utilisateur_id' => $this->ada->id, 'slug' => 'monLienAb']);
    Signalement::factory()->create(['annotation_id' => null, 'lien_court_id' => $lien->id]);

    appel('DELETE', '/moi/liens-courts/monLienAb', membre: $this->ada)->assertNoContent();

    expect(LienCourt::query()->count())->toBe(0)->and(Signalement::query()->count())->toBe(0);
});

it('le lien d\'un autre, ou d\'un visiteur, ou un lien inconnu : 404 et rien n\'est supprimé', function () {
    LienCourt::factory()->create(['slug' => 'autreAbc']);
    LienCourt::factory()->visiteur()->create(['slug' => 'visitAbc']);

    foreach (['autreAbc', 'visitAbc', 'inconnuAb'] as $slug) {
        attendErreurContrat(appel('DELETE', '/moi/liens-courts/'.$slug, membre: $this->ada), 404, 'ERR_NOT_FOUND', 'slug');
    }
    expect(LienCourt::query()->count())->toBe(2);
});

it('est limité à 20 publications par minute', function () {
    for ($i = 0; $i < 20; $i++) {
        appel('POST', '/liens-courts', [])->assertStatus(422);
    }

    attendErreurContrat(appel('POST', '/liens-courts', []), 429, 'ERR_TOO_MANY_REQUESTS', null);
});
