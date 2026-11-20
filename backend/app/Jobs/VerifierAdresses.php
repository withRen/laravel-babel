<?php

namespace App\Jobs;

use App\Models\Adresse;
use App\Services\Moderation\ServiceBannissement;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Revérifie des adresses enregistrées contre les textes bannis appliqués (levée d'un bannissement, tâche de fond). */
class VerifierAdresses implements ShouldQueue
{
    use Queueable;

    /** @param  list<int>  $ids */
    public function __construct(public array $ids) {}

    public function handle(ServiceBannissement $bannissement): void
    {
        Adresse::query()->whereIn('id', $this->ids)->whereNull('texte_banni_id')->each(
            fn (Adresse $a) => $bannissement->verifier($a)
        );
    }
}
