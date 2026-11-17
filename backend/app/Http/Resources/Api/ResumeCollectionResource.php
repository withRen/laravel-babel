<?php

namespace App\Http\Resources\Api;

use App\Models\Collection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Schéma « ResumeCollection ». Attend un attribut « nombre_pages » (withCount) : les pages
 * retirées par un texte banni ne sont pas comptées.
 *
 * @mixin Collection
 */
class ResumeCollectionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,       // le contrat veut des identifiants en chaîne
            'nom' => $this->nom,
            'description' => $this->description ?? '', // jamais null : chaîne vide
            'nombre_pages' => (int) $this->nombre_pages,
            'maj_le' => $this->maj_le->toIso8601String(),
        ];
    }
}
