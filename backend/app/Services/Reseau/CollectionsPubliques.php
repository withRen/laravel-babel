<?php

declare(strict_types=1);

namespace App\Services\Reseau;

use App\Models\Collection;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Ce que la porte réseau montre des collections (B3) : les collections publiques et non masquées,
 * et seulement les pages qui ne sont pas marquées par un texte banni appliqué ici.
 *
 * Une page « marquée » garde sa position (les autres ne se décalent pas) : elle est seulement
 * retirée de la liste et du compte. L'auteur n'est jamais publié.
 */
final class CollectionsPubliques
{
    /** Les pages montrées : des pages (pas des livres), sans texte banni appliqué. */
    private function pagesMontrees(): \Closure
    {
        return fn (Relation|Builder $pages) => $pages->whereHas(
            'adresse',
            fn (Builder $a) => $a->whereNotNull('page')->whereNull('texte_banni_id'),
        );
    }

    /** Les plus récemment modifiées d'abord ; l'id départage deux modifications à la même seconde. */
    public function lister(int $lot, int $taille): LengthAwarePaginator
    {
        return Collection::query()
            ->where('est_publique', true)
            ->whereNull('masque_le')
            ->withCount(['pages as nombre_pages' => $this->pagesMontrees()])
            ->orderByDesc('maj_le')
            ->orderByDesc('id')
            ->paginate($taille, ['*'], 'lot', $lot);
    }

    /** Une collection privée, masquée ou inconnue donne la même réponse : null. */
    public function trouver(string $id): ?Collection
    {
        // Un id qui n'est pas un entier décimal n'existe pas : pas de conversion hasardeuse en SQL
        // (« 12abc » ne doit pas devenir 12), et pas de débordement au-delà d'un entier 64 bits.
        if (! ctype_digit($id) || strlen($id) > 18) {
            return null;
        }

        $collection = Collection::query()
            ->where('est_publique', true)
            ->whereNull('masque_le')
            ->with(['pages' => fn ($pages) => $this->pagesMontrees()($pages)->with('adresse.bibliotheque')])
            ->find((int) $id);

        $collection?->setAttribute('nombre_pages', $collection->pages->count());

        return $collection;
    }
}
