<?php

namespace Database\Factories;

use App\Enums\StatutTexteBanni;
use App\Models\Bibliotheque;
use App\Models\TexteBanni;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TexteBanni> */
class TexteBanniFactory extends Factory
{
    public function definition(): array
    {
        return [
            // Texte déjà normalisé : minuscules, virgule, point, espaces simples.
            'texte' => fake()->regexify('[a-z]{4,8}( [a-z]{4,8}){2,6}'),
            'origine_id' => Bibliotheque::factory(),
            'statut' => StatutTexteBanni::Propose,
        ];
    }

    public function applique(): static
    {
        return $this->state(fn () => ['statut' => StatutTexteBanni::Applique, 'decide_le' => now()]);
    }
}
