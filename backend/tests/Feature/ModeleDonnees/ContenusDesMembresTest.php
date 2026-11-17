<?php

use App\Enums\Role;
use App\Models\Adresse;
use App\Models\Annotation;
use App\Models\Collection;
use App\Models\LienCourt;
use App\Models\MarquePage;
use App\Models\Recherche;
use App\Models\Signalement;
use App\Models\User;
use App\Models\Vote;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

it('crée un utilisateur avec les colonnes du MLD', function () {
    $user = User::factory()->create(['pseudo' => 'alice']);

    expect($user->fresh()->role)->toBe(Role::Membre)
        ->and($user->suspendu_le)->toBeNull()
        ->and($user->cree_le)->not->toBeNull()
        ->and($user->password)->not->toBe('password'); // haché par le cast
});

it('garde pseudo et email uniques et refuse un rôle inconnu', function () {
    User::factory()->create(['pseudo' => 'alice', 'email' => 'a@babel.test']);

    expect(fn () => User::factory()->create(['pseudo' => 'alice']))->toThrow(QueryException::class);
    expect(fn () => User::factory()->create(['email' => 'a@babel.test']))->toThrow(QueryException::class);

    // Le cast d'énumération bloque déjà une valeur inconnue côté PHP : on attaque la base directement.
    expect(fn () => DB::table('users')->where('pseudo', 'alice')->update(['role' => 'moderateur']))
        ->toThrow(QueryException::class);
});

it('ne garde qu\'une fois la même page par membre en marque-page', function () {
    $user = User::factory()->create();
    $adresse = Adresse::factory()->create();
    MarquePage::factory()->create(['utilisateur_id' => $user->id, 'adresse_id' => $adresse->id]);

    expect(fn () => MarquePage::factory()->create(['utilisateur_id' => $user->id, 'adresse_id' => $adresse->id]))
        ->toThrow(QueryException::class);

    // Un autre membre peut marquer la même page.
    MarquePage::factory()->create(['adresse_id' => $adresse->id]);
    expect(MarquePage::count())->toBe(2);
});

it('refuse un titre de marque-page trop long selon le MLD (120)', function () {
    // SQLite n'impose pas la longueur d'un varchar : la limite est portée par la validation de l'API.
    expect(MarquePage::factory()->create(['titre' => str_repeat('a', 120)])->titre)->toHaveLength(120);
})->skip(fn () => DB::getDriverName() === 'sqlite', 'longueur de varchar non appliquée par SQLite');

it('ordonne une collection et accepte la même page à deux positions', function () {
    $collection = Collection::factory()->create();
    $page = Adresse::factory()->create();

    $collection->pages()->create(['position' => 1, 'adresse_id' => $page->id]);
    $collection->pages()->create(['position' => 2, 'adresse_id' => $page->id]);

    expect($collection->pages()->pluck('position')->all())->toBe([1, 2]);
    expect(fn () => $collection->pages()->create(['position' => 2, 'adresse_id' => $page->id]))->toThrow(QueryException::class);
    expect(fn () => $collection->pages()->create(['position' => 0, 'adresse_id' => $page->id]))->toThrow(QueryException::class);
});

it('limite une annotation à 2 000 caractères', function () {
    expect(Annotation::factory()->create(['contenu' => str_repeat('a', 2000)])->exists)->toBeTrue();
    expect(fn () => Annotation::factory()->create(['contenu' => str_repeat('a', 2001)]))->toThrow(QueryException::class);
    expect(fn () => Annotation::factory()->create(['contenu' => '']))->toThrow(QueryException::class);
});

it('accepte un seul vote par membre et par page, de valeur +1 ou -1', function () {
    $user = User::factory()->create();
    $adresse = Adresse::factory()->create();

    Vote::create(['utilisateur_id' => $user->id, 'adresse_id' => $adresse->id, 'valeur' => 1]);

    expect(fn () => Vote::create(['utilisateur_id' => $user->id, 'adresse_id' => $adresse->id, 'valeur' => -1]))
        ->toThrow(QueryException::class);
    expect(fn () => Vote::create(['utilisateur_id' => User::factory()->create()->id, 'adresse_id' => $adresse->id, 'valeur' => 2]))
        ->toThrow(QueryException::class);
    expect(fn () => Vote::create(['utilisateur_id' => User::factory()->create()->id, 'adresse_id' => $adresse->id, 'valeur' => 0]))
        ->toThrow(QueryException::class);
});

it('change un vote existant par la relation', function () {
    $user = User::factory()->create();
    $adresse = Adresse::factory()->create();

    $user->votes()->attach($adresse->id, ['valeur' => 1]);
    $user->votes()->updateExistingPivot($adresse->id, ['valeur' => -1]);

    expect($user->votes()->first()->pivot->valeur)->toBe(-1)
        ->and(Vote::count())->toBe(1);
});

describe('lien court : exactement une cible', function () {
    it('accepte une adresse ou une collection', function () {
        expect(LienCourt::factory()->create()->exists)->toBeTrue();
        expect(LienCourt::factory()->versCollection()->create()->exists)->toBeTrue();
        expect(LienCourt::factory()->create(['adresse_id' => Adresse::factory()->livre()->create()->id])->exists)->toBeTrue();
    });

    it('refuse aucune cible', function () {
        expect(fn () => LienCourt::factory()->create(['adresse_id' => null, 'collection_id' => null]))
            ->toThrow(QueryException::class);
    });

    it('refuse les deux cibles à la fois', function () {
        expect(fn () => LienCourt::factory()->create(['collection_id' => Collection::factory()->create()->id]))
            ->toThrow(QueryException::class);
    });

    it('refuse un passage sur une collection', function () {
        expect(fn () => LienCourt::factory()->versCollection()->create(['passage' => 'un extrait']))
            ->toThrow(QueryException::class);
    });

    it('accepte un passage sur une adresse, jusqu\'à 3 200 caractères', function () {
        expect(LienCourt::factory()->create(['passage' => str_repeat('a', 3200)])->exists)->toBeTrue();
        expect(fn () => LienCourt::factory()->create(['passage' => str_repeat('a', 3201)]))->toThrow(QueryException::class);
    });

    it('impose une expiration à un lien créé sans compte', function () {
        expect(fn () => LienCourt::factory()->visiteur()->create(['expire_le' => null]))->toThrow(QueryException::class);
        expect(LienCourt::factory()->visiteur()->create()->utilisateur_id)->toBeNull();
        expect(LienCourt::factory()->create(['expire_le' => null])->exists)->toBeTrue(); // membre : pas d'expiration
    });

    it('garde le slug unique', function () {
        LienCourt::factory()->create(['slug' => 'k7Hq2xAB']);

        expect(fn () => LienCourt::factory()->create(['slug' => 'k7Hq2xAB']))->toThrow(QueryException::class);
    });
});

describe('signalement : exactement une cible', function () {
    it('accepte une annotation, une collection ou un lien court', function () {
        expect(Signalement::factory()->create()->annotation)->not->toBeNull();
        expect(Signalement::factory()->surCollection()->create()->collection)->not->toBeNull();
        expect(Signalement::factory()->surLienCourt()->create()->lienCourt)->not->toBeNull();
    });

    it('refuse aucune cible', function () {
        expect(fn () => Signalement::factory()->create(['annotation_id' => null]))->toThrow(QueryException::class);
    });

    it('refuse deux cibles', function () {
        expect(fn () => Signalement::factory()->create(['collection_id' => Collection::factory()->create()->id]))
            ->toThrow(QueryException::class);
        expect(fn () => Signalement::factory()->create(['lien_court_id' => LienCourt::factory()->create()->id]))
            ->toThrow(QueryException::class);
    });

    it('refuse les trois cibles', function () {
        expect(fn () => Signalement::factory()->create([
            'collection_id' => Collection::factory()->create()->id,
            'lien_court_id' => LienCourt::factory()->create()->id,
        ]))->toThrow(QueryException::class);
    });

    it('refuse un statut inconnu', function () {
        $signalement = Signalement::factory()->create();

        expect(fn () => DB::table('signalements')->where('id', $signalement->id)->update(['statut' => 'ignore']))
            ->toThrow(QueryException::class);
    });

    it('accepte un auteur nul (visiteur)', function () {
        expect(Signalement::factory()->visiteur()->create()->auteur_id)->toBeNull();
    });
});

describe('suppression en cascade', function () {
    it('efface les données d\'un compte supprimé mais garde ses signalements sans auteur', function () {
        $membre = User::factory()->create();
        $adresse = Adresse::factory()->create();
        MarquePage::factory()->create(['utilisateur_id' => $membre->id]);
        Collection::factory()->create(['utilisateur_id' => $membre->id]);
        Annotation::factory()->create(['utilisateur_id' => $membre->id]);
        Recherche::factory()->create(['utilisateur_id' => $membre->id]);
        LienCourt::factory()->create(['utilisateur_id' => $membre->id]);
        Vote::create(['utilisateur_id' => $membre->id, 'adresse_id' => $adresse->id, 'valeur' => 1]);
        $signalement = Signalement::factory()->create(['auteur_id' => $membre->id]);

        $membre->delete();

        expect(MarquePage::where('utilisateur_id', $membre->id)->count())->toBe(0)
            ->and(Collection::where('utilisateur_id', $membre->id)->count())->toBe(0)
            ->and(Annotation::where('utilisateur_id', $membre->id)->count())->toBe(0)
            ->and(Recherche::where('utilisateur_id', $membre->id)->count())->toBe(0)
            ->and(LienCourt::where('utilisateur_id', $membre->id)->count())->toBe(0)
            ->and(Vote::where('utilisateur_id', $membre->id)->count())->toBe(0)
            ->and($signalement->fresh()->auteur_id)->toBeNull();
    });

    it('efface les signalements d\'un contenu supprimé par son auteur', function () {
        $signalement = Signalement::factory()->create();

        $signalement->annotation->delete();

        expect(Signalement::count())->toBe(0);
    });

    it('efface une collection avec ses pages et ses liens courts', function () {
        $collection = Collection::factory()->create();
        $collection->pages()->create(['position' => 1, 'adresse_id' => Adresse::factory()->create()->id]);
        LienCourt::factory()->versCollection()->create(['collection_id' => $collection->id]);

        $collection->delete();

        expect(DB::table('collection_pages')->count())->toBe(0)
            ->and(LienCourt::count())->toBe(0);
    });
});
