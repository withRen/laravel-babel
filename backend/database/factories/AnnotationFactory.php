<?php

namespace Database\Factories;

use App\Models\Adresse;
use App\Models\Annotation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Annotation> */
class AnnotationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'utilisateur_id' => User::factory(),
            'adresse_id' => Adresse::factory(),
            'contenu' => fake()->paragraph(),
            'est_publique' => fake()->boolean(),
        ];
    }

    public function publique(): static
    {
        return $this->state(fn () => ['est_publique' => true]);
    }
}
