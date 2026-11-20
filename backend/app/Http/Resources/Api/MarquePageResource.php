<?php

namespace App\Http\Resources\Api;

use App\Models\MarquePage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Schéma « MarquePage ». Attend la relation « adresse.bibliotheque » chargée.
 *
 * @mixin MarquePage
 */
class MarquePageResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'bibliotheque' => $this->adresse->bibliotheque->identifiant,
            'adresse' => $this->adresse->ecriture(),
            'titre' => $this->titre,
            'a_convertir' => $this->a_convertir,
            'cree_le' => $this->cree_le->toIso8601String(),
        ];
    }
}
