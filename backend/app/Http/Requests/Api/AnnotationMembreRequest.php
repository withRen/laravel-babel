<?php

namespace App\Http\Requests\Api;

use Illuminate\Contracts\Validation\Validator;

/**
 * POST et PATCH /app/moi/annotations (M6).
 *
 * Création : la page, le contenu (1 à 2 000 caractères, règle 9) et la visibilité sont obligatoires.
 * Modification : « contenu » et/ou « est_publique », au moins un ; la page ne change pas.
 */
class AnnotationMembreRequest extends RequeteMembre
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        if (! $this->isMethod('POST')) {
            return [
                'contenu' => ['sometimes', 'required', 'string', 'max:2000'],
                'est_publique' => ['sometimes', self::booleen('« est_publique » doit être true ou false.')],
            ];
        }

        return $this->reglesDePage() + [
            'contenu' => ['required', 'string', 'max:2000'],
            'est_publique' => ['required', self::booleen('« est_publique » doit être true ou false.')],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validateur): void {
                if (! $this->isMethod('POST')) {
                    $this->exigerUnChamp($validateur, ['contenu', 'est_publique']);
                }
            },
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return $this->messagesDePage() + [
            'contenu.required' => 'Le contenu est obligatoire (1 à 2 000 caractères).',
            'contenu.string' => 'Le contenu doit être un texte.',
            'contenu.max' => 'Le contenu ne doit pas dépasser 2 000 caractères.',
            'est_publique.required' => 'La visibilité (« est_publique ») est obligatoire.',
        ];
    }
}
