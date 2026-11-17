<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(BibliothequeLocaleSeeder::class);

        // Les données de démonstration ne partent jamais en production.
        if (! app()->isProduction()) {
            $this->call(DemoSeeder::class);
        }
    }
}
