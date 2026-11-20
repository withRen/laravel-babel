<?php

namespace App\Http\Resources\Api;

use App\Models\Annotation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Schéma « Annotation ». Attend « adresse.bibliotheque » et « utilisateur » chargées.
 *
 * « masquee » vaut vrai si un administrateur l'a masquée (règle 14) OU si sa page contient un texte banni
 * appliqué (règle 18) : seul l'auteur voit alors l'annotation. « auteur » ne montre que le pseudo.
 *
 * @mixin Annotation
 */
class AnnotationMembreResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'bibliotheque' => $this->adresse->bibliotheque->identifiant,
            'adresse' => $this->adresse->ecriture(),
            'contenu' => $this->contenu,
            'est_publique' => $this->est_publique,
            'masquee' => $this->masque_le !== null || $this->adresse->texte_banni_id !== null,
            'a_convertir' => $this->a_convertir,
            'auteur' => ['pseudo' => $this->utilisateur->pseudo],
            'est_a_moi' => $request->user()?->getKey() === $this->utilisateur_id,
            'cree_le' => $this->cree_le->toIso8601String(),
            'maj_le' => $this->maj_le->toIso8601String(),
        ];
    }
}
