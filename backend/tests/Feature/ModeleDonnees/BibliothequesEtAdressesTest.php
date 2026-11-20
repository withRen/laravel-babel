<?php

use App\Models\Adresse;
use App\Models\Bibliotheque;
use App\Models\TexteBanni;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

it('n\'accepte qu\'une seule bibliothèque locale', function () {
    Bibliotheque::factory()->locale()->create();
    Bibliotheque::factory()->create(); // plusieurs lignes non locales : autorisé

    expect(fn () => Bibliotheque::factory()->locale()->create())->toThrow(QueryException::class);
    expect(Bibliotheque::where('est_locale', true)->count())->toBe(1);
});

it('garde l\'identifiant d\'une bibliothèque unique et refuse un état inconnu', function () {
    Bibliotheque::factory()->create(['identifiant' => 'voisine']);

    expect(fn () => Bibliotheque::factory()->create(['identifiant' => 'voisine']))->toThrow(QueryException::class);
    expect(fn () => DB::table('bibliotheques')->insert([
        'identifiant' => 'x1', 'nom' => 'X', 'url_actuelle' => 'https://x.test', 'etat' => 'cassee',
    ]))->toThrow(QueryException::class);
});

it('calcule les deux empreintes SHA-256 d\'une adresse', function () {
    $adresse = Adresse::factory()->create(['hexagone' => 'babel', 'mur' => 2, 'etagere' => 3, 'volume' => 17, 'page' => 204]);

    expect($adresse->ecriture())->toBe('babel-w2-s3-v17:204')
        ->and($adresse->empreinte)->toBe(hash('sha256', 'babel-w2-s3-v17:204'))
        ->and($adresse->empreinte_livre)->toBe(hash('sha256', 'babel-w2-s3-v17'));
});

it('enregistre une adresse une seule fois par bibliothèque', function () {
    $biblio = Bibliotheque::factory()->create();
    $donnees = ['bibliotheque_id' => $biblio->id, 'hexagone' => 'abc', 'mur' => 1, 'etagere' => 1, 'volume' => 1, 'page' => 1];
    Adresse::factory()->create($donnees);

    expect(fn () => Adresse::factory()->create($donnees))->toThrow(QueryException::class);

    // Même adresse écrite, autre bibliothèque : c'est une autre ligne.
    Adresse::factory()->create([...$donnees, 'bibliotheque_id' => Bibliotheque::factory()->create()->id]);
    expect(Adresse::count())->toBe(2);
});

it('distingue un livre entier de ses pages', function () {
    $biblio = Bibliotheque::factory()->create();
    $base = ['bibliotheque_id' => $biblio->id, 'hexagone' => 'abc', 'mur' => 1, 'etagere' => 1, 'volume' => 1];

    $livre = Adresse::factory()->create([...$base, 'page' => null]);
    $page = Adresse::factory()->create([...$base, 'page' => 1]);

    expect($livre->estLivre())->toBeTrue()
        ->and($livre->empreinte_livre)->toBe($page->empreinte_livre)
        ->and($livre->empreinte)->not->toBe($page->empreinte);
});

it('refuse les coordonnées hors des bornes', function (array $mauvais) {
    expect(fn () => Adresse::factory()->create($mauvais))->toThrow(QueryException::class);
})->with([
    'mur 0' => [['mur' => 0]],
    'mur 5' => [['mur' => 5]],
    'étagère 6' => [['etagere' => 6]],
    'volume 33' => [['volume' => 33]],
    'page 0' => [['page' => 0]],
    'page 411' => [['page' => 411]],
    'hexagone vide' => [['hexagone' => '']],
    'hexagone de 3 005 caractères' => [['hexagone' => str_repeat('a', 3005)]],
]);

it('accepte les bornes extrêmes, dont un hexagone de 3 004 caractères', function () {
    $adresse = Adresse::factory()->create([
        'hexagone' => str_repeat('z', 3004), 'mur' => 4, 'etagere' => 5, 'volume' => 32, 'page' => 410,
    ]);

    expect(strlen($adresse->fresh()->hexagone))->toBe(3004);
});

it('garde l\'empreinte d\'un texte banni unique et calculée', function () {
    $texte = TexteBanni::factory()->create(['texte' => 'un texte banni, normalise.']);

    expect($texte->empreinte)->toBe(hash('sha256', 'un texte banni, normalise.'));
    expect(fn () => TexteBanni::factory()->create(['texte' => 'un texte banni, normalise.']))->toThrow(QueryException::class);
});

it('refuse un texte banni de plus de 3 200 caractères ou un statut inconnu', function () {
    expect(fn () => TexteBanni::factory()->create(['texte' => str_repeat('a', 3201)]))->toThrow(QueryException::class);

    // Le cast d'énumération bloque déjà une valeur inconnue côté PHP : on attaque la base directement.
    $texte = TexteBanni::factory()->create();
    expect(fn () => DB::table('textes_bannis')->where('id', $texte->id)->update(['statut' => 'archive']))
        ->toThrow(QueryException::class);
});

it('marque une adresse comme contenant un texte banni et libère le lien si le texte disparaît', function () {
    $texte = TexteBanni::factory()->applique()->create();
    $adresse = Adresse::factory()->create(['texte_banni_id' => $texte->id]);

    expect($adresse->texteBanni->is($texte))->toBeTrue();

    $texte->delete();
    expect($adresse->fresh()->texte_banni_id)->toBeNull();
});

it('refuse de supprimer une bibliothèque qui a des adresses', function () {
    $adresse = Adresse::factory()->create();

    expect(fn () => $adresse->bibliotheque->delete())->toThrow(QueryException::class);
});
