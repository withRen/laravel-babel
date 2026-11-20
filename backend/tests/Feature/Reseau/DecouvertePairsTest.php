<?php

use App\Enums\EtatBibliotheque;
use App\Models\Adresse;
use App\Models\Bibliotheque;
use App\Models\Collection;
use App\Models\Reglage;
use App\Models\TexteBanni;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/*
 * Découverte des pairs : GET /app/infos, GET /app/bibliotheques et les 3 routes qui relaient les collections et les
 * pages populaires d'un pair (V21). Les pairs sont simulés avec Http::fake : aucun appel réseau réel.
 */

const PAIR_URL = 'https://voisine.example.test/api/v1';

function pairVoisin(array $attributs = []): Bibliotheque
{
    return Bibliotheque::factory()->enLigne()->create($attributs + [
        'identifiant' => 'voisine', 'nom' => 'La voisine', 'url_actuelle' => 'https://voisine.example.test',
    ]);
}

/** Une page de 3 200 caractères, avec éventuellement un texte à l'intérieur. */
function textePageDePair(string $contient = ''): string
{
    $page = $contient.str_repeat('abc ', 800);

    return substr($page, 0, 3200);
}

function collectionDePair(array $pages, array $surcharge = []): array
{
    return $surcharge + [
        'id' => '7', 'nom' => 'Trouvailles', 'description' => '', 'nombre_pages' => count($pages), 'maj_le' => '2026-11-20T14:05:00+01:00',
        'pages' => array_map(fn ($p, $i) => ['position' => $i + 1, 'bibliotheque' => $p[0], 'adresse' => $p[1]], $pages, array_keys($pages)),
    ];
}

/** Réponse d'un faux POST /lectures : un texte par adresse demandée. */
function lecturesFictives(array $textes, string $bibliotheque = 'voisine')
{
    return function (Request $requete) use ($textes, $bibliotheque) {
        $elements = array_map(fn (string $adresse) => [
            'adresse' => $adresse, 'texte' => $textes[$adresse] ?? textePageDePair(), 'erreurs' => null,
        ], $requete['adresses']);

        return Http::response(['bibliotheque' => $bibliotheque, 'elements' => $elements]);
    };
}

beforeEach(function () {
    $this->locale = bibliothequeLocale();
});

/* ---------------------------------------------------------------- /app/infos */

it('renvoie la configuration de la bibliothèque au format du contrat', function () {
    $r = $this->getJson('/api/v1/app/infos');

    $r->assertOk();
    expect(array_keys($r->json()))->toBe(['identifiant', 'nom', 'description', 'empreinte_cle', 'algorithme', 'contrat', 'application_web',
        'inscriptions_ouvertes', 'constantes', 'limites', 'reseau', 'temps_reel']);
    expect($r->json('identifiant'))->toBe('exemple')
        ->and($r->json('empreinte_cle'))->toBe('f85a8e8ae6a8a1ceb96d8884d2d45c00c3cc73c95e1bdf4e5cba35664bb0c637')
        ->and($r->json('inscriptions_ouvertes'))->toBeTrue()
        ->and($r->json('reseau'))->toBe(['delai_pair_ms' => 2000]);
});

it('donne les constantes du moteur, dont le dernier hexagone', function () {
    $constantes = $this->getJson('/api/v1/app/infos')->json('constantes');

    expect($constantes)->toMatchArray([
        'alphabet' => 'abcdefghijklmnopqrstuvwxyz ,.', 'longueur_page' => 3200, 'murs' => 4, 'etageres' => 5, 'volumes' => 32,
        'pages' => 410, 'longueur_max_hexagone' => 3004,
    ]);
    // Le contrat (const) : le même dernier hexagone dans les 10 bibliothèques, quelle que soit la clé.
    expect($constantes['dernier_hexagone'])->toStartWith('4rlo36cpsg3r9uo1m4u31xzyrdgxzzy')->and(strlen($constantes['dernier_hexagone']))->toBe(3004);
});

it('donne les limites du contrat, avec la durée des liens de visiteur choisie par la bibliothèque', function () {
    expect($this->getJson('/api/v1/app/infos')->json('limites'))->toBe([
        'taille_lot_max' => 50, 'annotation_max' => 2000, 'motif_signalement_max' => 500, 'nom_collection_max' => 100,
        'description_collection_max' => 1000, 'pseudo_max' => 30, 'mot_de_passe_min' => 8, 'duree_lien_visiteur_jours' => 7,
        'import_max_octets' => 10485760,
    ]);

    Reglage::create(['cle' => 'duree_lien_visiteur_jours', 'valeur' => '30']);
    expect($this->getJson('/api/v1/app/infos')->json('limites.duree_lien_visiteur_jours'))->toBe(30);

    Reglage::whereKey('duree_lien_visiteur_jours')->update(['valeur' => '0']); // une valeur invalide ne casse pas le contrat (minimum 1)
    expect($this->getJson('/api/v1/app/infos')->json('limites.duree_lien_visiteur_jours'))->toBe(7);
});

it('annonce les inscriptions fermées (A8)', function () {
    Reglage::create(['cle' => 'inscriptions_ouvertes', 'valeur' => '0']);

    expect($this->getJson('/api/v1/app/infos')->json('inscriptions_ouvertes'))->toBeFalse();
});

it('donne null pour le temps réel quand Reverb n\'est pas la connexion de diffusion', function () {
    config(['broadcasting.default' => 'null']);

    expect($this->getJson('/api/v1/app/infos')->json('temps_reel'))->toBeNull();
});

it('donne les paramètres publics de Reverb pour le navigateur, sans aucun secret', function () {
    config([
        'broadcasting.default' => 'reverb',
        'reverb.apps.apps.0.key' => 'cle-publique', 'reverb.apps.apps.0.secret' => 'secret-a-ne-jamais-montrer',
        'babel.temps_reel' => ['hote' => 'ws.exemple.test', 'port' => 443, 'tls' => true],
    ]);

    $r = $this->getJson('/api/v1/app/infos');

    expect($r->json('temps_reel'))->toBe(['protocole' => 'pusher', 'cle' => 'cle-publique', 'hote' => 'ws.exemple.test', 'port' => 443, 'tls' => true]);
    expect($r->getContent())->not->toContain('secret-a-ne-jamais-montrer');
});

it('est ouverte aux visiteurs et ne casse pas avec un jeton faux', function () {
    $this->getJson('/api/v1/app/infos', ['Authorization' => 'Bearer faux'])->assertOk();
});

/* ------------------------------------------------------------ /app/bibliotheques */

it('liste les bibliothèques, la nôtre en premier, sans aucun appel réseau', function () {
    Http::fake();
    pairVoisin(['dernier_contact_le' => '2026-11-20 12:58:00', 'description' => null]);
    Bibliotheque::factory()->create(['identifiant' => 'eteinte', 'nom' => 'Éteinte', 'etat' => EtatBibliotheque::HorsLigne,
        'cle_changee_le' => '2026-11-19 10:00:00']);

    $r = $this->getJson('/api/v1/app/bibliotheques');

    $r->assertOk();
    expect(array_keys($r->json()))->toBe(['elements']);
    expect(array_column($r->json('elements'), 'identifiant'))->toBe(['exemple', 'eteinte', 'voisine']);
    expect($r->json('elements.0'))->toBe([
        'identifiant' => 'exemple', 'nom' => "La bibliothèque d'exemple", 'description' => 'Une bibliothèque de démonstration.',
        'est_locale' => true, 'etat' => 'en_ligne', 'dernier_contact_le' => null, 'cle_changee_le' => null,
        'application_web' => 'http://localhost:4200',
    ]);
    expect($r->json('elements.1'))->toMatchArray(['est_locale' => false, 'etat' => 'hors_ligne', 'cle_changee_le' => '2026-11-19T10:00:00+00:00']);
    expect($r->json('elements.2'))->toMatchArray(['description' => '', 'etat' => 'en_ligne', 'dernier_contact_le' => '2026-11-20T12:58:00+00:00'])
        ->and($r->json('elements.2.application_web'))->toBeNull();
    Http::assertNothingSent();
});

it('ne montre jamais l\'URL ni l\'empreinte de clé d\'un pair', function () {
    pairVoisin();

    $contenu = $this->getJson('/api/v1/app/bibliotheques')->getContent();

    expect($contenu)->not->toContain('example.test')->and($contenu)->not->toContain('empreinte');
});

it('liste notre bibliothèque d\'après la configuration quand la base n\'est pas amorcée', function () {
    Bibliotheque::query()->delete();

    $r = $this->getJson('/api/v1/app/bibliotheques');

    expect($r->json('elements'))->toHaveCount(1)->and($r->json('elements.0.est_locale'))->toBeTrue()
        ->and($r->json('elements.0.identifiant'))->toBe(config('babel.identifiant'));
});

/* ------------------------------------------------ collections d'une bibliothèque */

it('lit les collections de NOTRE bibliothèque avec notre identifiant, comme la porte réseau (V13)', function () {
    Http::fake();
    $collection = Collection::factory()->publique()->create(['nom' => 'Chez nous', 'maj_le' => '2026-11-20 13:05:00']);
    $adresse = Adresse::factory()->create(['bibliotheque_id' => $this->locale->id, 'hexagone' => '0', 'mur' => 1, 'etagere' => 1, 'volume' => 1, 'page' => 1]);
    $collection->pages()->create(['position' => 1, 'adresse_id' => $adresse->id]);

    expect($this->getJson('/api/v1/app/bibliotheques/exemple/collections')->json())
        ->toBe($this->getJson('/api/v1/collections')->json());
    expect($this->getJson("/api/v1/app/bibliotheques/exemple/collections/{$collection->id}")->json())
        ->toBe($this->getJson("/api/v1/collections/{$collection->id}")->json());
    Http::assertNothingSent();
});

it('relaie la liste des collections d\'un pair, avec lot et taille', function () {
    pairVoisin();
    $liste = ['lot' => 2, 'taille' => 5, 'total' => 7, 'elements' => [
        ['id' => '3', 'nom' => 'Autre', 'description' => '', 'nombre_pages' => 4, 'maj_le' => '2026-11-20T14:05:00+01:00'],
    ]];
    Http::fake([PAIR_URL.'/collections*' => Http::response($liste)]);

    $r = $this->getJson('/api/v1/app/bibliotheques/voisine/collections?lot=2&taille=5');

    $r->assertOk();
    expect($r->json())->toBe($liste);
    Http::assertSent(fn (Request $q) => $q->method() === 'GET' && str_starts_with($q->url(), PAIR_URL.'/collections')
        && $q['lot'] == 2 && $q['taille'] == 5 && ! $q->hasHeader('Authorization')); // le jeton réseau ne sort jamais ici
});

it('refuse un lot ou une taille mal écrits avant d\'appeler le pair', function () {
    pairVoisin();
    Http::fake();

    attendErreurContrat($this->getJson('/api/v1/app/bibliotheques/voisine/collections?taille=51'), 422, 'ERR_INVALID_PARAMETER', 'taille');
    attendErreurContrat($this->getJson('/api/v1/app/bibliotheques/voisine/collections?lot=0'), 422, 'ERR_INVALID_PARAMETER', 'lot');
    Http::assertNothingSent();
});

it('répond 404 ERR_UNKNOWN_LIBRARY pour une bibliothèque qu\'on ne connaît pas, sans rien appeler', function () {
    Http::fake();

    $r = $this->getJson('/api/v1/app/bibliotheques/inconnue/collections');

    attendErreurContrat($r, 404, 'ERR_UNKNOWN_LIBRARY', 'identifiant');
    attendErreurContrat($this->getJson('/api/v1/app/bibliotheques/inconnue/pages-populaires'), 404, 'ERR_UNKNOWN_LIBRARY', 'identifiant');
    attendErreurContrat($this->getJson('/api/v1/app/bibliotheques/inconnue/collections/1'), 404, 'ERR_UNKNOWN_LIBRARY', 'identifiant');
    Http::assertNothingSent();
});

it('répond 502 quand le pair est éteint, et compte un échec', function () {
    $pair = pairVoisin();
    Http::fake([PAIR_URL.'/*' => fn () => throw new ConnectionException('timeout')]);

    $r = $this->getJson('/api/v1/app/bibliotheques/voisine/collections');

    attendErreurContrat($r, 502, 'ERR_PEER_UNREACHABLE', 'bibliotheque');
    expect($pair->fresh()->echecs_consecutifs)->toBe(1);
});

it('passe le pair hors ligne au troisième échec de suite', function () {
    $pair = pairVoisin(['echecs_consecutifs' => 2]);
    Http::fake([PAIR_URL.'/*' => Http::response('panne', 500)]);

    attendErreurContrat($this->getJson('/api/v1/app/bibliotheques/voisine/pages-populaires'), 502, 'ERR_PEER_UNREACHABLE', 'bibliotheque');

    expect($pair->fresh())->echecs_consecutifs->toBe(3)->etat->toBe(EtatBibliotheque::HorsLigne);
});

it('traite une réponse hors contrat comme une panne : rien n\'en est montré', function () {
    $pair = pairVoisin();
    // Un champ en trop (« additionalProperties: false ») et un id numérique au lieu d'une chaîne.
    Http::fake([PAIR_URL.'/collections*' => Http::response(['lot' => 1, 'taille' => 20, 'total' => 1, 'secret' => 'x', 'elements' => []])]);

    attendErreurContrat($this->getJson('/api/v1/app/bibliotheques/voisine/collections'), 502, 'ERR_PEER_UNREACHABLE', 'bibliotheque');
    expect($pair->fresh()->echecs_consecutifs)->toBe(1);

    Http::fake([PAIR_URL.'/collections*' => Http::response(['lot' => 1, 'taille' => 20, 'total' => 1, 'elements' => [
        ['id' => 3, 'nom' => 'A', 'description' => '', 'nombre_pages' => 0, 'maj_le' => '2026-11-20T14:05:00+01:00'],
    ]])]);
    attendErreurContrat($this->getJson('/api/v1/app/bibliotheques/voisine/collections'), 502, 'ERR_PEER_UNREACHABLE', 'bibliotheque');
});

it('répond 503 ERR_PEER_BUSY quand le pair dit 429, avec son Retry-After, sans compter d\'échec', function () {
    $pair = pairVoisin(['echecs_consecutifs' => 2]);
    Http::fake([PAIR_URL.'/*' => Http::response('', 429, ['Retry-After' => '12'])]);

    $r = $this->getJson('/api/v1/app/bibliotheques/voisine/collections');

    attendErreurContrat($r, 503, 'ERR_PEER_BUSY', 'bibliotheque');
    expect($r->headers->get('Retry-After'))->toBe('12');
    expect($pair->fresh())->echecs_consecutifs->toBe(2)->etat->toBe(EtatBibliotheque::EnLigne);
});

it('ne met pas de Retry-After quand le pair n\'en donne pas', function () {
    pairVoisin();
    Http::fake([PAIR_URL.'/*' => Http::response('', 429)]);

    $r = $this->getJson('/api/v1/app/bibliotheques/voisine/pages-populaires');

    attendErreurContrat($r, 503, 'ERR_PEER_BUSY', 'bibliotheque');
    expect($r->headers->has('Retry-After'))->toBeFalse();
});

/* ---------------------------------------- une collection d'un pair, filtrée (V21) */

it('lit une collection d\'un pair et la rend telle quelle quand aucun texte n\'est banni (sans lire les pages)', function () {
    pairVoisin();
    $collection = collectionDePair([['voisine', '0-w1-s1-v1:1'], ['voisine', '0-w1-s1-v1:2']]);
    Http::fake([PAIR_URL.'/collections/7' => Http::response($collection)]);

    $r = $this->getJson('/api/v1/app/bibliotheques/voisine/collections/7');

    $r->assertOk();
    expect($r->json())->toBe($collection);
    Http::assertSentCount(1); // aucun texte appliqué : rien à vérifier, donc aucune page lue
});

it('retire les pages qui contiennent un de nos textes bannis appliqués, sans décaler les autres', function () {
    pairVoisin();
    TexteBanni::factory()->applique()->create(['texte' => 'texte interdit']);
    $collection = collectionDePair([['voisine', '0-w1-s1-v1:1'], ['voisine', '0-w1-s1-v1:2'], ['voisine', '0-w1-s1-v1:3']]);
    Http::fake([
        PAIR_URL.'/collections/7' => Http::response($collection),
        PAIR_URL.'/lectures' => lecturesFictives(['0-w1-s1-v1:2' => textePageDePair('texte interdit')]),
    ]);

    $r = $this->getJson('/api/v1/app/bibliotheques/voisine/collections/7');

    $r->assertOk();
    expect($r->json('nombre_pages'))->toBe(2)
        ->and(array_column($r->json('pages'), 'position'))->toBe([1, 3]) // la position 3 ne devient pas 2
        ->and(array_column($r->json('pages'), 'adresse'))->toBe(['0-w1-s1-v1:1', '0-w1-s1-v1:3']);
    expect($r->json('id'))->toBe('7')->and($r->json('nom'))->toBe('Trouvailles');
    // Une seule requête de lecture groupée (POST /lectures), pas une par page.
    Http::assertSentCount(2);
});

it('ne tient pas compte d\'un texte seulement proposé ou rejeté', function () {
    pairVoisin();
    TexteBanni::factory()->create(['texte' => 'texte propose']); // statut proposé
    TexteBanni::factory()->create(['texte' => 'texte rejete', 'statut' => 'rejete']);
    Http::fake([PAIR_URL.'/collections/7' => Http::response(collectionDePair([['voisine', '0-w1-s1-v1:1']]))]);

    $this->getJson('/api/v1/app/bibliotheques/voisine/collections/7')->assertOk()->assertJsonPath('nombre_pages', 1);
});

it('ne montre pas une page qu\'on ne peut pas vérifier (adresse refusée par le pair)', function () {
    pairVoisin();
    TexteBanni::factory()->applique()->create(['texte' => 'texte interdit']);
    Http::fake([
        PAIR_URL.'/collections/7' => Http::response(collectionDePair([['voisine', '0-w1-s1-v1:1'], ['voisine', '0-w1-s1-v1:2']])),
        PAIR_URL.'/lectures' => Http::response(['bibliotheque' => 'voisine', 'elements' => [
            ['adresse' => '0-w1-s1-v1:1', 'texte' => null, 'erreurs' => [['code' => 'ERR_OUT_OF_BOUNDS', 'champ' => 'page', 'message' => 'Hors limites.']]],
            ['adresse' => '0-w1-s1-v1:2', 'texte' => textePageDePair(), 'erreurs' => null],
        ]]),
    ]);

    $r = $this->getJson('/api/v1/app/bibliotheques/voisine/collections/7');

    expect(array_column($r->json('pages'), 'adresse'))->toBe(['0-w1-s1-v1:2']);
});

it('lit les pages d\'une autre bibliothèque chez elle, et celles de la nôtre avec notre moteur', function () {
    pairVoisin();
    Bibliotheque::factory()->enLigne()->create(['identifiant' => 'lointaine', 'url_actuelle' => 'https://lointaine.example.test']);
    TexteBanni::factory()->applique()->create(['texte' => 'texte interdit']);
    Http::fake([
        PAIR_URL.'/collections/7' => Http::response(collectionDePair([['voisine', '0-w1-s1-v1:1'], ['lointaine', '0-w1-s1-v1:2'], ['exemple', '0-w1-s1-v1:3']])),
        PAIR_URL.'/lectures' => lecturesFictives([]),
        'https://lointaine.example.test/api/v1/lectures' => lecturesFictives(['0-w1-s1-v1:2' => textePageDePair('texte interdit')], 'lointaine'),
    ]);

    $r = $this->getJson('/api/v1/app/bibliotheques/voisine/collections/7');

    $r->assertOk();
    // La page de « lointaine » est retirée ; celle de « exemple » (la nôtre) est lue sans réseau et ne contient pas le texte.
    expect(array_column($r->json('pages'), 'bibliotheque'))->toBe(['voisine', 'exemple']);
    Http::assertSent(fn (Request $q) => $q->url() === 'https://lointaine.example.test/api/v1/lectures');
});

it('ne montre pas les pages d\'une bibliothèque inconnue ou muette, mais n\'échoue pas pour autant', function () {
    pairVoisin();
    $muette = Bibliotheque::factory()->enLigne()->create(['identifiant' => 'muette', 'url_actuelle' => 'https://muette.example.test']);
    TexteBanni::factory()->applique()->create(['texte' => 'texte interdit']);
    Http::fake([
        PAIR_URL.'/collections/7' => Http::response(collectionDePair([['voisine', '0-w1-s1-v1:1'], ['muette', '0-w1-s1-v1:2'], ['fantome', '0-w1-s1-v1:3']])),
        PAIR_URL.'/lectures' => lecturesFictives([]),
        'https://muette.example.test/*' => fn () => throw new ConnectionException('timeout'),
    ]);

    $r = $this->getJson('/api/v1/app/bibliotheques/voisine/collections/7');

    $r->assertOk();
    expect(array_column($r->json('pages'), 'bibliotheque'))->toBe(['voisine'])->and($r->json('nombre_pages'))->toBe(1);
    expect($muette->fresh()->echecs_consecutifs)->toBe(1);
});

it('échoue en 502 quand le pair interrogé ne peut pas relire ses propres pages', function () {
    pairVoisin();
    TexteBanni::factory()->applique()->create(['texte' => 'texte interdit']);
    Http::fake([
        PAIR_URL.'/collections/7' => Http::response(collectionDePair([['voisine', '0-w1-s1-v1:1']])),
        PAIR_URL.'/lectures' => Http::response('panne', 500),
    ]);

    attendErreurContrat($this->getJson('/api/v1/app/bibliotheques/voisine/collections/7'), 502, 'ERR_PEER_UNREACHABLE', 'bibliotheque');
});

it('refuse la réponse d\'un pair dont les lectures ne suivent pas l\'ordre demandé', function () {
    pairVoisin();
    TexteBanni::factory()->applique()->create(['texte' => 'texte interdit']);
    Http::fake([
        PAIR_URL.'/collections/7' => Http::response(collectionDePair([['voisine', '0-w1-s1-v1:1'], ['voisine', '0-w1-s1-v1:2']])),
        PAIR_URL.'/lectures' => Http::response(['bibliotheque' => 'voisine', 'elements' => [
            ['adresse' => '0-w1-s1-v1:2', 'texte' => textePageDePair(), 'erreurs' => null],
            ['adresse' => '0-w1-s1-v1:1', 'texte' => textePageDePair(), 'erreurs' => null],
        ]]),
    ]);

    attendErreurContrat($this->getJson('/api/v1/app/bibliotheques/voisine/collections/7'), 502, 'ERR_PEER_UNREACHABLE', 'bibliotheque');
});

it('répond 404 quand le pair n\'a pas cette collection, sans compter d\'échec', function () {
    $pair = pairVoisin();
    Http::fake([PAIR_URL.'/collections/99' => Http::response(['erreurs' => [['code' => 'ERR_NOT_FOUND', 'champ' => 'id', 'message' => 'Non.']]], 404)]);

    attendErreurContrat($this->getJson('/api/v1/app/bibliotheques/voisine/collections/99'), 404, 'ERR_NOT_FOUND', 'id');
    expect($pair->fresh()->echecs_consecutifs)->toBe(0);
});

it('refuse une collection dont les pages ne sont pas par position croissante', function () {
    pairVoisin();
    $collection = collectionDePair([['voisine', '0-w1-s1-v1:1'], ['voisine', '0-w1-s1-v1:2']]);
    $collection['pages'][1]['position'] = 1;
    Http::fake([PAIR_URL.'/collections/7' => Http::response($collection)]);

    attendErreurContrat($this->getJson('/api/v1/app/bibliotheques/voisine/collections/7'), 502, 'ERR_PEER_UNREACHABLE', 'bibliotheque');
});

it('refuse une adresse de livre ou mal écrite dans une collection d\'un pair', function () {
    pairVoisin();
    Http::fake([PAIR_URL.'/collections/7' => Http::response(collectionDePair([['voisine', '0-w1-s1-v1']]))]); // un livre, pas une page

    attendErreurContrat($this->getJson('/api/v1/app/bibliotheques/voisine/collections/7'), 502, 'ERR_PEER_UNREACHABLE', 'bibliotheque');
});

/* ------------------------------------------------ pages populaires d'un pair */

it('relaie les pages populaires de NOTRE bibliothèque avec notre identifiant', function () {
    $adresse = Adresse::factory()->create(['bibliotheque_id' => $this->locale->id, 'hexagone' => '0', 'mur' => 1, 'etagere' => 1, 'volume' => 1, 'page' => 1]);
    $adresse->marquePages()->create(['utilisateur_id' => User::factory()->create()->id, 'titre' => 'Un']);

    expect($this->getJson('/api/v1/app/bibliotheques/exemple/pages-populaires')->json())
        ->toBe($this->getJson('/api/v1/pages-populaires')->json());
});

it('relaie les pages populaires d\'un pair en retirant celles qui contiennent un texte banni', function () {
    pairVoisin();
    TexteBanni::factory()->applique()->create(['texte' => 'texte interdit']);
    Http::fake([
        PAIR_URL.'/pages-populaires*' => Http::response(['elements' => [
            ['bibliotheque' => 'voisine', 'adresse' => '0-w1-s1-v1:1', 'score' => 9],
            ['bibliotheque' => 'voisine', 'adresse' => '0-w1-s1-v1:2', 'score' => 5],
        ]]),
        PAIR_URL.'/lectures' => lecturesFictives(['0-w1-s1-v1:1' => textePageDePair('texte interdit')]),
    ]);

    $r = $this->getJson('/api/v1/app/bibliotheques/voisine/pages-populaires?taille=10');

    $r->assertOk();
    expect($r->json())->toBe(['elements' => [['bibliotheque' => 'voisine', 'adresse' => '0-w1-s1-v1:2', 'score' => 5]]]);
    Http::assertSent(fn (Request $q) => str_starts_with($q->url(), PAIR_URL.'/pages-populaires') && $q['taille'] == 10);
});

it('refuse des pages populaires qui ne sont pas par score décroissant', function () {
    pairVoisin();
    Http::fake([PAIR_URL.'/pages-populaires*' => Http::response(['elements' => [
        ['bibliotheque' => 'voisine', 'adresse' => '0-w1-s1-v1:1', 'score' => 1],
        ['bibliotheque' => 'voisine', 'adresse' => '0-w1-s1-v1:2', 'score' => 5],
    ]])]);

    attendErreurContrat($this->getJson('/api/v1/app/bibliotheques/voisine/pages-populaires'), 502, 'ERR_PEER_UNREACHABLE', 'bibliotheque');
});

it('lit les pages de plus de 50 adresses par lots de 50', function () {
    pairVoisin();
    TexteBanni::factory()->applique()->create(['texte' => 'texte interdit']);
    $pages = [];
    for ($i = 1; $i <= 120; $i++) {
        $pages[] = ['voisine', "0-w1-s1-v1:{$i}"];
    }
    $pages = array_slice($pages, 0, 120);
    Http::fake([
        PAIR_URL.'/collections/7' => Http::response(collectionDePair($pages)),
        PAIR_URL.'/lectures' => lecturesFictives([]),
    ]);

    // Les pages 1 à 120 du même livre : 410 est la limite, 120 est valide. 3 lots (50 + 50 + 20).
    $this->getJson('/api/v1/app/bibliotheques/voisine/collections/7')->assertOk()->assertJsonPath('nombre_pages', 120);
    Http::assertSentCount(4);
});
