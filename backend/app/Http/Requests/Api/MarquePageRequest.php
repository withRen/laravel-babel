<?php

namespace App\Http\Requests\Api;

/** POST /app/moi/marque-pages (M2, M13) : la page à garder, et un titre facultatif. */
class MarquePageRequest extends RequeteMembre
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return $this->reglesDePage() + [
            'titre' => ['sometimes', 'nullable', 'string', 'max:100'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return $this->messagesDePage() + [
            'titre.string' => 'Le titre doit être un texte.',
            'titre.max' => 'Le titre ne doit pas dépasser 100 caractères.',
        ];
    }
}
