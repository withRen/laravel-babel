<?php

namespace App\Http\Requests\Api;

/** Les listes du membre : « lot » et « taille » (voir RequeteDeListe), rien d'autre à filtrer. */
class ListePagineeRequest extends RequeteDeListe
{
    public function rules(): array
    {
        return parent::rules() + [
            'lot' => ['filled', 'integer', 'min:1'],
        ];
    }

    public function lot(): int
    {
        return (int) ($this->validated('lot') ?? 1);
    }
}
