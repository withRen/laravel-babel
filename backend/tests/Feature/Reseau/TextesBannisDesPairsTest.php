<?php

use App\Enums\EtatBibliotheque;
use App\Enums\StatutTexteBanni;
use App\Jobs\ReleverTextesBannisDuPair;
use App\Models\Adresse;
use App\Models\Bibliotheque;
use App\Models\Collection;
use App\Models\TexteBanni;
use App\Services\Moderation\ApplicationDesTextesBannis;
use App\Services\Reseau\ClientPair;
use App\Services\Reseau\ErreurPair;
use App\Services\Reseau\ReleveDesTextesBannis;
use Babel\Moteur\Moteur;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/*
 * Textes bannis des pairs (A7) : le relevé (B5), puis appliquer ou lever un texte (masquage de ce qui est publié vers lui).
 */

const TEXTES_BANNIS_URL = 'https://voisine.example.test/api/v1/textes-bannis';

function texteBanniDePair(string $texte, ?string $motif = 'Exemple.'): array
{
    return ['texte' => $texte, 'empreinte' => hash('sha256', $texte), 'motif' => $motif, 'banni_le' => '2026-11-20T14:05:00+01:00'];
}

function voisineEnLigne(): Bibliotheque
{
    return Bibliotheque::factory()->enLigne()->create(['identifiant' => 'voisine', 'url_actuelle' => 'https://voisine.example.test']);
}

function releverChez(Bibliotheque $pair): int
{
    return app(ReleveDesTextesBannis::class)->relever($pair);
}

beforeEach(function () {
    $this->locale = bibliothequeLocale();
});

/* ------------------------------------------------------------- relevé */

it('relève les textes d\'un pair avec le jeton réseau, et les enregistre comme PROPOSÉS', function () {
    $pair = voisineEnLigne();
    Http::fake([TEXTES_BANNIS_URL => Http::response(['elements' => [texteBanniDePair('texte interdit', 'Haineux.'), texteBanniDePair('autre texte', null)]])]);

    expect(releverChez($pair))->toBe(2);

    Http::assertSent(fn (Request $q) => $q->method() === 'GET' && $q->url() === TEXTES_BANNIS_URL && $q->header('Authorization') === ['Bearer jeton-reseau-de-test']);
    $texte = TexteBanni::where('texte', 'texte interdit')->sole();
    expect($texte->statut)->toBe(StatutTexteBanni::Propose)
        ->and($texte->origine_id)->toBe($pair->id)
        ->and($texte->motif)->toBe('Haineux.')
        ->and($texte->empreinte)->toBe(hash('sha256', 'texte interdit'))
        ->and($texte->decide_le)->toBeNull();
    expect(TexteBanni::where('texte', 'autre texte')->sole()->motif)->toBeNull();
});

it('ne masque rien tant que le texte n\'est pas appliqué', function () {
    $pair = voisineEnLigne();
    Http::fake([TEXTES_BANNIS_URL => Http::response(['elements' => [texteBanniDePair('texte interdit')]])]);
    releverChez($pair);
    $adresse = Adresse::factory()->create(['bibliotheque_id' => $this->locale->id, 'hexagone' => '0', 'mur' => 1, 'etagere' => 1, 'volume' => 1, 'page' => 1]);
    $collection = Collection::factory()->publique()->create();
    $collection->pages()->create(['position' => 1, 'adresse_id' => $adresse->id]);

    expect($this->getJson('/api/v1/collections/'.$collection->id)->json('nombre_pages'))->toBe(1);
    expect($adresse->fresh()->texte_banni_id)->toBeNull();
});

it('ne retouche pas un texte déjà connu : un texte rejeté ne revient pas en « proposé »', function () {
    $pair = voisineEnLigne();
    TexteBanni::factory()->create(['texte' => 'texte interdit', 'origine_id' => $pair->id, 'statut' => StatutTexteBanni::Rejete, 'decide_le' => '2026-11-19 10:00:00']);
    TexteBanni::factory()->applique()->create(['texte' => 'deja applique', 'origine_id' => $this->locale->id]);
    Http::fake([TEXTES_BANNIS_URL => Http::response(['elements' => [texteBanniDePair('texte interdit'), texteBanniDePair('deja applique')]])]);

    expect(releverChez($pair))->toBe(0);

    expect(TexteBanni::where('texte', 'texte interdit')->sole()->statut)->toBe(StatutTexteBanni::Rejete);
    expect(TexteBanni::where('texte', 'deja applique')->sole()->statut)->toBe(StatutTexteBanni::Applique);
    expect(TexteBanni::count())->toBe(2);
});

it('relève deux fois sans doublon', function () {
    $pair = voisineEnLigne();
    Http::fake([TEXTES_BANNIS_URL => Http::response(['elements' => [texteBanniDePair('texte interdit')]])]);

    expect(releverChez($pair))->toBe(1)->and(releverChez($pair))->toBe(0);
    expect(TexteBanni::count())->toBe(1);
});

it('garde un texte que le pair ne publie plus : appliquer ou non est notre décision', function () {
    $pair = voisineEnLigne();
    TexteBanni::factory()->applique()->create(['texte' => 'texte ancien', 'origine_id' => $pair->id]);
    Http::fake([TEXTES_BANNIS_URL => Http::response(['elements' => []])]);

    releverChez($pair);

    expect(TexteBanni::where('texte', 'texte ancien')->sole()->statut)->toBe(StatutTexteBanni::Applique);
});

it('ne republie jamais un texte venu d\'un pair : la porte réseau ne sert que les textes d\'origine locale', function () {
    $pair = voisineEnLigne();
    Http::fake([TEXTES_BANNIS_URL => Http::response(['elements' => [texteBanniDePair('texte interdit')]])]);
    releverChez($pair);
    TexteBanni::query()->update(['statut' => StatutTexteBanni::Applique]); // même appliqué chez nous

    $r = $this->getJson('/api/v1/textes-bannis', jetonReseau());

    expect($r->json('elements'))->toBe([]);
});

it('n\'enregistre rien quand la réponse du pair est hors contrat, et compte un échec', function (array $corps) {
    $pair = voisineEnLigne();
    Http::fake([TEXTES_BANNIS_URL => Http::response($corps)]);

    expect(fn () => releverChez($pair))->toThrow(ErreurPair::class);

    expect(TexteBanni::count())->toBe(0)->and($pair->fresh()->echecs_consecutifs)->toBe(1);
})->with([
    'empreinte qui n\'est pas celle du texte' => [['elements' => [['texte' => 'texte interdit', 'empreinte' => str_repeat('a', 64), 'motif' => null, 'banni_le' => '2026-11-20T14:05:00+01:00']]]],
    'texte non normalisé' => [['elements' => [texteBanniDePair('Texte INTERDIT')]]],
    'texte vide' => [['elements' => [texteBanniDePair('')]]],
    'texte avec espaces doubles' => [['elements' => [texteBanniDePair('deux  espaces')]]],
    'date mal écrite' => [['elements' => [['texte' => 'a', 'empreinte' => hash('sha256', 'a'), 'motif' => null, 'banni_le' => 'hier']]]],
    'champ en trop' => [['elements' => [texteBanniDePair('a') + ['auteur' => 'x']]]],
    'motif trop long' => [['elements' => [texteBanniDePair('a', str_repeat('m', 501))]]],
    'pas de elements' => [['textes' => []]],
    'liste à la racine' => [[texteBanniDePair('a')]],
]);

it('un seul élément hors contrat fait refuser toute la réponse', function () {
    $pair = voisineEnLigne();
    Http::fake([TEXTES_BANNIS_URL => Http::response(['elements' => [texteBanniDePair('bon texte'), texteBanniDePair('Mauvais')]])]);

    expect(fn () => releverChez($pair))->toThrow(ErreurPair::class);

    expect(TexteBanni::count())->toBe(0);
});

it('ne compte pas d\'échec quand le pair est occupé (429)', function () {
    $pair = voisineEnLigne();
    Http::fake([TEXTES_BANNIS_URL => Http::response('', 429, ['Retry-After' => '30'])]);

    expect(fn () => releverChez($pair))->toThrow(fn (ErreurPair $e) => $e->occupe && $e->reessayerApres === 30);

    expect($pair->fresh()->echecs_consecutifs)->toBe(0);
});

it('ne compte pas d\'échec quand le pair refuse NOTRE jeton (401) : ce n\'est pas sa panne', function () {
    $pair = voisineEnLigne();
    Http::fake([TEXTES_BANNIS_URL => Http::response(['erreurs' => [['code' => 'ERR_INVALID_NETWORK_TOKEN', 'champ' => null, 'message' => 'Non.']]], 401)]);

    expect(releverChez($pair))->toBe(0);

    expect($pair->fresh()->echecs_consecutifs)->toBe(0)->and(TexteBanni::count())->toBe(0);
});

it('n\'appelle pas le pair sans jeton réseau configuré', function () {
    $pair = voisineEnLigne();
    Http::fake();
    config(['babel.network_token' => null]);

    expect(releverChez($pair))->toBe(0);

    Http::assertNothingSent();
});

it('n\'envoie le jeton réseau à aucune autre route que GET /textes-bannis', function () {
    $pair = voisineEnLigne();
    Http::fake(['*' => Http::response(['bibliotheque' => 'voisine', 'texte_normalise' => 'a', 'mode' => 'exact', 'adresse' => '0-w1-s1-v1:1'])]);

    app(ClientPair::class)->chercher($pair, 'a', 'exact');

    Http::assertSent(fn (Request $q) => str_ends_with($q->url(), '/recherches') && ! $q->hasHeader('Authorization'));
});

it('compte un échec quand le pair est éteint', function () {
    $pair = voisineEnLigne();
    Http::fake([TEXTES_BANNIS_URL => fn () => throw new ConnectionException('timeout')]);

    expect(fn () => releverChez($pair))->toThrow(ErreurPair::class);

    expect($pair->fresh()->echecs_consecutifs)->toBe(1);
});

/* ---------------------------------------------------- commande et tâches */

it('dépose une tâche de relevé par pair EN LIGNE, et refuse de tourner sans jeton', function () {
    Queue::fake();
    voisineEnLigne();
    Bibliotheque::factory()->enLigne()->create(['identifiant' => 'lointaine']);
    Bibliotheque::factory()->create(['identifiant' => 'eteinte', 'etat' => EtatBibliotheque::HorsLigne]);

    $this->artisan('babel:relever-textes-bannis')->assertSuccessful();
    Queue::assertPushed(ReleverTextesBannisDuPair::class, 2);

    Queue::fake();
    config(['babel.network_token' => '']);
    $this->artisan('babel:relever-textes-bannis')->assertFailed();
    Queue::assertNothingPushed();
});

it('la tâche de relevé avale un pair en panne : le prochain relevé reprendra', function () {
    $pair = voisineEnLigne();
    Http::fake([TEXTES_BANNIS_URL => Http::response('panne', 500)]);

    (new ReleverTextesBannisDuPair($pair->id))->handle(app(ReleveDesTextesBannis::class));

    expect($pair->fresh()->echecs_consecutifs)->toBe(1);
});

it('planifie le relevé toutes les 10 minutes', function () {
    $evenements = collect(app(Schedule::class)->events())
        ->filter(fn ($e) => str_contains($e->command, 'babel:relever-textes-bannis'));

    expect($evenements)->toHaveCount(1)->and($evenements->first()->expression)->toBe('*/10 * * * *');
});

/* ---------------------------------------------------- appliquer et lever */

/** Une adresse de page enregistrée chez nous, celle que le moteur renvoie pour ce texte. */
function adresseDuTexte(Bibliotheque $locale, string $texte, string $mode = 'exact'): Adresse
{
    preg_match('/^(.+)-w(\d)-s(\d)-v(\d+):(\d+)$/', app(Moteur::class)->chercher($texte, $mode), $m);

    return Adresse::factory()->create([
        'bibliotheque_id' => $locale->id, 'hexagone' => $m[1], 'mur' => (int) $m[2], 'etagere' => (int) $m[3], 'volume' => (int) $m[4], 'page' => (int) $m[5],
    ]);
}

it('appliquer un texte marque les adresses enregistrées que le moteur renvoie pour lui, en modes exact et bruit', function () {
    $texte = TexteBanni::factory()->create(['texte' => 'texte interdit']);
    $exacte = adresseDuTexte($this->locale, 'texte interdit', 'exact');
    $bruit = adresseDuTexte($this->locale, 'texte interdit', 'bruit');
    $autre = adresseDuTexte($this->locale, 'une autre phrase');

    $marquees = app(ApplicationDesTextesBannis::class)->appliquer($texte);

    expect($marquees)->toBe(2);
    expect($exacte->fresh()->texte_banni_id)->toBe($texte->id)->and($bruit->fresh()->texte_banni_id)->toBe($texte->id)
        ->and($autre->fresh()->texte_banni_id)->toBeNull();
    expect($texte->fresh())->statut->toBe(StatutTexteBanni::Applique)->decide_le->not->toBeNull();
});

it('appliquer un texte retire de la porte réseau ce qui est publié vers lui, sans supprimer la page', function () {
    $texte = TexteBanni::factory()->create(['texte' => 'texte interdit']);
    $adresse = adresseDuTexte($this->locale, 'texte interdit');
    $autre = adresseDuTexte($this->locale, 'une autre phrase');
    $collection = Collection::factory()->publique()->create();
    $collection->pages()->createMany([['position' => 1, 'adresse_id' => $adresse->id], ['position' => 2, 'adresse_id' => $autre->id]]);
    expect($this->getJson('/api/v1/collections/'.$collection->id)->json('nombre_pages'))->toBe(2);

    app(ApplicationDesTextesBannis::class)->appliquer($texte);

    $r = $this->getJson('/api/v1/collections/'.$collection->id);
    expect($r->json('nombre_pages'))->toBe(1)->and($r->json('pages.0.position'))->toBe(2);
    $this->getJson('/api/v1/pages/'.$adresse->ecriture())->assertOk(); // la page reste lisible (rien n'est supprimé)
    expect(Adresse::find($adresse->id))->not->toBeNull()->and(Collection::find($collection->id)->masque_le)->toBeNull();
});

it('une page déjà marquée par un autre texte garde sa marque : le premier trouvé suffit', function () {
    $premier = TexteBanni::factory()->applique()->create(['texte' => 'premier texte']);
    $second = TexteBanni::factory()->create(['texte' => 'texte interdit']);
    $adresse = adresseDuTexte($this->locale, 'texte interdit');
    $adresse->forceFill(['texte_banni_id' => $premier->id])->save();

    expect(app(ApplicationDesTextesBannis::class)->appliquer($second))->toBe(0);

    expect($adresse->fresh()->texte_banni_id)->toBe($premier->id);
});

it('montre ce qui serait masqué sans rien changer (A7, étape 4)', function () {
    $texte = TexteBanni::factory()->create(['texte' => 'texte interdit']);
    $adresse = adresseDuTexte($this->locale, 'texte interdit');

    $concernees = app(ApplicationDesTextesBannis::class)->adressesConcernees($texte);

    expect($concernees)->toHaveCount(1)->and($concernees[0]->is($adresse))->toBeTrue();
    expect($adresse->fresh()->texte_banni_id)->toBeNull()->and($texte->fresh()->statut)->toBe(StatutTexteBanni::Propose);
});

it('ne plante pas pour un texte que le moteur refuse (trop long)', function () {
    $texte = TexteBanni::factory()->create();
    $texte->texte = str_repeat('a', 3201);

    expect(app(ApplicationDesTextesBannis::class)->adressesConcernees($texte))->toBe([]);
});

it('lever un bannissement retire ses marques : la page redevient visible', function () {
    $texte = TexteBanni::factory()->applique()->create(['texte' => 'texte interdit']);
    $adresse = adresseDuTexte($this->locale, 'texte interdit');
    $adresse->forceFill(['texte_banni_id' => $texte->id])->save();

    app(ApplicationDesTextesBannis::class)->lever($texte);

    expect($adresse->fresh()->texte_banni_id)->toBeNull();
    expect($texte->fresh())->statut->toBe(StatutTexteBanni::Rejete)->decide_le->not->toBeNull();
});

it('lever un bannissement revérifie la page contre les AUTRES textes appliqués', function () {
    $moteur = app(Moteur::class);
    $adresse = adresseDuTexte($this->locale, 'texte interdit');
    $page = $moteur->lire($adresse->ecriture());
    $levé = TexteBanni::factory()->applique()->create(['texte' => 'texte interdit']);
    // Un autre texte appliqué qui se trouve aussi dans cette page (un morceau de son contenu, déjà normalisé).
    $autre = TexteBanni::factory()->applique()->create(['texte' => 'interdit']);
    // Un texte seulement proposé ne compte pas.
    TexteBanni::factory()->create(['texte' => 'texte']);
    expect($page)->toContain('interdit');
    $adresse->forceFill(['texte_banni_id' => $levé->id])->save();

    app(ApplicationDesTextesBannis::class)->lever($levé);

    expect($adresse->fresh()->texte_banni_id)->toBe($autre->id);
});

it('lever un bannissement ne touche pas aux pages marquées par un autre texte', function () {
    $levé = TexteBanni::factory()->applique()->create(['texte' => 'texte interdit']);
    $autre = TexteBanni::factory()->applique()->create(['texte' => 'une autre phrase']);
    $adresse = adresseDuTexte($this->locale, 'une autre phrase');
    $adresse->forceFill(['texte_banni_id' => $autre->id])->save();

    app(ApplicationDesTextesBannis::class)->lever($levé);

    expect($adresse->fresh()->texte_banni_id)->toBe($autre->id);
});

it('lever un bannissement sur une page d\'une autre bibliothèque retire la marque (la tâche de fond la revérifiera)', function () {
    $pair = voisineEnLigne();
    $texte = TexteBanni::factory()->applique()->create(['texte' => 'texte interdit']);
    TexteBanni::factory()->applique()->create(['texte' => 'un autre']);
    $adresse = Adresse::factory()->create(['bibliotheque_id' => $pair->id, 'hexagone' => '5', 'mur' => 1, 'etagere' => 1, 'volume' => 1, 'page' => 1, 'texte_banni_id' => $texte->id]);
    Http::fake();

    app(ApplicationDesTextesBannis::class)->lever($texte);

    expect($adresse->fresh()->texte_banni_id)->toBeNull();
    Http::assertNothingSent(); // aucune lecture réseau pendant l'action de l'administrateur
});
