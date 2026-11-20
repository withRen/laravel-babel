<?php

use App\Jobs\ConvertirAdressesImportees;
use App\Models\Adresse;
use App\Models\Annotation;
use App\Models\Bibliotheque;
use App\Models\Collection;
use App\Models\MarquePage;
use App\Models\User;
use App\Services\Membre\Donnees\ConvertisseurDAdresses;
use Babel\Moteur\Moteur;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/Aide.php';

/*
 * La tâche de fond de l'import (M12, règle 20) : lire la page chez l'origine, la retrouver ici avec « chercher »
 * en mode « page », puis réécrire marque-pages, pages de collection et annotations.
 */
beforeEach(function () {
    $this->biblios = emBibliotheques();
    $this->membre = User::factory()->create();
});

/** Le texte que « babel-5 » répondrait pour cette page (ici le même moteur, donc la même clé : la page est la même). */
function emTexteDistant(string $adresse): string
{
    return app(Moteur::class)->lire($adresse);
}

/** Ce que répond « babel-5 » à partir de maintenant (une nouvelle réponse REMPLACE la précédente). */
function emOrigine(mixed $reponse): void
{
    Http::swap(new Factory);
    Http::fake(['babel-5.example.test/*' => $reponse]);
}

/** Une origine en ligne : elle répond avec le texte des pages demandées. */
function emOrigineEnLigne(): void
{
    emOrigine(function (Request $requete) {
        $adresse = rawurldecode(basename($requete->url()));

        return Http::response(['bibliotheque' => 'babel-5', 'adresse' => $adresse, 'texte' => emTexteDistant($adresse)]);
    });
}

/** Les lignes « à convertir » d'un membre : un marque-page, une annotation et une page de collection sur la même page d'ailleurs. */
function emImporte(User $membre, string $adresse = '1-w1-s1-v1:1'): Adresse
{
    $ancienne = emAdresse($adresse, emBibliotheques()['autre']);
    MarquePage::factory()->create(['utilisateur_id' => $membre->id, 'adresse_id' => $ancienne->id, 'titre' => 'Importé', 'a_convertir' => true]);
    Annotation::factory()->create(['utilisateur_id' => $membre->id, 'adresse_id' => $ancienne->id, 'a_convertir' => true]);
    $collection = Collection::factory()->create(['utilisateur_id' => $membre->id]);
    $collection->pages()->create(['position' => 1, 'adresse_id' => $ancienne->id, 'a_convertir' => true]);

    return $ancienne;
}

it('convertit les adresses : la page est lue chez l\'origine puis retrouvée ici', function () {
    emOrigineEnLigne();
    $ancienne = emImporte($this->membre);

    $converties = app(ConvertisseurDAdresses::class)->convertir($this->membre);

    expect($converties)->toBe(1);
    $locale = $this->biblios['locale'];
    $nouvelle = Adresse::query()->where('bibliotheque_id', $locale->id)->where('empreinte', hash('sha256', '1-w1-s1-v1:1'))->firstOrFail();

    $marquePage = $this->membre->marquePages()->first();
    expect($marquePage->adresse_id)->toBe($nouvelle->id)->and($marquePage->a_convertir)->toBeFalse()->and($marquePage->titre)->toBe('Importé');
    expect($this->membre->annotations()->first())->adresse_id->toBe($nouvelle->id)->a_convertir->toBeFalse();
    $page = DB::table('collection_pages')->first();
    expect($page->adresse_id)->toBe($nouvelle->id)->and((bool) $page->a_convertir)->toBeFalse()->and($page->position)->toBe(1);
    expect($nouvelle->id)->not->toBe($ancienne->id);
});

it('appelle la porte réseau de l\'origine (GET /api/v1/pages/{adresse}) et rien d\'autre', function () {
    emOrigineEnLigne();
    emImporte($this->membre);

    app(ConvertisseurDAdresses::class)->convertir($this->membre);

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $r) => $r->method() === 'GET' && str_starts_with($r->url(), 'https://babel-5.example.test/api/v1/pages/1-w1-s1-v1'));
});

it('ne change RIEN tant que l\'origine ne répond pas, et date la tentative', function () {
    emOrigine(Http::response('', 503));
    $ancienne = emImporte($this->membre);

    expect(app(ConvertisseurDAdresses::class)->convertir($this->membre))->toBe(0);

    expect($this->membre->marquePages()->first())->adresse_id->toBe($ancienne->id)->a_convertir->toBeTrue();
    expect(app(ConvertisseurDAdresses::class)->restantes($this->membre))->toBe(1);
    expect($this->membre->fresh()->import_tentative_le)->not->toBeNull();
});

it('ne change rien non plus quand l\'origine est injoignable ou répond hors contrat', function () {
    $ancienne = emImporte($this->membre);

    emOrigine(fn () => throw new ConnectionException('éteint'));
    expect(app(ConvertisseurDAdresses::class)->convertir($this->membre))->toBe(0);

    emOrigine(Http::response(['texte' => 'trop court']));
    expect(app(ConvertisseurDAdresses::class)->convertir($this->membre))->toBe(0);

    expect($this->membre->marquePages()->first()->adresse_id)->toBe($ancienne->id);
});

it('retente au passage suivant : l\'origine revient, la conversion aboutit', function () {
    emOrigine(Http::response('', 500));
    emImporte($this->membre);
    app(ConvertisseurDAdresses::class)->convertir($this->membre);
    expect(app(ConvertisseurDAdresses::class)->restantes($this->membre))->toBe(1);

    emOrigineEnLigne();
    app(ConvertisseurDAdresses::class)->convertir($this->membre);

    expect(app(ConvertisseurDAdresses::class)->restantes($this->membre))->toBe(0);
});

it('FUSIONNE les marque-pages quand la conversion fait apparaître deux fois la même page (règle 20)', function () {
    emOrigineEnLigne();
    // Le membre garde déjà la page d'ici, et a importé la même page depuis l'origine.
    MarquePage::factory()->create(['utilisateur_id' => $this->membre->id, 'adresse_id' => emAdresse('1-w1-s1-v1:1')->id, 'titre' => 'Déjà là']);
    emImporte($this->membre);

    app(ConvertisseurDAdresses::class)->convertir($this->membre);

    expect($this->membre->marquePages()->count())->toBe(1);
    expect($this->membre->marquePages()->first()->titre)->toBe('Déjà là');
    // Les annotations, elles, ne fusionnent pas : toutes sont gardées.
    expect($this->membre->annotations()->count())->toBe(1);
});

it('ne garde qu\'un marque-page quand deux adresses d\'origine différentes mènent à la même page ici', function () {
    // Deux bibliothèques qui ont, chacune à son adresse, la même page : leurs deux marque-pages se retrouvent ici sur UNE page.
    $babel6 = Bibliotheque::factory()->create(['identifiant' => 'babel-6', 'url_actuelle' => 'https://babel-6.example.test']);
    Http::swap(new Factory);
    Http::fake(['*' => fn () => Http::response(['texte' => emTexteDistant('1-w1-s1-v1:1')])]);
    foreach ([emAdresse('7-w1-s1-v1:1', $this->biblios['autre']), emAdresse('8-w1-s1-v1:1', $babel6)] as $i => $ancienne) {
        MarquePage::factory()->create(['utilisateur_id' => $this->membre->id, 'adresse_id' => $ancienne->id, 'a_convertir' => true, 'titre' => "Importé $i"]);
    }

    $converties = app(ConvertisseurDAdresses::class)->convertir($this->membre);

    expect($converties)->toBe(2);
    expect($this->membre->marquePages()->count())->toBe(1);
    expect($this->membre->marquePages()->first()->a_convertir)->toBeFalse();
});

it('ne touche jamais aux lignes d\'un autre membre', function () {
    emOrigineEnLigne();
    $autre = User::factory()->create();
    $ancienne = emImporte($this->membre);
    MarquePage::factory()->create(['utilisateur_id' => $autre->id, 'adresse_id' => $ancienne->id, 'a_convertir' => true]);

    app(ConvertisseurDAdresses::class)->convertir($this->membre);

    expect($autre->marquePages()->first())->adresse_id->toBe($ancienne->id)->a_convertir->toBeTrue();
});

it('ne fait rien quand il n\'y a rien à convertir : pas d\'appel réseau', function () {
    Http::fake();

    expect(app(ConvertisseurDAdresses::class)->convertir($this->membre))->toBe(0);

    Http::assertNothingSent();
    expect($this->membre->fresh()->import_tentative_le)->toBeNull();
});

it('refuse d\'appeler une URL qui n\'est pas http(s) : l\'annuaire est une source extérieure', function () {
    Http::fake();
    $this->biblios['autre']->update(['url_actuelle' => 'file:///etc/passwd']);
    emImporte($this->membre);

    expect(app(ConvertisseurDAdresses::class)->convertir($this->membre))->toBe(0);

    Http::assertNothingSent();
});

it('la tâche de fond (job) convertit pour le bon membre, et ignore un compte supprimé', function () {
    emOrigineEnLigne();
    emImporte($this->membre);

    (new ConvertirAdressesImportees($this->membre->id))->handle(app(ConvertisseurDAdresses::class));
    (new ConvertirAdressesImportees(999999))->handle(app(ConvertisseurDAdresses::class));

    expect(app(ConvertisseurDAdresses::class)->restantes($this->membre))->toBe(0);
});

it('babel:convertir-import relance les membres qui ont encore des adresses, sauf ceux essayés il y a moins de 10 minutes', function () {
    Queue::fake();
    $recent = User::factory()->create(['import_tentative_le' => now()->subMinutes(2)]);
    $ancien = User::factory()->create(['import_tentative_le' => now()->subMinutes(30)]);
    $jamais = User::factory()->create();
    $fini = User::factory()->create();
    foreach ([$recent, $ancien, $jamais] as $membre) {
        emImporte($membre, '1-w1-s1-v1:'.$membre->id);
    }

    $this->artisan('babel:convertir-import')->assertSuccessful();

    Queue::assertPushed(ConvertirAdressesImportees::class, 2);
    Queue::assertPushed(ConvertirAdressesImportees::class, fn ($job) => $job->utilisateurId === $ancien->id);
    Queue::assertPushed(ConvertirAdressesImportees::class, fn ($job) => $job->utilisateurId === $jamais->id);
    Queue::assertNotPushed(ConvertirAdressesImportees::class, fn ($job) => in_array($job->utilisateurId, [$recent->id, $fini->id], true));
});

it('est planifiée toutes les 10 minutes', function () {
    $evenements = collect(app(Schedule::class)->events())
        ->filter(fn ($e) => str_contains($e->command, 'babel:convertir-import'));

    expect($evenements)->toHaveCount(1)->and($evenements->first()->expression)->toBe('*/10 * * * *');
});
