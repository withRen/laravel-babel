<?php

declare(strict_types=1);

namespace App\Services\Signalements;

use App\Enums\StatutSignalement;
use App\Exceptions\ErreurApi;
use App\Models\Annotation;
use App\Models\Collection;
use App\Models\LienCourt;
use App\Models\Signalement;
use App\Models\User;

/**
 * Signaler un contenu publié (V17) : une annotation publique, une collection publique ou un lien court
 * de CETTE bibliothèque. Jamais une page (livrable 1) : on signale ce qui est publié, pas ce qui existe.
 *
 * Ouvert aux visiteurs (auteur nul). Un membre qui signale deux fois le même contenu retrouve son signalement
 * (200), il n'en crée pas un second.
 */
final class ServiceSignalements
{
    /**
     * @return array{signalement: Signalement, cree: bool}
     */
    public function signaler(string $type, string $id, string $motif, ?User $membre): array
    {
        // Colonne de clé étrangère du signalement selon le type, et identifiant interne du contenu.
        [$colonne, $cibleId] = match ($type) {
            'annotation' => ['annotation_id', $this->annotationSignalable($id)],
            'collection' => ['collection_id', $this->collectionSignalable($id)],
            'lien_court' => ['lien_court_id', $this->lienSignalable($id)],
        };

        if ($membre !== null) {
            $existant = Signalement::query()
                ->where('auteur_id', $membre->id)
                ->where($colonne, $cibleId)
                ->first();

            if ($existant !== null) {
                return ['signalement' => $this->avecCible($existant), 'cree' => false];
            }
        }

        $signalement = Signalement::query()->create([
            $colonne => $cibleId,
            'auteur_id' => $membre?->id,
            'motif' => $motif,
            'statut' => StatutSignalement::EnAttente,
        ]);

        return ['signalement' => $this->avecCible($signalement), 'cree' => true];
    }

    /** Une annotation publique et non masquée : ce que le public voit. Une privée ou masquée répond comme une inconnue. */
    private function annotationSignalable(string $id): int
    {
        $annotation = $this->entier($id) === null ? null : Annotation::query()
            ->whereKey($this->entier($id))
            ->where('est_publique', true)
            ->whereNull('masque_le')
            ->first();

        return $annotation?->id ?? throw $this->introuvable();
    }

    private function collectionSignalable(string $id): int
    {
        $collection = $this->entier($id) === null ? null : Collection::query()
            ->whereKey($this->entier($id))
            ->where('est_publique', true)
            ->whereNull('masque_le')
            ->first();

        return $collection?->id ?? throw $this->introuvable();
    }

    private function lienSignalable(string $slug): int
    {
        $lien = LienCourt::query()->where('slug', $slug)->whereNull('masque_le')->first();

        return $lien?->id ?? throw $this->introuvable();
    }

    /** Un id n'est qu'une suite de chiffres : « 12abc » n'est pas 12, et un nombre géant n'est pas un id. */
    private function entier(string $id): ?int
    {
        return ctype_digit($id) && strlen($id) <= 18 ? (int) $id : null;
    }

    private function introuvable(): ErreurApi
    {
        return ErreurApi::nonTrouve("Ce contenu n'existe pas ou n'est pas visible.", 'cible');
    }

    /** Charge la cible pour que la ressource puisse écrire « {type, id} » (id d'un lien court = son slug). */
    private function avecCible(Signalement $signalement): Signalement
    {
        return $signalement->load('lienCourt');
    }
}
