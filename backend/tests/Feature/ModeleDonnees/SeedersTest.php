<?php

use App\Models\Adresse;
use App\Models\Bibliotheque;
use App\Models\Reglage;
use Database\Seeders\BibliothequeLocaleSeeder;
use Database\Seeders\DatabaseSeeder;

it('crée une seule bibliothèque locale, sans clé, et reste idempotent', function () {
    $this->seed(BibliothequeLocaleSeeder::class);
    $this->seed(BibliothequeLocaleSeeder::class);

    expect(Bibliotheque::where('est_locale', true)->count())->toBe(1)
        ->and(Bibliotheque::locale()->empreinte_cle)->toBeNull()
        ->and(Reglage::count())->toBe(2);
});

it('remplit une base de démonstration sans erreur', function () {
    $this->seed(DatabaseSeeder::class);

    expect(Bibliotheque::count())->toBe(2)
        ->and(Adresse::count())->toBeGreaterThan(0);
});
