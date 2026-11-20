<?php

namespace App\Http\Resources\Api;

use App\Models\Collection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Schéma « ResumeMaCollection » : une collection vue par son auteur. Attend « nombre_pages » (withCount).
 * Toutes les pages comptent, y compris celles que le public ne voit pas.
 *
 * @mixin Collection
 */
class ResumeMaCollectionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'nom' => $this->nom,
            'description' => $this->description ?? '',
            'est_publique' => $this->est_publique,
            'masquee' => $this->masque_le !== null,
            'nombre_pages' => (int) $this->nombre_pages,
            'cree_le' => $this->cree_le->toIso8601String(),
            'maj_le' => $this->maj_le->toIso8601String(),
        ];
    }
}
