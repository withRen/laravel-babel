<?php

namespace App\Http\Resources\Api;

use App\Models\TexteBanni;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Schéma « TexteBanni » : le texte (normalisé), son empreinte SHA-256, le motif, la date.
 *
 * @mixin TexteBanni
 */
class TexteBanniResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'texte' => $this->texte,
            'empreinte' => $this->empreinte,
            'motif' => $this->motif,
            // « decide_le » = le jour où il a été appliqué ; à défaut, le jour où il a été créé.
            'banni_le' => ($this->decide_le ?? $this->cree_le)->toIso8601String(),
        ];
    }
}
