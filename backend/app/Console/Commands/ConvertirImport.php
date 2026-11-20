<?php

namespace App\Console\Commands;

use App\Jobs\ConvertirAdressesImportees;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Relance la conversion des adresses importées (M12) pour tous les membres qui en ont encore, quand leur
 * ancienne bibliothèque n'avait pas répondu. Planifiée toutes les 10 minutes (routes/console.php).
 */
#[Signature('babel:convertir-import')]
#[Description("Relance la conversion des adresses importées d'une autre bibliothèque")]
class ConvertirImport extends Command
{
    public function handle(): int
    {
        $membres = DB::table('marque_pages')->where('a_convertir', true)->pluck('utilisateur_id')
            ->merge(DB::table('annotations')->where('a_convertir', true)->pluck('utilisateur_id'))
            ->merge(
                DB::table('collection_pages')
                    ->join('collections', 'collections.id', '=', 'collection_pages.collection_id')
                    ->where('collection_pages.a_convertir', true)
                    ->pluck('collections.utilisateur_id')
            )
            ->unique();

        // Un membre dont la dernière tentative date de moins de 10 minutes est laissé tranquille : une origine
        // éteinte ne doit pas être appelée à chaque passage.
        $aRelancer = User::query()->whereIn('id', $membres)
            ->where(fn ($q) => $q->whereNull('import_tentative_le')->orWhere('import_tentative_le', '<', now()->subMinutes(10)))
            ->pluck('id');

        foreach ($aRelancer as $id) {
            ConvertirAdressesImportees::dispatch($id);
        }

        $this->info("{$aRelancer->count()} membre(s) à convertir.");

        return self::SUCCESS;
    }
}
