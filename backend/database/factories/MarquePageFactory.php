<?php

namespace Database\Factories;

use App\Models\Adresse;
use App\Models\MarquePage;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MarquePage> */
class MarquePageFactory extends Factory
{
    public function definition(): array
    {
        return [
            'utilisateur_id' => User::factory(),
            'adresse_id' => Adresse::factory(),
            'titre' => fake()->optional()->words(3, true),
        ];
    }
}
