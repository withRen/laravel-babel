<?php

namespace App\Console\Commands;

use App\Models\Adresse;
use App\Models\Bibliotheque;
use App\Services\Moderation\ServiceBannissement;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/** Tâche de fond de la modération (étape 2) : relit les adresses enregistrées ici et marque celles qui contiennent un texte appliqué. */
#[Signature('babel:verifier-bannis')]
#[Description('Relire les adresses enregistrées et marquer celles qui contiennent un texte banni appliqué')]
class VerifierBannis extends Command
{
    public function handle(ServiceBannissement $bannissement): int
    {
        $locale = Bibliotheque::locale();
        $marquees = 0;
        $lues = 0;

        if ($locale !== null) {
            Adresse::query()->where('bibliotheque_id', $locale->id)->whereNull('texte_banni_id')
                ->each(function (Adresse $a) use ($bannissement, &$marquees, &$lues) {
                    $lues++;
                    $marquees += $bannissement->verifier($a) ? 1 : 0;
                });
        }

        $this->info("$lues adresse(s) relue(s), $marquees marquée(s).");

        return self::SUCCESS;
    }
}
