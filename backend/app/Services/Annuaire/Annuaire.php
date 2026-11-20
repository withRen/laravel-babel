<?php

declare(strict_types=1);

namespace App\Services\Annuaire;

/**
 * L'annuaire de la plateforme du réseau : s'annoncer, puis récupérer la liste des autres bibliothèques.
 *
 * Le cahier des charges décrit CE que fait l'échange (identifiant + URL du moment, secret d'annonce,
 * liste des pairs en retour), pas le format exact de l'appel. On isole donc l'appel derrière cette
 * interface : le jour où la plateforme publie son format, seule l'implémentation change.
 */
interface Annuaire
{
    /**
     * Annonce notre bibliothèque : son identifiant (stable) et son URL du moment (qui peut changer).
     *
     * @throws ErreurAnnuaire l'annuaire n'est pas configuré, injoignable ou refuse l'annonce
     */
    public function annoncer(string $identifiant, string $url): void;

    /**
     * La liste des bibliothèques que l'annuaire connaît.
     *
     * @return list<PairAnnonce>
     *
     * @throws ErreurAnnuaire
     */
    public function lister(): array;
}
