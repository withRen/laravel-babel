<?php

namespace Database\Factories;

use App\Enums\StatutSignalement;
use App\Models\Annotation;
use App\Models\Collection;
use App\Models\LienCourt;
use App\Models\Signalement;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Par défaut : un signalement d'annotation. Un seul état « cible » à la fois.
 *
 * @extends Factory<Signalement>
 */
class SignalementFactory extends Factory
{
    public function definition(): array
    {
        return [
            'annotation_id' => Annotation::factory()->publique(),
            'collection_id' => null,
            'lien_court_id' => null,
            'auteur_id' => User::factory(),
            'motif' => fake()->sentence(),
            'statut' => StatutSignalement::EnAttente,
        ];
    }

    public function surCollection(): static
    {
        return $this->state(fn () => ['annotation_id' => null, 'collection_id' => Collection::factory()->publique()]);
    }

    public function surLienCourt(): static
    {
        return $this->state(fn () => ['annotation_id' => null, 'lien_court_id' => LienCourt::factory()]);
    }

    public function visiteur(): static
    {
        return $this->state(fn () => ['auteur_id' => null]);
    }
}
