<?php

namespace App\Http\Requests\Api;

class ListeCollectionsRequest extends RequeteDeListe
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
