<?php

namespace Database\Seeders;

use App\Enums\StatutSignalement;
use App\Enums\StatutTexteBanni;
use App\Models\ActiviteJour;
use App\Models\Adresse;
use App\Models\Annotation;
use App\Models\Bibliotheque;
use App\Models\Collection;
use App\Models\LienCourt;
use App\Models\MarquePage;
use App\Models\Recherche;
use App\Models\Signalement;
use App\Models\TexteBanni;
use App\Models\User;
use App\Models\Vote;
use Illuminate\Database\Seeder;

/** Données de démonstration : à ne jamais lancer en production. Mots de passe de démo : « password ». */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $locale = Bibliotheque::locale();
        $autre = Bibliotheque::factory()->enLigne()->create([
            'identifiant' => 'biblio-demo',
            'nom' => 'Bibliothèque voisine',
            'url_actuelle' => 'https://voisine.example.test',
        ]);

        $admin = User::factory()->admin()->create(['pseudo' => 'admin', 'email' => 'admin@babel.test']);
        $membres = collect(['alice', 'bob', 'chloe'])->map(
            fn ($p) => User::factory()->create(['pseudo' => $p, 'email' => "$p@babel.test"])
        );
        [$alice, $bob] = [$membres[0], $membres[1]];

        $pages = Adresse::factory()->count(6)->create(['bibliotheque_id' => $locale->id]);
        $distante = Adresse::factory()->create(['bibliotheque_id' => $autre->id]);
        $livre = Adresse::factory()->livre()->create(['bibliotheque_id' => $locale->id]);

        foreach ($pages->take(3) as $i => $page) {
            MarquePage::factory()->create(['utilisateur_id' => $alice->id, 'adresse_id' => $page->id, 'titre' => 'Découverte '.($i + 1)]);
        }
        MarquePage::factory()->create(['utilisateur_id' => $bob->id, 'adresse_id' => $distante->id]);

        $collection = Collection::factory()->publique()->create(['utilisateur_id' => $alice->id, 'nom' => 'Mes premières trouvailles']);
        foreach ($pages->take(3)->values() as $i => $page) {
            $collection->pages()->create(['position' => $i + 1, 'adresse_id' => $page->id]);
        }

        $annotation = Annotation::factory()->publique()->create([
            'utilisateur_id' => $alice->id, 'adresse_id' => $pages[0]->id, 'contenu' => 'Une page étonnamment lisible.',
        ]);
        Annotation::factory()->create(['utilisateur_id' => $bob->id, 'adresse_id' => $pages[1]->id, 'est_publique' => false]);

        Vote::create(['utilisateur_id' => $alice->id, 'adresse_id' => $pages[0]->id, 'valeur' => 1]);
        Vote::create(['utilisateur_id' => $bob->id, 'adresse_id' => $pages[0]->id, 'valeur' => -1]);

        LienCourt::factory()->create(['utilisateur_id' => $alice->id, 'adresse_id' => $pages[0]->id, 'passage' => 'une page']);
        LienCourt::factory()->visiteur()->create(['adresse_id' => $livre->id]);
        LienCourt::factory()->create(['utilisateur_id' => $alice->id, 'adresse_id' => null, 'collection_id' => $collection->id]);

        Signalement::factory()->create([
            'annotation_id' => $annotation->id, 'auteur_id' => $bob->id, 'motif' => 'Hors sujet.',
        ]);
        Signalement::factory()->create([
            'annotation_id' => $annotation->id, 'auteur_id' => null, 'motif' => 'Un visiteur.',
            'statut' => StatutSignalement::Rejete, 'traite_par_id' => $admin->id, 'traite_le' => now(),
        ]);

        TexteBanni::factory()->applique()->create(['origine_id' => $locale->id, 'motif' => 'Démonstration']);
        TexteBanni::factory()->create(['origine_id' => $autre->id, 'statut' => StatutTexteBanni::Propose]);

        Recherche::factory()->count(3)->create(['utilisateur_id' => $alice->id]);
        ActiviteJour::create(['jour' => now()->toDateString(), 'pages_lues' => 42, 'recherches' => 7]);
    }
}
