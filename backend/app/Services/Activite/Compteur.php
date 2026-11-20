<?php

declare(strict_types=1);

namespace App\Services\Activite;

use Illuminate\Support\Facades\DB;

/**
 * Compteurs anonymes par jour (règle 21) : une ligne par jour, jamais une ligne par lecture.
 * On ne garde ni qui a lu, ni quoi : seulement « combien ».
 */
final class Compteur
{
    public const PAGES_LUES = 'pages_lues';

    public const RECHERCHES = 'recherches';

    /**
     * Ajoute 1 au compteur du jour, en une seule requête atomique : si la ligne du jour n'existe pas elle est
     * créée avec 1, sinon la valeur existante est augmentée dans la base (deux requêtes simultanées ne se
     * marchent pas dessus, ce qu'un « lire puis écrire » en PHP ne garantirait pas).
     */
    public function incrementer(string $colonne): void
    {
        if (! in_array($colonne, [self::PAGES_LUES, self::RECHERCHES], true)) {
            throw new \InvalidArgumentException("Compteur inconnu : {$colonne}");
        }

        DB::table('activites_jour')->upsert(
            [[
                'jour' => now()->toDateString(),
                self::PAGES_LUES => $colonne === self::PAGES_LUES ? 1 : 0,
                self::RECHERCHES => $colonne === self::RECHERCHES ? 1 : 0,
            ]],
            ['jour'],
            [$colonne => DB::raw("{$colonne} + 1")],
        );
    }
}
