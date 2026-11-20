<?php

use App\Enums\EtatBibliotheque;
use App\Models\ActiviteJour;
use App\Models\Annotation;
use App\Models\Bibliotheque;
use App\Models\MarquePage;
use App\Models\TexteBanni;
use App\Models\User;
use App\Models\Vote;
use Babel\Moteur\Moteur;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/Appel.php';

/* GET /app/pages/{adresse} (V1, V15, V16) : le texte et tout ce qui l'entoure. */

beforeEach(function () {
    $this->locale = bibliothequeLocale();
});

/** Un pair « voisin » avec son URL, comme l'annuaire la donne. */
function voisin(array $attributs = []): Bibliotheque
{
    return Bibliotheque::factory()->enLigne()->create($attributs + ['identifiant' => 'voisin', 'url_actuelle' => 'https://voisin.example.test']);
}

it('lit une page sans compte : texte du moteur, score 0, rien autour (schéma PageLue exact)', function () {
    $r = appel('GET', '/pages/'.PAGE_EXEMPLE);

    $r->assertOk();
    expect(array_keys($r->json()))->toBe(['bibliotheque', 'adresse', 'adresse_livre', 'texte', 'score', 'mon_vote', 'marque_page', 'annotations']);
    expect($r->json('bibliotheque'))->toBe('exemple');
    expect($r->json('adresse'))->toBe(PAGE_EXEMPLE);
    expect($r->json('adresse_livre'))->toBe(LIVRE_EXEMPLE);
    expect($r->json('texte'))->toBe(app(Moteur::class)->lire(PAGE_EXEMPLE))->toHaveLength(3200);
    expect($r->json('score'))->toBe(0);
    expect($r->json('mon_vote'))->toBeNull();
    expect($r->json('marque_page'))->toBeNull();
    expect($r->json('annotations'))->toBe([]);
});

it('compte la page lue dans les statistiques du jour, sans rien garder d\'autre', function () {
    appel('GET', '/pages/'.PAGE_EXEMPLE)->assertOk();
    appel('GET', '/pages/'.PAGE_EXEMPLE)->assertOk();

    expect(ActiviteJour::query()->count())->toBe(1);
    expect(ActiviteJour::query()->first()->pages_lues)->toBe(2);
});

it('montre à un membre son vote, son marque-page, le score et ses annotations privées', function () {
    $ada = User::factory()->create(['pseudo' => 'ada']);
    $autre = User::factory()->create(['pseudo' => 'borges']);
    $adresse = adresseEnregistree($this->locale);
    Vote::query()->create(['utilisateur_id' => $ada->id, 'adresse_id' => $adresse->id, 'valeur' => 1]);
    Vote::query()->create(['utilisateur_id' => $autre->id, 'adresse_id' => $adresse->id, 'valeur' => 1]);
    $marque = MarquePage::factory()->create(['utilisateur_id' => $ada->id, 'adresse_id' => $adresse->id, 'titre' => 'Le tout début']);
    $privee = Annotation::factory()->create(['utilisateur_id' => $ada->id, 'adresse_id' => $adresse->id, 'est_publique' => false, 'contenu' => 'pour moi']);

    $r = appel('GET', '/pages/'.PAGE_EXEMPLE, membre: $ada);

    $r->assertOk();
    expect($r->json('score'))->toBe(3); // 2 votes +1 et 1 marque-page
    expect($r->json('mon_vote'))->toBe(1);
    expect($r->json('marque_page'))->toBe(['id' => (string) $marque->id, 'titre' => 'Le tout début']);
    expect($r->json('annotations'))->toHaveCount(1);
    expect($r->json('annotations.0'))->toMatchArray([
        'id' => (string) $privee->id, 'bibliotheque' => 'exemple', 'adresse' => PAGE_EXEMPLE, 'contenu' => 'pour moi',
        'est_publique' => false, 'masquee' => false, 'a_convertir' => false, 'est_a_moi' => true,
    ]);
    expect($r->json('annotations.0.auteur'))->toBe(['pseudo' => 'ada']);
    expect(array_keys($r->json('annotations.0')))->toBe(
        ['id', 'bibliotheque', 'adresse', 'contenu', 'est_publique', 'masquee', 'a_convertir', 'auteur', 'est_a_moi', 'cree_le', 'maj_le'],
    );
});

it('ne montre à un visiteur ni vote, ni marque-page, ni annotation privée ou masquée, mais le score est public', function () {
    $ada = User::factory()->create();
    $adresse = adresseEnregistree($this->locale);
    Vote::query()->create(['utilisateur_id' => $ada->id, 'adresse_id' => $adresse->id, 'valeur' => -1]);
    MarquePage::factory()->create(['utilisateur_id' => $ada->id, 'adresse_id' => $adresse->id]);
    Annotation::factory()->create(['adresse_id' => $adresse->id, 'est_publique' => false]);
    Annotation::factory()->create(['adresse_id' => $adresse->id, 'est_publique' => true, 'masque_le' => now()]);
    $publique = Annotation::factory()->create(['adresse_id' => $adresse->id, 'est_publique' => true]);

    $r = appel('GET', '/pages/'.PAGE_EXEMPLE);

    expect($r->json('score'))->toBe(0); // -1 + 1 marque-page
    expect($r->json('mon_vote'))->toBeNull()->and($r->json('marque_page'))->toBeNull();
    expect(array_column($r->json('annotations'), 'id'))->toBe([(string) $publique->id]);
    expect($r->json('annotations.0.est_a_moi'))->toBeFalse();
});

it('trie les annotations de la plus récente à la plus ancienne', function () {
    $adresse = adresseEnregistree($this->locale);
    $ancienne = Annotation::factory()->publique()->create(['adresse_id' => $adresse->id, 'cree_le' => now()->subDays(2)]);
    $recente = Annotation::factory()->publique()->create(['adresse_id' => $adresse->id, 'cree_le' => now()->subDay()]);

    expect(array_column(appel('GET', '/pages/'.PAGE_EXEMPLE)->json('annotations'), 'id'))
        ->toBe([(string) $recente->id, (string) $ancienne->id]);
});

it('montre à son auteur une annotation masquée par un administrateur, marquée « masquee », et à lui seul', function () {
    $ada = User::factory()->create();
    $adresse = adresseEnregistree($this->locale);
    $masquee = Annotation::factory()->publique()->create(['utilisateur_id' => $ada->id, 'adresse_id' => $adresse->id, 'masque_le' => now()]);

    expect(appel('GET', '/pages/'.PAGE_EXEMPLE, membre: $ada)->json('annotations.0'))
        ->toMatchArray(['id' => (string) $masquee->id, 'masquee' => true, 'est_a_moi' => true]);
    expect(appel('GET', '/pages/'.PAGE_EXEMPLE, membre: User::factory()->create())->json('annotations'))->toBe([]);
});

it('une page qui contient un texte banni reste lisible, mais ses annotations publiques ne sont plus montrées aux autres (règle 18)', function () {
    $ada = User::factory()->create();
    $adresse = adresseEnregistree($this->locale);
    $banni = TexteBanni::factory()->applique()->create(['texte' => PASSAGE_EXEMPLE]);
    $adresse->forceFill(['texte_banni_id' => $banni->id])->save();
    $sienne = Annotation::factory()->publique()->create(['utilisateur_id' => $ada->id, 'adresse_id' => $adresse->id]);
    Annotation::factory()->publique()->create(['adresse_id' => $adresse->id]);

    $visiteur = appel('GET', '/pages/'.PAGE_EXEMPLE);
    $visiteur->assertOk();
    expect($visiteur->json('texte'))->toHaveLength(3200)->and($visiteur->json('annotations'))->toBe([]);

    // L'auteur voit la sienne, signalée comme masquée ; il ne voit pas celle des autres.
    $membre = appel('GET', '/pages/'.PAGE_EXEMPLE, membre: $ada);
    expect(array_column($membre->json('annotations'), 'id'))->toBe([(string) $sienne->id]);
    expect($membre->json('annotations.0.masquee'))->toBeTrue();
});

it('un faux jeton, ou le jeton d\'un compte suspendu, vaut un visiteur : la lecture ne casse pas', function () {
    $adresse = adresseEnregistree($this->locale);
    $suspendu = User::factory()->suspendu()->create();
    Annotation::factory()->create(['utilisateur_id' => $suspendu->id, 'adresse_id' => $adresse->id, 'est_publique' => false]);

    appel('GET', '/pages/'.PAGE_EXEMPLE, entetes: ['Authorization' => 'Bearer faux'])->assertOk()->assertJsonPath('annotations', []);
    appel('GET', '/pages/'.PAGE_EXEMPLE, membre: $suspendu)->assertOk()->assertJsonPath('annotations', []);
});

// ---- Les erreurs --------------------------------------------------------------------------------------------

it('refuse une adresse mal écrite avec la liste d\'erreurs du moteur : 422', function () {
    $r = appel('GET', '/pages/0-W1-s1-v1:1'); // « W » en majuscule : on ne corrige pas

    $r->assertStatus(422);
    expect($r->json('erreurs.0.code'))->toBe('ERR_INVALID_ADDRESS_SYNTAX');
});

it('répond 404 quand l\'adresse est bien écrite mais n\'existe pas (volume 33)', function () {
    $r = appel('GET', '/pages/0-w1-s1-v33:1');

    attendErreurContrat($r, 404, 'ERR_OUT_OF_BOUNDS', 'volume');
});

it('une adresse de livre n\'est pas une page : 422', function () {
    appel('GET', '/pages/'.LIVRE_EXEMPLE)->assertStatus(422);
});

it('une bibliothèque inconnue donne 404 ERR_UNKNOWN_LIBRARY', function () {
    attendErreurContrat(appel('GET', '/pages/'.PAGE_EXEMPLE.'?bibliotheque=inconnue'), 404, 'ERR_UNKNOWN_LIBRARY', 'bibliotheque');
    attendErreurContrat(appel('GET', '/pages/'.PAGE_EXEMPLE.'?bibliotheque[]=x'), 404, 'ERR_UNKNOWN_LIBRARY', 'bibliotheque');
});

it('« bibliotheque » égale à notre identifiant vaut la bibliothèque locale (sans appel réseau)', function () {
    Http::fake();

    appel('GET', '/pages/'.PAGE_EXEMPLE.'?bibliotheque=exemple')->assertOk()->assertJsonPath('bibliotheque', 'exemple');

    Http::assertNothingSent();
});

// ---- Lire chez un pair (V15, V16) ---------------------------------------------------------------------------

it('lit la page chez le pair, et y ajoute NOS votes et annotations (compteur d\'échecs remis à zéro)', function () {
    $pair = voisin(['echecs_consecutifs' => 2, 'etat' => EtatBibliotheque::Inconnu]);
    $texte = app(Moteur::class)->lire(PAGE_EXEMPLE);
    Http::fake(['voisin.example.test/*' => Http::response(['bibliotheque' => 'voisin', 'adresse' => PAGE_EXEMPLE, 'texte' => $texte])]);
    $adresse = adresseEnregistree($pair);
    Annotation::factory()->publique()->create(['adresse_id' => $adresse->id, 'contenu' => 'chez le voisin']);
    // La même adresse chez nous n'a rien à voir : une autre ligne, d'autres annotations.
    Annotation::factory()->publique()->create(['adresse_id' => adresseEnregistree($this->locale)->id]);

    $r = appel('GET', '/pages/'.PAGE_EXEMPLE.'?bibliotheque=voisin');

    $r->assertOk();
    expect($r->json('bibliotheque'))->toBe('voisin')->and($r->json('texte'))->toBe($texte);
    expect(array_column($r->json('annotations'), 'contenu'))->toBe(['chez le voisin']);
    expect($r->json('annotations.0.bibliotheque'))->toBe('voisin');

    Http::assertSent(fn ($requete) => $requete->url() === 'https://voisin.example.test/api/v1/pages/'.PAGE_EXEMPLE);
    $pair->refresh();
    expect($pair->echecs_consecutifs)->toBe(0)->and($pair->etat)->toBe(EtatBibliotheque::EnLigne)->and($pair->dernier_contact_le)->not->toBeNull();
    // On ne compte que les pages lues chez nous.
    expect(ActiviteJour::query()->count())->toBe(0);
});

it('contrôle l\'adresse AVANT tout appel réseau', function () {
    voisin();
    Http::fake();

    appel('GET', '/pages/0-w1-s1-v33:1?bibliotheque=voisin')->assertStatus(404);
    appel('GET', '/pages/0-W1-s1-v1:1?bibliotheque=voisin')->assertStatus(422);

    Http::assertNothingSent();
});

it('un pair qui répond 429 donne 503 ERR_PEER_BUSY avec son Retry-After, sans compter d\'échec', function () {
    $pair = voisin(['echecs_consecutifs' => 1]);
    Http::fake(['voisin.example.test/*' => Http::response('', 429, ['Retry-After' => '17'])]);

    $r = appel('GET', '/pages/'.PAGE_EXEMPLE.'?bibliotheque=voisin');

    attendErreurContrat($r, 503, 'ERR_PEER_BUSY', 'bibliotheque');
    expect($r->headers->get('Retry-After'))->toBe('17');
    expect($pair->refresh()->echecs_consecutifs)->toBe(1);
});

it('un pair injoignable donne 502 ERR_PEER_UNREACHABLE et son compteur d\'échecs augmente ; hors ligne au 3e échec', function () {
    $pair = voisin();
    Http::fake(['voisin.example.test/*' => fn () => throw new ConnectionException('timeout')]);

    attendErreurContrat(appel('GET', '/pages/'.PAGE_EXEMPLE.'?bibliotheque=voisin'), 502, 'ERR_PEER_UNREACHABLE', 'bibliotheque');
    expect($pair->refresh()->echecs_consecutifs)->toBe(1)->and($pair->etat)->toBe(EtatBibliotheque::EnLigne);

    appel('GET', '/pages/'.PAGE_EXEMPLE.'?bibliotheque=voisin');
    appel('GET', '/pages/'.PAGE_EXEMPLE.'?bibliotheque=voisin');
    expect($pair->refresh()->echecs_consecutifs)->toBe(3)->and($pair->etat)->toBe(EtatBibliotheque::HorsLigne);
});

it('une réponse hors contrat du pair (mauvais texte, mauvaise adresse, mauvais identifiant, 500) est un échec : 502', function (mixed $reponse) {
    $pair = voisin();
    Http::fake(['voisin.example.test/*' => $reponse]);

    attendErreurContrat(appel('GET', '/pages/'.PAGE_EXEMPLE.'?bibliotheque=voisin'), 502, 'ERR_PEER_UNREACHABLE', 'bibliotheque');
    expect($pair->refresh()->echecs_consecutifs)->toBe(1);
})->with([
    'texte trop court' => fn () => Http::response(['bibliotheque' => 'voisin', 'adresse' => PAGE_EXEMPLE, 'texte' => 'abc']),
    'hors alphabet' => fn () => Http::response(['bibliotheque' => 'voisin', 'adresse' => PAGE_EXEMPLE, 'texte' => str_repeat('A', 3200)]),
    'autre adresse' => fn () => Http::response(['bibliotheque' => 'voisin', 'adresse' => '0-w1-s1-v1:2', 'texte' => str_repeat('a', 3200)]),
    'autre identifiant' => fn () => Http::response(['bibliotheque' => 'imposteur', 'adresse' => PAGE_EXEMPLE, 'texte' => str_repeat('a', 3200)]),
    'erreur 500' => fn () => Http::response('boom', 500),
    'pas du JSON' => fn () => Http::response('<html>', 200),
    'redirection' => fn () => Http::response('', 302, ['Location' => 'http://127.0.0.1/']),
]);

it('un pair dont l\'URL n\'est pas http(s) ne peut pas être appelé : 502', function () {
    voisin(['url_actuelle' => 'ftp://voisin.example.test']);
    Http::fake();

    attendErreurContrat(appel('GET', '/pages/'.PAGE_EXEMPLE.'?bibliotheque=voisin'), 502, 'ERR_PEER_UNREACHABLE', 'bibliotheque');
    Http::assertNothingSent();
});
