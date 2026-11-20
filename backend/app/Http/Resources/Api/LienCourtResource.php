<?php

namespace App\Http\Resources\Api;

use App\Models\LienCourt;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Schéma « LienCourt » (ce que voit son créateur). Attend `adresse.bibliotheque` chargée quand la cible est une adresse.
 *
 * @mixin LienCourt
 */
class LienCourtResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'slug' => $this->slug,
            'cible' => self::cible($this->resource),
            'passage' => $this->passage,
            'visites' => (int) $this->visites,
            'expire_le' => $this->expire_le?->toIso8601String(),
            'cree_le' => $this->cree_le->toIso8601String(),
        ];
    }

    /**
     * Schéma « CibleLienCourt » : une adresse (avec son niveau) ou une collection.
     *
     * @return array<string, mixed>
     */
    public static function cible(LienCourt $lien): array
    {
        if ($lien->collection_id !== null) {
            return ['type' => 'collection', 'id' => (string) $lien->collection_id];
        }

        return [
            'type' => 'adresse',
            'bibliotheque' => $lien->adresse->bibliotheque->identifiant,
            'adresse' => $lien->adresse->ecriture(),
            'niveau' => $lien->adresse->estLivre() ? 'livre' : 'page',
        ];
    }
}
