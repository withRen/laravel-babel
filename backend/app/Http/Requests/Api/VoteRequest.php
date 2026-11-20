<?php

namespace App\Http\Requests\Api;

/**
 * PUT /app/pages/{adresse}/vote (M7) : « valeur » vaut 1 ou -1. La bibliothèque est dans la requête
 * (?bibliotheque=…), comme pour les autres routes qui visent une page d'ailleurs. Sert aussi au DELETE,
 * qui n'a pas de corps : seule la bibliothèque est alors contrôlée.
 */
class VoteRequest extends RequeteMembre
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $regles = ['bibliotheque' => ['sometimes', 'string', 'regex:'.self::IDENTIFIANT_BIBLIOTHEQUE]];

        if ($this->isMethod('PUT')) {
            $regles['valeur'] = ['required', function (string $attribut, mixed $valeur, \Closure $echec): void {
                if ($valeur !== 1 && $valeur !== -1) {
                    $echec('La valeur doit être 1 (pour) ou -1 (contre).');
                }
            }];
        }

        return $regles;
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'bibliotheque.string' => "L'identifiant de la bibliothèque doit être un texte.",
            'bibliotheque.regex' => "L'identifiant de la bibliothèque n'est pas valide.",
            'valeur.required' => 'La valeur est obligatoire : 1 (pour) ou -1 (contre).',
        ];
    }
}
