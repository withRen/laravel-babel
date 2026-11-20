<?php

namespace App\Http\Requests\Api;

/** POST /app/moi/collections/{id}/pages (M4, M13) : une page, et une position facultative (à partir de 1). */
class PageDeCollectionRequest extends RequeteMembre
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return $this->reglesDePage() + [
            'position' => ['sometimes', self::entier('La position doit être un entier à partir de 1.')],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return $this->messagesDePage();
    }
}
