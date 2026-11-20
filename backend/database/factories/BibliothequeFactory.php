<?php

namespace Database\Factories;

use App\Enums\EtatBibliotheque;
use App\Models\Bibliotheque;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Bibliotheque> */
class BibliothequeFactory extends Factory
{
    public function definition(): array
    {
        $identifiant = fake()->unique()->bothify('biblio-####');

        return [
            'identifiant' => $identifiant,
            'nom' => 'Bibliothèque '.fake()->unique()->lastName(),
            'description' => fake()->sentence(),
            'url_actuelle' => "https://{$identifiant}.example.test",
            'est_locale' => false,
            'etat' => EtatBibliotheque::Inconnu,
            'echecs_consecutifs' => 0,
            // L'empreinte de clé d'une autre bibliothèque : 64 caractères hexadécimaux.
            'empreinte_cle' => hash('sha256', $identifiant),
        ];
    }

    public function locale(): static
    {
        return $this->state(fn () => [
            'est_locale' => true,
            'etat' => EtatBibliotheque::EnLigne,
        ]);
    }

    public function enLigne(): static
    {
        return $this->state(fn () => ['etat' => EtatBibliotheque::EnLigne, 'dernier_contact_le' => now()]);
    }
}
