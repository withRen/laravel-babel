<?php

declare(strict_types=1);

namespace App\Services\Reseau;

use App\Models\Adresse;
use Illuminate\Database\Eloquent\Collection;

/**
 * Les pages les plus populaires (B4).
 *
 * Le score est un total anonyme : la somme des votes (+1 ou -1) plus le nombre de marque-pages.
 * Seul le total sort de la base : personne n'est nommé. Une page à 0 ou moins n'est pas
 * « populaire » : elle n'apparaît pas. Les pages marquées par un texte banni appliqué non plus.
 * À score égal, l'id donne un ordre stable (deux appels successifs ne se contredisent pas).
 */
final class PagesPopulaires
{
    private const SCORE = '(select coalesce(sum(votes.valeur), 0) from votes where votes.adresse_id = adresses.id)'
        .' + (select count(*) from marque_pages where marque_pages.adresse_id = adresses.id)';

    /** @return Collection<int, Adresse> chaque adresse porte l'attribut « score » */
    public function lister(int $taille): Collection
    {
        return Adresse::query()
            ->with('bibliotheque')
            ->select('adresses.*')
            ->selectRaw(self::SCORE.' as score')
            ->whereNotNull('page')
            ->whereNull('texte_banni_id')
            ->whereRaw('('.self::SCORE.') > 0')
            ->orderByDesc('score')
            ->orderBy('adresses.id')
            ->limit($taille)
            ->get();
    }
}
