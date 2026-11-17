<?php

declare(strict_types=1);

namespace App\Services\Reseau;

use App\Services\Engine\ResultatValidation;
use App\Services\Engine\Valideur;
use Babel\Moteur\Erreur\CodeErreur;
use Babel\Moteur\Erreur\ErreurMoteur;
use Babel\Moteur\Moteur;

/**
 * F1 côté serveur : lire la page à une adresse (B1), sans jamais corriger l'adresse.
 *
 * Le Valideur est la porte d'entrée : seule une adresse de PAGE valide (bien écrite, qui existe)
 * arrive jusqu'au moteur. Quand il refuse, la liste d'erreurs est celle du moteur de référence
 * (mêmes champs, même ordre hexagone, mur, étagère, volume, page, mêmes messages) : on la lui
 * demande plutôt que de la recopier, pour qu'elle ne diverge jamais du contrat.
 */
final class LecteurDePages
{
    public function __construct(private readonly Moteur $moteur, private readonly Valideur $valideur) {}

    /**
     * @throws ErreurMoteur l'adresse est refusée (toutes les erreurs d'un coup)
     */
    public function lire(string $adresse): string
    {
        $validation = $this->valideur->valider($adresse);

        if ($validation->valide && $validation->niveau === ResultatValidation::NIVEAU_PAGE) {
            return $this->moteur->lire($adresse);
        }

        // Refusée, ou adresse de livre (sans « :<page> ») : le moteur lève les erreurs du contrat.
        $this->moteur->lire($adresse);

        // Garde-fou : le moteur et le Valideur ne devraient jamais se contredire.
        throw $validation->valide
            ? ErreurMoteur::une(CodeErreur::AdresseMalEcrite, 'page')
            : new ErreurMoteur($validation->erreurs);
    }
}
