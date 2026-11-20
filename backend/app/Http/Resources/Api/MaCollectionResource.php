<?php

namespace App\Http\Resources\Api;

use App\Models\Collection;
use Illuminate\Http\Request;

/**
 * Schéma « MaCollection » : le résumé, plus TOUTES les pages par position croissante.
 * Contrairement à la porte réseau, une page marquée par un texte banni est listée, avec « masquee » :
 * son auteur la voit, le public non. Attend « pages.adresse.bibliotheque » chargée.
 *
 * @mixin Collection
 */
class MaCollectionResource extends ResumeMaCollectionResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return array_merge(parent::toArray($request), [
            'nombre_pages' => $this->pages->count(),
            'pages' => $this->pages->map(fn ($page) => [
                'position' => $page->position,
                'bibliotheque' => $page->adresse->bibliotheque->identifiant,
                'adresse' => $page->adresse->ecriture(),
                'masquee' => $page->adresse->texte_banni_id !== null,
                'a_convertir' => $page->a_convertir,
            ])->all(),
        ]);
    }
}
