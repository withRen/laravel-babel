<?php

declare(strict_types=1);

namespace App\Services\Lecture;

use App\Models\Annotation;
use App\Models\User;

/**
 * Le schéma « Annotation » du contrat. Ce qu'on montre de l'auteur : son pseudo, rien d'autre.
 * (La branche « lecture annotée » réutilisera cette forme pour les routes /moi/annotations.)
 */
final class AnnotationPublique
{
    /**
     * Attend les relations `utilisateur` et `adresse.bibliotheque` déjà chargées.
     *
     * @return array<string, mixed>
     */
    public static function depuis(Annotation $annotation, ?User $membre): array
    {
        return [
            'id' => (string) $annotation->id,
            'bibliotheque' => $annotation->adresse->bibliotheque->identifiant,
            'adresse' => $annotation->adresse->ecriture(),
            'contenu' => $annotation->contenu,
            'est_publique' => $annotation->est_publique,
            // Masquée par un administrateur (A4) OU parce que la page contient un texte banni appliqué.
            'masquee' => $annotation->masque_le !== null || $annotation->adresse->texte_banni_id !== null,
            'a_convertir' => false, // renseigné par l'import (M12), pas encore écrit
            'auteur' => ['pseudo' => $annotation->utilisateur->pseudo],
            'est_a_moi' => $membre !== null && $annotation->utilisateur_id === $membre->id,
            'cree_le' => $annotation->cree_le->toIso8601String(),
            'maj_le' => $annotation->maj_le->toIso8601String(),
        ];
    }
}
