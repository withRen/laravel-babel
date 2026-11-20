<?php

namespace App\Http\Resources\Api;

use App\Models\Adresse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Schéma « PagePopulaire ». Attend l'attribut « score » calculé par PagesPopulaires.
 *
 * @mixin Adresse
 */
class PagePopulaireResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'bibliotheque' => $this->bibliotheque->identifiant,
            'adresse' => $this->ecriture(),
            'score' => (int) $this->score,
        ];
    }
}
