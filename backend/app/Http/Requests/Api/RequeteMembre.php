<?php

namespace App\Http\Requests\Api;

use App\Exceptions\ErreurApi;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Socle des requêtes de l'espace membre : le jeton est déjà vérifié par le middleware (auth:sanctum),
 * et chaque ressource est retrouvée par le contrôleur dans CE que le membre possède (404 sinon),
 * donc aucune autorisation à ajouter ici.
 *
 * Les règles « strictes » existent parce que le contrat dit « booléen » et « entier » : Laravel accepte
 * sinon "1", "true", "on" ou "12" (chaînes), que le contrat n'autorise pas.
 */
abstract class RequeteMembre extends FormRequest
{
    /** Même motif que le schéma IdentifiantBibliotheque du contrat. */
    public const IDENTIFIANT_BIBLIOTHEQUE = '/\A[a-z0-9][a-z0-9-]{1,31}\z/';

    public function authorize(): bool
    {
        return true;
    }

    /**
     * La page désignée : adresse (obligatoire) et bibliothèque (facultative, la nôtre par défaut).
     * Le CONTENU de l'adresse n'est pas jugé ici mais par le moteur (valider), avec ses propres erreurs.
     *
     * @return array<string, list<mixed>>
     */
    protected function reglesDePage(): array
    {
        return [
            'adresse' => ['required', 'string'],
            'bibliotheque' => ['sometimes', 'string', 'regex:'.self::IDENTIFIANT_BIBLIOTHEQUE],
        ];
    }

    /** @return array<string, string> */
    protected function messagesDePage(): array
    {
        return [
            'adresse.required' => "L'adresse est obligatoire.",
            'adresse.string' => "L'adresse doit être un texte.",
            'bibliotheque.string' => "L'identifiant de la bibliothèque doit être un texte.",
            'bibliotheque.regex' => "L'identifiant de la bibliothèque n'est pas valide.",
        ];
    }

    /** Un vrai booléen JSON (true/false) : « 1 », « "true" » ou « "on" » sont refusés. */
    protected static function booleen(string $message): \Closure
    {
        return function (string $attribut, mixed $valeur, \Closure $echec) use ($message): void {
            if (! is_bool($valeur)) {
                $echec($message);
            }
        };
    }

    /** Un vrai entier JSON, au moins égal à $minimum : « "3" » et « 3.5 » sont refusés. */
    protected static function entier(string $message, int $minimum = 1): \Closure
    {
        return function (string $attribut, mixed $valeur, \Closure $echec) use ($message, $minimum): void {
            if (! is_int($valeur) || $valeur < $minimum) {
                $echec($message);
            }
        };
    }

    /**
     * Au moins un des champs : le contrat (minProperties: 1) refuse un corps qui ne change rien.
     *
     * @param  list<string>  $champs
     */
    protected function exigerUnChamp(Validator $validateur, array $champs): void
    {
        if ($validateur->errors()->isEmpty() && ! $this->hasAny($champs)) {
            throw ErreurApi::une(422, 'ERR_INVALID_PARAMETER', 'Envoyez au moins un champ à modifier : '.implode(', ', $champs).'.');
        }
    }
}
