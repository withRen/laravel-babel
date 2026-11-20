<?php

use App\Enums\Role;
use App\Enums\StatutSignalement;
use App\Enums\StatutTexteBanni;
use App\Filament\Pages\Reglages;
use App\Filament\Resources\Reseau\Pages\ListBibliotheques;
use App\Filament\Resources\Signalements\Pages\ListSignalements;
use App\Filament\Resources\TextesBannis\Pages\ListTextesBannis;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\Adresse;
use App\Models\Annotation;
use App\Models\Bibliotheque;
use App\Models\Reglage;
use App\Models\Signalement;
use App\Models\TexteBanni;
use App\Models\User;
use Babel\Moteur\Moteur;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Cache;

function livewire(string $composant, array $params = [])
{
    return Livewire\Livewire::test($composant, $params);
}

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->actingAs($this->admin);
    Filament::setCurrentPanel('admin');
    $this->locale = Bibliotheque::factory()->locale()->create();
});

/** Enregistre ici l'adresse de la page trouvée par le moteur pour ce texte. */
function adresseTrouvee(string $texte, Bibliotheque $locale, string $mode = 'exact'): Adresse
{
    $ecriture = app(Moteur::class)->chercher($texte, $mode);
    preg_match('/^(.+)-w(\d+)-s(\d+)-v(\d+):(\d+)$/', $ecriture, $m);

    return Adresse::create([
        'bibliotheque_id' => $locale->id, 'hexagone' => $m[1], 'mur' => $m[2], 'etagere' => $m[3], 'volume' => $m[4], 'page' => $m[5],
    ]);
}

// --- Accès ---

it('refuse le back-office à un membre et laisse entrer un administrateur', function () {
    auth()->logout();
    $this->actingAs(User::factory()->create())->get('/admin/comptes')->assertForbidden();
    $this->actingAs($this->admin)->get('/admin/comptes')->assertOk();
    foreach (['signalements', 'textes-bannis', 'reseau', 'reglages', ''] as $page) {
        $this->get('/admin/'.$page)->assertOk();
    }
});

// --- A1, A2, A3 : comptes ---

it('liste les comptes et filtre les suspendus (A1)', function () {
    $actif = User::factory()->create();
    $suspendu = User::factory()->suspendu()->create();

    livewire(ListUsers::class)->assertCanSeeTableRecords([$actif, $suspendu])
        ->filterTable('statut', 'suspendu')->assertCanSeeTableRecords([$suspendu])->assertCanNotSeeTableRecords([$actif]);
});

it('suspend puis rétablit un compte et révoque ses jetons (A2)', function () {
    $membre = User::factory()->create();
    $membre->createToken('app');

    livewire(ListUsers::class)->callAction(TestAction::make('suspendre')->table($membre));
    expect($membre->fresh()->estSuspendu())->toBeTrue()->and($membre->tokens()->count())->toBe(0);

    livewire(ListUsers::class)->callAction(TestAction::make('retablir')->table($membre));
    expect($membre->fresh()->estSuspendu())->toBeFalse();
});

it('supprime un compte avec ses données et garde ses signalements sans auteur (A2)', function () {
    $membre = User::factory()->create();
    $annotation = Annotation::factory()->publique()->create();
    $signalement = Signalement::factory()->create(['annotation_id' => $annotation->id, 'auteur_id' => $membre->id]);
    $annotationDuMembre = Annotation::factory()->create(['utilisateur_id' => $membre->id]);

    livewire(ListUsers::class)->callAction(TestAction::make('delete')->table($membre));

    expect(User::find($membre->id))->toBeNull()
        ->and(Annotation::find($annotationDuMembre->id))->toBeNull()
        ->and($signalement->fresh()->auteur_id)->toBeNull();
});

it('promeut un membre administrateur (A3)', function () {
    $membre = User::factory()->create();

    livewire(ListUsers::class)->callAction(TestAction::make('promouvoir')->table($membre));

    expect($membre->fresh()->role)->toBe(Role::Admin);
});

it('ne laisse un administrateur ni se suspendre ni se supprimer, ni supprimer un autre administrateur', function () {
    $autreAdmin = User::factory()->admin()->create();

    livewire(ListUsers::class)
        ->assertActionHidden(TestAction::make('suspendre')->table($this->admin))
        ->assertActionHidden(TestAction::make('delete')->table($this->admin))
        ->assertActionHidden(TestAction::make('delete')->table($autreAdmin))
        ->assertActionHidden(TestAction::make('promouvoir')->table($autreAdmin));
});

// --- A4 : signalements ---

it('masque le contenu signalé et note qui a décidé (A4, règle 14)', function () {
    $signalement = Signalement::factory()->create();

    livewire(ListSignalements::class)->callAction(TestAction::make('masquer')->table($signalement));

    $signalement->refresh();
    expect($signalement->statut)->toBe(StatutSignalement::Traite)
        ->and($signalement->traite_par_id)->toBe($this->admin->id)
        ->and($signalement->traite_le)->not->toBeNull()
        ->and($signalement->annotation->masque_le)->not->toBeNull();
});

it('rétablit un contenu masqué : la date repart à nul', function () {
    $signalement = Signalement::factory()->create();
    $signalement->annotation->update(['masque_le' => now()]);

    livewire(ListSignalements::class)
        ->filterTable('statut', null)
        ->callAction(TestAction::make('retablir')->table($signalement));

    expect($signalement->fresh()->annotation->masque_le)->toBeNull()
        ->and($signalement->fresh()->statut)->toBe(StatutSignalement::Rejete);
});

it('rejette un signalement sans toucher au contenu', function () {
    $signalement = Signalement::factory()->surCollection()->create();

    livewire(ListSignalements::class)->callAction(TestAction::make('rejeter')->table($signalement));

    expect($signalement->fresh()->statut)->toBe(StatutSignalement::Rejete)
        ->and($signalement->fresh()->collection->masque_le)->toBeNull();
});

it('affiche par défaut la file en attente seulement', function () {
    $attente = Signalement::factory()->create();
    $traite = Signalement::factory()->create(['statut' => StatutSignalement::Traite]);

    livewire(ListSignalements::class)->assertCanSeeTableRecords([$attente])->assertCanNotSeeTableRecords([$traite]);
});

it('traite aussi un signalement de lien court', function () {
    $signalement = Signalement::factory()->surLienCourt()->create();

    livewire(ListSignalements::class)->callAction(TestAction::make('masquer')->table($signalement));

    expect($signalement->fresh()->lienCourt->masque_le)->not->toBeNull();
});

// --- A5, A7 : textes bannis ---

it('déclare un texte banni : normalisé, appliqué, local, et marque la page enregistrée (A5)', function () {
    $adresse = adresseTrouvee('bonjour babel', $this->locale);
    $autre = Adresse::factory()->create(['bibliotheque_id' => $this->locale->id]);

    livewire(ListTextesBannis::class)
        ->callAction('declarer', ['texte' => 'Bonjour   BABEL !', 'motif' => 'test'])
        ->assertNotified();

    $texte = TexteBanni::sole();
    expect($texte->texte)->toBe('bonjour babel')
        ->and($texte->statut)->toBe(StatutTexteBanni::Applique)
        ->and($texte->origine_id)->toBe($this->locale->id)
        ->and($texte->empreinte)->toBe(hash('sha256', 'bonjour babel'))
        ->and($adresse->fresh()->texte_banni_id)->toBe($texte->id)
        ->and($autre->fresh()->texte_banni_id)->toBeNull();
});

it('marque aussi la page trouvée en mode bruit', function () {
    $adresse = adresseTrouvee('bonjour babel', $this->locale, 'bruit');

    livewire(ListTextesBannis::class)->callAction('declarer', ['texte' => 'bonjour babel']);

    expect($adresse->fresh()->texte_banni_id)->not->toBeNull();
});

it('marque le livre enregistré qui contient la page trouvée', function () {
    $page = adresseTrouvee('bonjour babel', $this->locale);
    $livre = Adresse::create([
        'bibliotheque_id' => $this->locale->id, 'hexagone' => $page->hexagone, 'mur' => $page->mur,
        'etagere' => $page->etagere, 'volume' => $page->volume, 'page' => null,
    ]);

    livewire(ListTextesBannis::class)->callAction('declarer', ['texte' => 'bonjour babel']);

    expect($livre->fresh()->texte_banni_id)->not->toBeNull();
});

it('refuse un texte vide une fois normalisé', function () {
    livewire(ListTextesBannis::class)->callAction('declarer', ['texte' => '12345 !?'])->assertNotified('Texte refusé');

    expect(TexteBanni::count())->toBe(0);
});

it('reprend un texte déjà connu au lieu d\'en créer un second', function () {
    $propose = TexteBanni::factory()->create(['texte' => 'un texte', 'origine_id' => Bibliotheque::factory()->enLigne()]);

    livewire(ListTextesBannis::class)->callAction('declarer', ['texte' => 'Un  texte']);

    expect(TexteBanni::count())->toBe(1)->and($propose->fresh()->statut)->toBe(StatutTexteBanni::Applique);
});

it('applique un texte proposé par un pair (A7), sans le publier au réseau', function () {
    $adresse = adresseTrouvee('bonjour babel', $this->locale);
    $propose = TexteBanni::factory()->create(['texte' => 'bonjour babel', 'origine_id' => Bibliotheque::factory()->enLigne()]);

    livewire(ListTextesBannis::class)->callAction(TestAction::make('appliquer')->table($propose));

    expect($propose->fresh()->statut)->toBe(StatutTexteBanni::Applique)
        ->and($adresse->fresh()->texte_banni_id)->toBe($propose->id);

    config(['babel.network_token' => 'jeton']);
    $this->withToken('jeton')->getJson('/api/v1/textes-bannis')->assertOk()->assertJsonCount(0, 'elements');
});

it('rejette un texte proposé : rien n\'est marqué (A7)', function () {
    $adresse = adresseTrouvee('bonjour babel', $this->locale);
    $propose = TexteBanni::factory()->create(['texte' => 'bonjour babel', 'origine_id' => Bibliotheque::factory()->enLigne()]);

    livewire(ListTextesBannis::class)->callAction(TestAction::make('rejeter')->table($propose));

    expect($propose->fresh()->statut)->toBe(StatutTexteBanni::Rejete)->and($adresse->fresh()->texte_banni_id)->toBeNull();
});

it('lève un bannissement : les marques partent et le texte n\'est plus publié (A5)', function () {
    $adresse = adresseTrouvee('bonjour babel', $this->locale);
    livewire(ListTextesBannis::class)->callAction('declarer', ['texte' => 'bonjour babel']);
    expect($adresse->fresh()->texte_banni_id)->not->toBeNull();

    livewire(ListTextesBannis::class)->callAction(TestAction::make('rejeter')->table(TexteBanni::sole()));

    expect($adresse->fresh()->texte_banni_id)->toBeNull()->and(TexteBanni::sole()->statut)->toBe(StatutTexteBanni::Rejete);

    config(['babel.network_token' => 'jeton']);
    $this->withToken('jeton')->getJson('/api/v1/textes-bannis')->assertJsonCount(0, 'elements');
});

it('revérifie une page libérée contre les autres textes appliqués (levée)', function () {
    // « bonjour babel » est la page exacte ; un second texte (un morceau de cette page) la marque aussi.
    $adresse = adresseTrouvee('bonjour babel', $this->locale);
    $morceau = TexteBanni::factory()->applique()->create(['texte' => 'bonjour babel', 'origine_id' => $this->locale->id]);
    $autre = TexteBanni::factory()->applique()->create(['texte' => 'bonjour', 'origine_id' => $this->locale->id]);
    $adresse->update(['texte_banni_id' => $morceau->id]);

    livewire(ListTextesBannis::class)->callAction(TestAction::make('rejeter')->table($morceau));

    expect($adresse->fresh()->texte_banni_id)->toBe($autre->id);
});

it('la tâche de fond marque une page enregistrée qui contient un texte appliqué', function () {
    $adresse = adresseTrouvee('bonjour babel', $this->locale);
    $texte = TexteBanni::factory()->applique()->create(['texte' => 'bonjour babel', 'origine_id' => $this->locale->id]);

    $this->artisan('babel:verifier-bannis')->expectsOutputToContain('1 marquée(s)')->assertSuccessful();

    expect($adresse->fresh()->texte_banni_id)->toBe($texte->id);
});

// --- A8, A10 : réglages ---

it('enregistre le nom, la description, les inscriptions et les quotas (A8, A10)', function () {
    livewire(Reglages::class)
        ->fillForm(['nom' => 'Babel Béziers', 'description' => 'Notre bibliothèque', 'inscriptions_ouvertes' => false,
            'quota_api_publique' => 90, 'quota_lecture' => 200, 'quota_publication' => 5])
        ->call('save')->assertHasNoFormErrors();

    expect(Bibliotheque::locale()->nom)->toBe('Babel Béziers')
        ->and(Reglage::inscriptionsOuvertes())->toBeFalse()
        ->and(Reglage::quota('quota_api_publique'))->toBe(90)
        ->and(Reglage::quota('quota_lecture'))->toBe(200)
        ->and(Reglage::quota('quota_publication'))->toBe(5);

    $this->postJson('/api/v1/app/auth/inscription', ['pseudo' => 'nouveau', 'email' => 'n@x.fr', 'mot_de_passe' => 'unmotdepasse123'])
        ->assertForbidden();
});

it('refuse un quota réseau sous le plancher du contrat (60)', function () {
    livewire(Reglages::class)->fillForm(['quota_api_publique' => 10])->call('save')
        ->assertHasFormErrors(['quota_api_publique']);
});

it('lit les quotas en base avec des valeurs par défaut sûres (A10)', function () {
    expect(Reglage::quota('quota_api_publique'))->toBe(60)->and(Reglage::quota('quota_lecture'))->toBe(120)
        ->and(Reglage::quota('quota_publication'))->toBe(20);

    foreach (['abc', '0', '-5', ''] as $mauvais) {
        Reglage::definir('quota_lecture', $mauvais);
        expect(Reglage::quota('quota_lecture'))->toBe(120);
    }

    Reglage::definir('quota_api_publique', '10'); // jamais sous 60 : le contrat
    expect(Reglage::quota('quota_api_publique'))->toBe(60);
});

it('applique le quota de la porte réseau lu en base', function () {
    Reglage::definir('quota_api_publique', '61');
    Cache::flush();

    foreach (range(1, 61) as $i) {
        $this->getJson('/api/v1/identite')->assertSuccessful();
    }
    $this->getJson('/api/v1/identite')->assertStatus(429);
});

// --- A9, A11 ---

it('affiche l\'état du réseau : pairs et clés changées (A9)', function () {
    $pair = Bibliotheque::factory()->create(['est_locale' => false, 'cle_changee_le' => now()]);

    livewire(ListBibliotheques::class)->assertCanSeeTableRecords([$pair])->assertCanNotSeeTableRecords([$this->locale])
        ->filterTable('cle_changee', ['isActive' => true])->assertCanSeeTableRecords([$pair]);
});
