<?php

namespace App\Http\Resources\Api;

use App\Models\Vote;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Schéma « Vote » (la liste GET /moi/votes). Attend « adresse.bibliotheque » chargée.
 *
 * @mixin Vote
 */
class VoteResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'bibliotheque' => $this->adresse->bibliotheque->identifiant,
            'adresse' => $this->adresse->ecriture(),
            'valeur' => $this->valeur,
            'cree_le' => $this->cree_le->toIso8601String(),
        ];
    }
}
