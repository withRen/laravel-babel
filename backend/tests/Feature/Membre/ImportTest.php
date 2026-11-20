<?php

use App\Jobs\ConvertirAdressesImportees;
use App\Models\MarquePage;
use App\Services\Membre\Donnees\ValideurDExport;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/Aide.php';

/*
 * POST /app/moi/import et GET /app/moi/import (M12) : voir docs/openapi.yaml, schéma ExportCompte.
 */
beforeEach(function () {
    $this->biblios = emBibliotheques();
    $this->membre = emConnecte();
    Queue::fake();
});

/** Un fichier d'export valide, avec de quoi remplir les trois sortes de lignes que l'import recrée. */
function emFichier(array $remplacements = []): array
{
    return $remplacements + [
        'format' => 'babel-export/v1',
        'exporte_le' => '2026-11-10T12:00:00+00:00',
        'bibliotheque' => 'babel-5',
        'profil' => ['pseudo' => 'ada', 'email' => 'ada@ancienne.test', 'cree_le' => '2026-01-01T00:00:00+00:00'],
        'marque_pages' => [
            ['bibliotheque' => 'babel-5', 'adresse' => '1-w1-s1-v1:1', 'titre' => 'Ancien titre', 'cree_le' => '2026-02-01T10:00:00+00:00'],
            ['bibliotheque' => 'exemple', 'adresse' => '0-w1-s1-v1:5', 'titre' => null, 'cree_le' => '2026-02-02T10:00:00+00:00'],
        ],
        'collections' => [[
            'nom' => 'Ma liste', 'description' => 'Pour lire', 'est_publique' => true,
            'pages' => [
                ['position' => 2, 'bibliotheque' => 'babel-5', 'adresse' => '1-w1-s1-v1:2'],
                ['position' => 1, 'bibliotheque' => 'exemple', 'adresse' => '0-w1-s1-v1:6'],
            ],
            'cree_le' => '2026-03-01T10:00:00+00:00',
        ]],
        'annotations' => [[
            'bibliotheque' => 'babel-5', 'adresse' => '1-w1-s1-v1:3', 'contenu' => '  Étrange.  ', 'est_publique' => true,
            'cree_le' => '2026-04-01T10:00:00+00:00', 'maj_le' => '2026-04-02T10:00:00+00:00',
        ]],
        'votes' => [['bibliotheque' => 'babel-5', 'adresse' => '1-w1-s1-v1:1', 'valeur' => 1, 'cree_le' => '2026-05-01T10:00:00+00:00']],
        'recherches' => [['texte' => 'bonjour', 'mode' => 'exact', 'portee' => 'locale', 'cree_le' => '2026-06-01T10:00:00+00:00']],
        'liens_courts' => [
            ['slug' => 'aaaa1111', 'cible' => ['type' => 'adresse', 'bibliotheque' => 'babel-5', 'adresse' => '1-w1-s1-v1'], 'passage' => null, 'visites' => 3, 'expire_le' => null, 'cree_le' => '2026-07-01T10:00:00+00:00'],
            ['slug' => 'bbbb2222', 'cible' => ['type' => 'collection', 'collection' => 0], 'passage' => null, 'visites' => 0, 'expire_le' => '2027-01-01T00:00:00+00:00', 'cree_le' => '2026-07-02T10:00:00+00:00'],
        ],
    ];
}

/** Envoie le fichier TEL QUEL (corps brut), comme le fait le client. */
function emImporter($test, array|string $fichier)
{
    return $test->call('POST', '/api/v1/app/moi/import', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], is_string($fichier) ? $fichier : json_encode($fichier));
}

it('répond 401 ERR_UNAUTHENTICATED sans jeton', function () {
    $this->refreshApplication();

    attendErreurContrat($this->postJson('/api/v1/app/moi/import', emFichier()), 401, 'ERR_UNAUTHENTICATED', null);
    attendErreurContrat($this->getJson('/api/v1/app/moi/import'), 401, 'ERR_UNAUTHENTICATED', null);
});

// ---- un fichier valide ---------------------------------------------------------------------------------

it('importe les marque-pages, collections et annotations (202, schéma ResultatImport exact)', function () {
    $r = emImporter($this, emFichier());

    $r->assertStatus(202);
    expect(emCles($r))->toBe(['importes', 'fusionnes', 'a_convertir']);
    expect(emCles($r, 'importes'))->toBe(['marque_pages', 'collections', 'annotations']);
    expect($r->json())->toBe(['importes' => ['marque_pages' => 2, 'collections' => 1, 'annotations' => 1], 'fusionnes' => 0, 'a_convertir' => 3]);

    expect($this->membre->marquePages()->count())->toBe(2)
        ->and($this->membre->collections()->count())->toBe(1)
        ->and($this->membre->annotations()->count())->toBe(1);
});

it('ne reprend QUE ce que l\'import recrée : ni votes, ni recherches, ni liens courts', function () {
    emImporter($this, emFichier())->assertStatus(202);

    expect(DB::table('votes')->count())->toBe(0)
        ->and($this->membre->recherches()->count())->toBe(0)
        ->and($this->membre->liensCourts()->count())->toBe(0);
});

it('fait arriver collections et annotations PRIVÉES, et garde les dates d\'origine', function () {
    emImporter($this, emFichier())->assertStatus(202);

    $collection = $this->membre->collections()->first();
    expect($collection->est_publique)->toBeFalse()->and($collection->cree_le->toIso8601String())->toBe('2026-03-01T10:00:00+00:00');

    $annotation = $this->membre->annotations()->first();
    expect($annotation->est_publique)->toBeFalse()
        ->and($annotation->cree_le->toIso8601String())->toBe('2026-04-01T10:00:00+00:00')
        ->and($annotation->maj_le->toIso8601String())->toBe('2026-04-02T10:00:00+00:00');

    expect($this->membre->marquePages()->orderBy('id')->first()->cree_le->toIso8601String())->toBe('2026-02-01T10:00:00+00:00');
});

it('garde le contenu d\'une annotation à l\'octet près : le fichier n\'est pas « nettoyé »', function () {
    emImporter($this, emFichier())->assertStatus(202);

    expect($this->membre->annotations()->first()->contenu)->toBe('  Étrange.  ');
});

it('range les pages d\'une collection dans l\'ordre des positions du fichier, renumérotées sans trou', function () {
    emImporter($this, emFichier())->assertStatus(202);

    $id = $this->membre->collections()->value('id');
    $pages = $this->getJson("/api/v1/app/moi/collections/{$id}")->json('pages');

    expect(array_column($pages, 'adresse'))->toBe(['0-w1-s1-v1:6', '1-w1-s1-v1:2']);
    expect(array_column($pages, 'position'))->toBe([1, 2]);
    expect(array_column($pages, 'a_convertir'))->toBe([false, true]);
});

it('marque « à convertir » les adresses d\'une AUTRE bibliothèque, et pas celles d\'ici', function () {
    emImporter($this, emFichier())->assertStatus(202);

    $marquePages = $this->getJson('/api/v1/app/moi/marque-pages')->json('elements');
    $parAdresse = array_column($marquePages, 'a_convertir', 'adresse');

    expect($parAdresse)->toBe(['0-w1-s1-v1:5' => false, '1-w1-s1-v1:1' => true]);
    expect($this->getJson('/api/v1/app/moi/annotations')->json('elements.0.a_convertir'))->toBeTrue();
});

it('lance la conversion en tâche de fond quand il y a des adresses à convertir', function () {
    emImporter($this, emFichier())->assertStatus(202);

    Queue::assertPushed(ConvertirAdressesImportees::class, fn ($job) => $job->utilisateurId === $this->membre->id);
});

it('ne lance rien quand toutes les adresses sont d\'ici', function () {
    $fichier = emFichier([
        'bibliotheque' => 'exemple',
        'marque_pages' => [['bibliotheque' => 'exemple', 'adresse' => '0-w1-s1-v1:5', 'titre' => null, 'cree_le' => '2026-02-02T10:00:00+00:00']],
        'collections' => [], 'annotations' => [],
    ]);

    $r = emImporter($this, $fichier);

    expect($r->json('a_convertir'))->toBe(0);
    Queue::assertNothingPushed();
});

it('compte une adresse une seule fois, combien de fois qu\'elle serve', function () {
    $fichier = emFichier(['collections' => [], 'annotations' => [
        ['bibliotheque' => 'babel-5', 'adresse' => '1-w1-s1-v1:1', 'contenu' => 'Une', 'est_publique' => false, 'cree_le' => '2026-04-01T10:00:00+00:00', 'maj_le' => '2026-04-01T10:00:00+00:00'],
        ['bibliotheque' => 'babel-5', 'adresse' => '1-w1-s1-v1:1', 'contenu' => 'Deux', 'est_publique' => false, 'cree_le' => '2026-04-01T10:00:00+00:00', 'maj_le' => '2026-04-01T10:00:00+00:00'],
    ]]);

    expect(emImporter($this, $fichier)->json('a_convertir'))->toBe(1); // la même page d'ailleurs, gardée en marque-page et annotée deux fois
});

it('FUSIONNE un marque-page déjà gardé au lieu de le recréer (règle 7)', function () {
    MarquePage::factory()->create(['utilisateur_id' => $this->membre->id, 'adresse_id' => emAdresse('0-w1-s1-v1:5')->id, 'titre' => null]);

    $r = emImporter($this, emFichier(['marque_pages' => [
        ['bibliotheque' => 'exemple', 'adresse' => '0-w1-s1-v1:5', 'titre' => 'Titre importé', 'cree_le' => '2026-02-02T10:00:00+00:00'],
        ['bibliotheque' => 'exemple', 'adresse' => '0-w1-s1-v1:5', 'titre' => 'Encore', 'cree_le' => '2026-02-03T10:00:00+00:00'],
    ]]));

    expect($r->json('importes.marque_pages'))->toBe(0)->and($r->json('fusionnes'))->toBe(2);
    expect($this->membre->marquePages()->count())->toBe(1);
    expect($this->membre->marquePages()->first()->titre)->toBe('Titre importé'); // le titre manquant est repris, jamais écrasé
});

it('coupe les textes trop longs pour nos limites plutôt que de tout refuser', function () {
    $fichier = emFichier([
        'marque_pages' => [['bibliotheque' => 'exemple', 'adresse' => '0-w1-s1-v1:5', 'titre' => str_repeat('é', 150), 'cree_le' => '2026-02-02T10:00:00+00:00']],
        'collections' => [['nom' => str_repeat('n', 150), 'description' => str_repeat('d', 1500), 'est_publique' => false, 'pages' => [], 'cree_le' => '2026-03-01T10:00:00+00:00']],
        'annotations' => [],
    ]);

    emImporter($this, $fichier)->assertStatus(202);

    expect(mb_strlen($this->membre->marquePages()->first()->titre))->toBe(100);
    expect(mb_strlen($this->membre->collections()->first()->nom))->toBe(100)->and(mb_strlen($this->membre->collections()->first()->description))->toBe(1000);
});

it('importe un export fait par notre propre exportateur (aller-retour)', function () {
    emRemplir($this->membre);
    $export = $this->getJson('/api/v1/app/moi/export')->getContent();
    $autre = emConnecte();

    $r = emImporter($this, $export);

    $r->assertStatus(202);
    expect($r->json('importes'))->toBe(['marque_pages' => 2, 'collections' => 1, 'annotations' => 1]);
    expect($autre->marquePages()->count())->toBe(2);
});

// ---- un fichier refusé : rien n'est importé ---------------------------------------------------------------

it('refuse un fichier hors contrat avec UNE erreur par problème, au format « marque_pages[3].adresse »', function () {
    $fichier = emFichier();
    $fichier['marque_pages'][1]['adresse'] = 'ABC-w9-s1-v1:1';
    $fichier['format'] = 'babel-export/v2';

    $r = emImporter($this, $fichier);

    $r->assertStatus(422);
    expect(array_column($r->json('erreurs'), 'code'))->toBe(array_fill(0, count($r->json('erreurs')), 'ERR_INVALID_IMPORT'));
    expect(array_column($r->json('erreurs'), 'champ'))->toContain('format')->toContain('marque_pages[1].adresse');
    expect(array_keys($r->json()))->toBe(['erreurs']);
    expect($this->membre->marquePages()->count())->toBe(0); // rien d'importé, même les éléments valides
    Queue::assertNothingPushed();
});

it('refuse un champ inconnu, un champ manquant et un mauvais type (additionalProperties: false)', function () {
    $fichier = emFichier();
    $fichier['extra'] = 1;
    unset($fichier['profil']['email']);
    $fichier['annotations'][0]['est_publique'] = 'oui';
    $fichier['votes'][0]['valeur'] = 2;
    $fichier['recherches'][0]['mode'] = 'Exact';
    $fichier['liens_courts'][0]['cible']['type'] = 'livre';

    $champs = array_column(emImporter($this, $fichier)->assertStatus(422)->json('erreurs'), 'champ');

    expect($champs)->toContain('extra')->toContain('profil.email')->toContain('annotations[0].est_publique')
        ->toContain('votes[0].valeur')->toContain('recherches[0].mode')->toContain('liens_courts[0].cible');
});

it('refuse une adresse de livre dans un marque-page, mais l\'accepte comme cible d\'un lien court', function () {
    $fichier = emFichier();
    $fichier['marque_pages'][0]['adresse'] = '1-w1-s1-v1';

    $champs = array_column(emImporter($this, $fichier)->assertStatus(422)->json('erreurs'), 'champ');

    expect($champs)->toBe(['marque_pages[0].adresse']); // le lien court vers « 1-w1-s1-v1 » du fichier de base est accepté
});

it('refuse une page qui n\'existe pas, une date sans fuseau et un email invalide', function () {
    $fichier = emFichier();
    $fichier['marque_pages'][0]['adresse'] = '1-w1-s1-v33:1';
    $fichier['exporte_le'] = '2026-11-10 12:00:00';
    $fichier['profil']['email'] = 'pas-un-email';

    $champs = array_column(emImporter($this, $fichier)->assertStatus(422)->json('erreurs'), 'champ');

    expect($champs)->toContain('marque_pages[0].adresse')->toContain('exporte_le')->toContain('profil.email');
});

it('refuse une adresse d\'une bibliothèque inconnue de notre annuaire : aucune ligne ne peut la porter', function () {
    $fichier = emFichier();
    $fichier['marque_pages'][0]['bibliotheque'] = 'disparue';

    $champs = array_column(emImporter($this, $fichier)->assertStatus(422)->json('erreurs'), 'champ');

    expect($champs)->toBe(['marque_pages[0].bibliotheque']);
});

it('refuse ce qui n\'est pas du JSON, ou pas un objet', function () {
    foreach (['pas du json', '[]', '"texte"', '{}', ''] as $corps) {
        $r = emImporter($this, $corps);
        $r->assertStatus(422);
        expect($r->json('erreurs.0.code'))->toBe('ERR_INVALID_IMPORT');
    }
});

it('refuse un fichier de plus de 10 Mo', function () {
    $r = emImporter($this, str_repeat(' ', 10 * 1024 * 1024 + 1));

    attendErreurContrat($r, 422, 'ERR_INVALID_IMPORT', null);
});

it('limite le nombre d\'erreurs renvoyées : un fichier tout faux ne produit pas une réponse géante', function () {
    $fichier = emFichier(['marque_pages' => array_fill(0, 500, ['bibliotheque' => 'babel-5', 'adresse' => 'nimporte', 'titre' => null, 'cree_le' => 'hier'])]);

    $r = emImporter($this, $fichier);

    expect($r->json('erreurs'))->toHaveCount(ValideurDExport::MAX_ERREURS);
});

// ---- GET /moi/import -----------------------------------------------------------------------------------------

it('dit combien d\'adresses restent à convertir, et quand a eu lieu la dernière tentative', function () {
    $r = $this->getJson('/api/v1/app/moi/import');
    $r->assertOk();
    expect($r->json())->toBe(['a_convertir' => 0, 'derniere_tentative_le' => null]);

    emImporter($this, emFichier())->assertStatus(202);
    $this->membre->forceFill(['import_tentative_le' => '2026-11-20 08:00:00'])->save();

    $r = $this->getJson('/api/v1/app/moi/import');
    expect($r->json())->toBe(['a_convertir' => 3, 'derniere_tentative_le' => '2026-11-20T08:00:00+00:00']);
    expect(emCles($r))->toBe(['a_convertir', 'derniere_tentative_le']);
});

it('ne compte que mes adresses à convertir, pas celles des autres membres', function () {
    emImporter($this, emFichier())->assertStatus(202);
    emConnecte();

    expect($this->getJson('/api/v1/app/moi/import')->json('a_convertir'))->toBe(0);
});
