<?php

namespace App\Http\Resources\Api;

use App\Enums\EtatBibliotheque;
use App\Models\Bibliotheque;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Schéma « BibliothequeReseau » : une ligne de la liste des bibliothèques (V15, V16, V21, M13).
 * Aucune URL de pair ici : l'application web ne parle jamais directement aux pairs, elle passe par notre serveur.
 *
 * @mixin Bibliotheque
 */
class BibliothequeReseauResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $locale = (bool) $this->est_locale;

        return [
            'identifiant' => $this->identifiant,
            'nom' => $this->nom,
            'description' => $this->description ?? '', // le contrat : chaîne vide, jamais null
            'est_locale' => $locale,
            // La nôtre est toujours en ligne : on ne se contrôle pas soi-même.
            'etat' => $locale ? EtatBibliotheque::EnLigne->value : $this->etat->value,
            'dernier_contact_le' => $locale ? null : $this->dernier_contact_le?->toIso8601String(),
            'cle_changee_le' => $locale ? null : $this->cle_changee_le?->toIso8601String(),
            // Les pairs publient leur application web dans leur identité, mais le modèle de données ne la garde pas.
            'application_web' => $locale ? config('babel.frontend_url') : null,
        ];
    }
}
