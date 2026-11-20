<?php

declare(strict_types=1);

namespace App\Services\Membre;

use App\Exceptions\ErreurApi;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Retrouve UNE ressource parmi celles du membre connecté. On part toujours de la relation du membre
 * (« $membre->annotations() »), jamais de la table entière : la ressource d'un autre membre n'est
 * donc pas « interdite », elle n'existe pas pour lui (404, jamais 403 : on ne laisse rien deviner).
 */
final class Possession
{
    /**
     * @template TModele of Model
     *
     * @param  HasMany<TModele, *>  $ressources
     * @return TModele
     */
    public static function trouver(HasMany $ressources, string $id, string $champ = 'id'): Model
    {
        // Un id qui n'est pas un entier décimal n'existe pas : pas de conversion hasardeuse en SQL
        // (« 12abc » ne doit pas devenir 12) ni de débordement au-delà d'un entier 64 bits.
        if (! ctype_digit($id) || strlen($id) > 18) {
            throw self::introuvable($champ);
        }

        return $ressources->whereKey((int) $id)->first() ?? throw self::introuvable($champ);
    }

    public static function introuvable(string $champ = 'id'): ErreurApi
    {
        return ErreurApi::nonTrouve("Cette ressource n'existe pas ou n'est pas visible.", $champ);
    }
}
