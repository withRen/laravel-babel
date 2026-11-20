<?php

namespace App\Http\Resources\Api;

use App\Models\Recherche;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Schéma « RechercheEnregistree ». L'adresse trouvée n'est pas gardée (règle 12) : le texte suffit à la retrouver.
 *
 * @mixin Recherche
 */
class RechercheEnregistreeResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'texte' => $this->texte,
            'mode' => $this->mode->value,
            'portee' => $this->portee->value,
            'cree_le' => $this->cree_le->toIso8601String(),
        ];
    }
}
