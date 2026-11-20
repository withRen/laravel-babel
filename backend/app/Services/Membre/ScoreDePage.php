<?php

declare(strict_types=1);

namespace App\Services\Membre;

use Illuminate\Support\Facades\DB;

/**
 * Le score d'une page chez nous : la somme des votes (+1 / -1) plus le nombre de marque-pages.
 * C'est exactement la formule des pages populaires (App\Services\Reseau\PagesPopulaires), calculée pour
 * une seule page : un test vérifie que les deux donnent le même nombre.
 */
final class ScoreDePage
{
    public static function de(?int $adresseId): int
    {
        if ($adresseId === null) {
            return 0; // page que personne n'a encore désignée : ni vote ni marque-page
        }

        return (int) DB::table('votes')->where('adresse_id', $adresseId)->sum('valeur')
            + DB::table('marque_pages')->where('adresse_id', $adresseId)->count();
    }
}
