<?php

declare(strict_types=1);

namespace App\Services\Membre;

use App\Models\Collection;
use App\Models\CollectionPage;
use Illuminate\Support\Facades\DB;

/**
 * Les pages d'une collection, vues comme une LISTE ordonnée (règle 8).
 *
 * La clé de la table est (collection_id, position). Insérer au milieu ou retirer au milieu change la position de
 * toutes les suivantes : plutôt que de décaler ligne à ligne (la clé unique se plaindrait à chaque pas, selon
 * l'ordre dans lequel la base traite les lignes), on lit la liste, on la modifie en mémoire, puis on la réécrit
 * entière dans une transaction, renumérotée 1, 2, 3… sans trou. Même méthode pour ajouter, retirer et réordonner.
 */
final class PagesDeCollection
{
    /**
     * @return list<array{adresse_id: int, a_convertir: bool}> les pages, par position croissante
     */
    public function lignes(Collection $collection): array
    {
        return CollectionPage::query()
            ->where('collection_id', $collection->id)
            ->orderBy('position')
            ->get(['adresse_id', 'a_convertir'])
            ->map(fn (CollectionPage $p) => ['adresse_id' => $p->adresse_id, 'a_convertir' => (bool) $p->a_convertir])
            ->all();
    }

    /**
     * Remplace toutes les pages par $lignes, dans cet ordre, et date la modification de la collection.
     *
     * @param  list<array{adresse_id: int, a_convertir: bool}>  $lignes
     */
    public function ecrire(Collection $collection, array $lignes): void
    {
        DB::transaction(function () use ($collection, $lignes): void {
            CollectionPage::query()->where('collection_id', $collection->id)->delete();

            $nouvelles = [];
            foreach (array_values($lignes) as $indice => $ligne) {
                $nouvelles[] = [
                    'collection_id' => $collection->id,
                    'position' => $indice + 1,
                    'adresse_id' => $ligne['adresse_id'],
                    'a_convertir' => $ligne['a_convertir'],
                ];
            }

            // Par paquets : SQLite limite le nombre de paramètres d'une requête.
            foreach (array_chunk($nouvelles, 200) as $paquet) {
                CollectionPage::query()->insert($paquet);
            }

            $collection->touch(); // « maj_le » : la liste des collections va de la plus récemment modifiée à la plus ancienne
        });
    }
}
