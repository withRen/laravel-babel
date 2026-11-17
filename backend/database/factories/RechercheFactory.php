<?php

namespace Database\Factories;

use App\Enums\ModeRecherche;
use App\Enums\PorteeRecherche;
use App\Models\Recherche;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Recherche> */
class RechercheFactory extends Factory
{
    public function definition(): array
    {
        return [
            'utilisateur_id' => User::factory(),
            'texte' => fake()->regexify('[a-z]{3,8}( [a-z]{3,8}){0,3}'),
            'mode' => ModeRecherche::Exact,
            'portee' => PorteeRecherche::Locale,
        ];
    }
}
