<?php

namespace App\Http\Requests\Api;

use Illuminate\Contracts\Validation\Validator;

/**
 * POST et PATCH /app/moi/collections (M4, M5).
 *
 * Création : « nom » obligatoire, « description » et « est_publique » facultatifs (privée par défaut).
 * Modification : seuls les champs envoyés changent, au moins un.
 * Une description vide ("") est acceptée : le serveur la range en chaîne vide, jamais en null.
 */
class CollectionMembreRequest extends RequeteMembre
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $creation = $this->isMethod('POST');

        return [
            'nom' => array_merge($creation ? ['required'] : ['sometimes', 'required'], ['string', 'max:100']),
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'est_publique' => ['sometimes', self::booleen('« est_publique » doit être true ou false.')],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validateur): void {
                if (! $this->isMethod('POST')) {
                    $this->exigerUnChamp($validateur, ['nom', 'description', 'est_publique']);
                }
            },
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'nom.required' => 'Le nom est obligatoire.',
            'nom.string' => 'Le nom doit être un texte.',
            'nom.max' => 'Le nom ne doit pas dépasser 100 caractères.',
            'description.string' => 'La description doit être un texte.',
            'description.max' => 'La description ne doit pas dépasser 1000 caractères.',
        ];
    }
}
