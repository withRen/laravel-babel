<?php

namespace App\Http\Resources\Api;

use App\Models\Signalement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Schéma « Signalement ». La cible est {type, id} : l'id est celui de l'annotation ou de la collection,
 * ou le SLUG du lien court (pas son id interne). Attend la relation `lienCourt` chargée.
 *
 * @mixin Signalement
 */
class SignalementResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'cible' => match (true) {
                $this->annotation_id !== null => ['type' => 'annotation', 'id' => (string) $this->annotation_id],
                $this->collection_id !== null => ['type' => 'collection', 'id' => (string) $this->collection_id],
                default => ['type' => 'lien_court', 'id' => $this->lienCourt->slug],
            },
            'motif' => $this->motif,
            'statut' => $this->statut->value,
            'cree_le' => $this->cree_le->toIso8601String(),
        ];
    }
}
