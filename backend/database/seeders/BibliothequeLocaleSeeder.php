<?php

namespace Database\Seeders;

use App\Enums\EtatBibliotheque;
use App\Models\Bibliotheque;
use App\Models\Reglage;
use Illuminate\Database\Seeder;

/**
 * Le strict nécessaire pour que l'application fonctionne, en production comme en démo :
 * la ligne de notre bibliothèque et les réglages par défaut. Idempotent.
 *
 * `empreinte_cle` reste nulle ici : elle est renseignée à partir de `$moteur->empreinte()`
 * par l'application (la clé n'est jamais lue ni stockée par un seeder).
 */
class BibliothequeLocaleSeeder extends Seeder
{
    public function run(): void
    {
        Bibliotheque::updateOrCreate(
            ['est_locale' => true],
            [
                'identifiant' => config('babel.identifiant', 'babel-3'),
                'nom' => config('babel.nom', 'Babel 3'),
                'description' => 'Bibliothèque de Babel du groupe 3.',
                'url_actuelle' => config('app.url'),
                'etat' => EtatBibliotheque::EnLigne,
            ]
        );

        Reglage::firstOrCreate(['cle' => 'inscriptions_ouvertes'], ['valeur' => '1']);
        Reglage::firstOrCreate(['cle' => 'quota_api_publique'], ['valeur' => '60']);
    }
}
