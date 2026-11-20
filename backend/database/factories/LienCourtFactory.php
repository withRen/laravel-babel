<?php

namespace Database\Factories;

use App\Models\Adresse;
use App\Models\Collection;
use App\Models\LienCourt;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Par défaut : un lien créé par un membre vers une adresse. Voir `versCollection()`
 * et `visiteur()` pour les autres cas.
 *
 * @extends Factory<LienCourt>
 */
class LienCourtFactory extends Factory
{
    public function definition(): array
    {
        return [
            'slug' => Str::random(8),
            'utilisateur_id' => User::factory(),
            'adresse_id' => Adresse::factory(),
            'collection_id' => null,
            'visites' => 0,
            'expire_le' => null,
        ];
    }

    public function versCollection(): static
    {
        return $this->state(fn () => ['adresse_id' => null, 'collection_id' => Collection::factory()->publique(), 'passage' => null]);
    }

    /** Créé sans compte : il expire obligatoirement. */
    public function visiteur(): static
    {
        return $this->state(fn () => ['utilisateur_id' => null, 'expire_le' => now()->addDays(30)]);
    }
}
