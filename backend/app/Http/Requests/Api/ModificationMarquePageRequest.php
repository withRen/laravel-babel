<?php

namespace App\Http\Requests\Api;

/** PATCH /app/moi/marque-pages/{id} : le titre est obligatoire dans le corps, mais « null » le retire. */
class ModificationMarquePageRequest extends RequeteMembre
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'titre' => ['present', 'nullable', 'string', 'max:100'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'titre.present' => 'Le titre est obligatoire (null pour le retirer).',
            'titre.string' => 'Le titre doit être un texte.',
            'titre.max' => 'Le titre ne doit pas dépasser 100 caractères.',
        ];
    }
}
