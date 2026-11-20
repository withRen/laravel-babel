<?php

namespace App\Http\Resources\Api;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * L'enveloppe commune de toutes les listes du contrat : {lot, taille, total, elements}.
 * « total » compte tous les éléments de la liste, pas seulement ceux du lot.
 */
final class ListePaginee
{
    /**
     * @param  class-string<JsonResource>  $ressource
     * @return array{lot: int, taille: int, total: int, elements: array<int, mixed>}
     */
    public static function de(LengthAwarePaginator $lot, string $ressource): array
    {
        return [
            'lot' => $lot->currentPage(),
            'taille' => $lot->perPage(),
            'total' => $lot->total(),
            'elements' => $ressource::collection($lot->items())->resolve(),
        ];
    }
}
