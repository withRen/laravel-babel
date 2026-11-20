<?php

/*
 * Outils communs aux tests de l'espace membre. Ce fichier ne finit pas par « Test » : Pest ne le charge pas tout seul,
 * chaque test le demande avec require_once. Les fonctions portent le préfixe « em » (espace membre) pour ne jamais
 * entrer en collision avec celles des autres fichiers de tests (PHP interdit de redéclarer une fonction).
 */

use App\Models\Adresse;
use App\Models\Bibliotheque;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

/** La bibliothèque locale (« exemple ») et une autre du réseau (« babel-5 »), créées à la demande. */
function emBibliotheques(): array
{
    return [
        'locale' => Bibliotheque::locale() ?? bibliothequeLocale(),
        'autre' => Bibliotheque::query()->where('identifiant', 'babel-5')->first()
            ?? Bibliotheque::factory()->create(['identifiant' => 'babel-5', 'url_actuelle' => 'https://babel-5.example.test']),
    ];
}

/** Un membre connecté (jeton Sanctum) : les requêtes suivantes sont les siennes. */
function emConnecte(?User $membre = null): User
{
    $membre ??= User::factory()->create();
    Sanctum::actingAs($membre);

    return $membre;
}

/** La ligne ADRESSE d'une page, d'ici par défaut (créée si besoin). $adresse doit être une adresse de page valide. */
function emAdresse(string $adresse = '0-w1-s1-v1:1', ?Bibliotheque $bibliotheque = null): Adresse
{
    $bibliotheque ??= emBibliotheques()['locale'];

    $existante = Adresse::query()->where('bibliotheque_id', $bibliotheque->id)->where('empreinte', hash('sha256', $adresse))->first();
    if ($existante !== null) {
        return $existante;
    }

    preg_match('/\A([0-9a-z]+)-w(\d+)-s(\d+)-v(\d+):(\d+)\z/', $adresse, $m);

    return Adresse::factory()->create([
        'bibliotheque_id' => $bibliotheque->id,
        'hexagone' => $m[1], 'mur' => (int) $m[2], 'etagere' => (int) $m[3], 'volume' => (int) $m[4], 'page' => (int) $m[5],
    ]);
}

/** Les clés d'une réponse JSON, dans l'ordre : le contrat dit « additionalProperties: false ». */
function emCles($reponse, ?string $chemin = null): array
{
    return array_keys($chemin === null ? $reponse->json() : $reponse->json($chemin));
}
