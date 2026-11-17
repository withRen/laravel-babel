<?php

namespace Database\Factories;

use App\Models\Adresse;
use App\Models\Bibliotheque;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Les empreintes sont calculées par le modèle à l'enregistrement.
 *
 * @extends Factory<Adresse>
 */
class AdresseFactory extends Factory
{
    public function definition(): array
    {
        return [
            'bibliotheque_id' => Bibliotheque::factory(),
            // Hexagone en base 36 : chiffres et minuscules, sans zéro de tête.
            'hexagone' => fake()->regexify('[1-9a-z][0-9a-z]{9,39}'),
            'mur' => fake()->numberBetween(1, 4),
            'etagere' => fake()->numberBetween(1, 5),
            'volume' => fake()->numberBetween(1, 32),
            'page' => fake()->numberBetween(1, 410),
        ];
    }

    /** Un livre entier : pas de page. */
    public function livre(): static
    {
        return $this->state(fn () => ['page' => null]);
    }
}
