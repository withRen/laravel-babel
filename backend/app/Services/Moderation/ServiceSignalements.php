<?php

declare(strict_types=1);

namespace App\Services\Moderation;

use App\Enums\StatutSignalement;
use App\Models\Signalement;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * A4 : traiter un signalement. Règle 14 : « masquer, c'est dater » (`masque_le`), rétablir remet la date à nul, et
 * seul un administrateur qui traite un signalement pose cette date ; le signalement garde qui a décidé, et quand.
 */
final class ServiceSignalements
{
    /** Masquer le contenu signalé : le signalement est traité. */
    public function masquer(Signalement $signalement, User $admin): void
    {
        $this->decider($signalement, $admin, StatutSignalement::Traite, now());
    }

    /** Rétablir le contenu : la date repart à nul. Le signalement n'était pas fondé, il passe à « rejete ». */
    public function retablir(Signalement $signalement, User $admin): void
    {
        $this->decider($signalement, $admin, StatutSignalement::Rejete, null);
    }

    /** Rejeter le signalement sans toucher au contenu (qui peut déjà être masqué par un autre signalement). */
    public function rejeter(Signalement $signalement, User $admin): void
    {
        $this->enregistrer($signalement, $admin, StatutSignalement::Rejete);
    }

    /** Le contenu visé : annotation, collection ou lien court (exactement un, garanti par la base). */
    public function contenu(Signalement $signalement): Model
    {
        $signalement->loadMissing(['annotation', 'collection', 'lienCourt']);

        return $signalement->annotation ?? $signalement->collection ?? $signalement->lienCourt;
    }

    private function decider(Signalement $signalement, User $admin, StatutSignalement $statut, mixed $masqueLe): void
    {
        DB::transaction(function () use ($signalement, $admin, $statut, $masqueLe): void {
            $contenu = $this->contenu($signalement);
            $contenu->forceFill(['masque_le' => $masqueLe])->save();
            $this->enregistrer($signalement, $admin, $statut);
        });
    }

    private function enregistrer(Signalement $signalement, User $admin, StatutSignalement $statut): void
    {
        $signalement->forceFill(['statut' => $statut, 'traite_par_id' => $admin->id, 'traite_le' => now()])->save();
    }
}
