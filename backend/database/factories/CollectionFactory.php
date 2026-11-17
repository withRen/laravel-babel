<?php

namespace Database\Factories;

use App\Models\Collection;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Collection> */
class CollectionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'utilisateur_id' => User::factory(),
            'nom' => fake()->words(3, true),
            'description' => fake()->optional()->sentence(),
            'est_publique' => false,
        ];
    }

    public function publique(): static
    {
        return $this->state(fn () => ['est_publique' => true]);
    }
}
