<?php

namespace App\Http\Resources\Api;

use App\Models\Collection;
use Illuminate\Http\Request;

/**
 * Schéma « Collection » : le résumé, plus les pages montrées par position croissante.
 * Chaque page porte l'identifiant de SA bibliothèque (la nôtre ou, depuis M13, une autre).
 *
 * @mixin Collection
 */
class CollectionResource extends ResumeCollectionResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return parent::toArray($request) + [
            'pages' => $this->pages->map(fn ($page) => [
                'position' => $page->position,
                'bibliotheque' => $page->adresse->bibliotheque->identifiant,
                'adresse' => $page->adresse->ecriture(),
            ])->all(),
        ];
    }
}
