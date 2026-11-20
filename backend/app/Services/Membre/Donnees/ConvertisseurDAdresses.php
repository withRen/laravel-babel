<?php

declare(strict_types=1);

namespace App\Services\Membre\Donnees;

use App\Models\Adresse;
use App\Models\Collection;
use App\Models\CollectionPage;
use App\Models\User;
use App\Services\Membre\Adresses;
use App\Services\Membre\LecteurDePageDistante;
use Babel\Moteur\Erreur\ErreurMoteur;
use Babel\Moteur\Moteur;
use Illuminate\Support\Facades\DB;

/**
 * M12, règle 20 : la tâche de fond qui convertit les adresses importées d'une autre bibliothèque.
 *
 * Une adresse n'a de sens que dans SA bibliothèque : la même suite de coordonnées donne un autre texte chez nous.
 * Pour retrouver « la même page », on lit le TEXTE chez l'origine, puis on demande à notre moteur l'adresse de
 * ce texte avec le mode « page » (3 200 caractères exactement). Le marque-page, la page de collection ou
 * l'annotation désigne alors NOTRE bibliothèque et plus rien ne dépend de l'ancienne (qui peut fermer).
 *
 * Tant que l'origine ne répond pas, rien ne change : l'adresse garde sa bibliothèque d'origine et reste
 * « à convertir » (la page s'ouvre quand même chez l'origine, M13). On réessaie au passage suivant.
 * Si la conversion fait apparaître deux marque-pages sur la même page, ils sont fusionnés (règle 7).
 */
final class ConvertisseurDAdresses
{
    public function __construct(
        private readonly Moteur $moteur,
        private readonly Adresses $adresses,
        private readonly LecteurDePageDistante $distant,
    ) {}

    /** Combien d'adresses distinctes restent à convertir pour ce membre (GET /moi/import). */
    public function restantes(User $membre): int
    {
        return count($this->aConvertir($membre));
    }

    /** @return int le nombre d'adresses converties pendant ce passage */
    public function convertir(User $membre): int
    {
        $anciennes = $this->aConvertir($membre);
        if ($anciennes === []) {
            return 0;
        }

        $converties = 0;

        foreach (Adresse::query()->with('bibliotheque')->whereIn('id', $anciennes)->get() as $ancienne) {
            $texte = $this->distant->lire($ancienne->bibliotheque, $ancienne->ecriture());
            if ($texte === null) {
                continue; // l'origine ne répond pas, ou pas dans les clous : au prochain passage
            }

            try {
                $nouvelle = $this->adresses->trouverOuCreer(null, $this->moteur->chercher($texte, 'page'));
            } catch (ErreurMoteur) {
                continue; // une page qui ne se retrouve pas ici ne doit pas bloquer les autres
            }

            DB::transaction(fn () => $this->remplacer($membre, $ancienne, $nouvelle));
            $converties++;
        }

        // « Dernière tentative » même quand rien n'a abouti : c'est ce que GET /moi/import montre.
        $membre->forceFill(['import_tentative_le' => now()])->save();

        return $converties;
    }

    /** Les adresses (ids) que ce membre a importées et qui désignent encore l'ancienne bibliothèque. */
    public function aConvertir(User $membre): array
    {
        $ids = DB::table('marque_pages')->where('utilisateur_id', $membre->id)->where('a_convertir', true)->pluck('adresse_id')
            ->merge(DB::table('annotations')->where('utilisateur_id', $membre->id)->where('a_convertir', true)->pluck('adresse_id'))
            ->merge(
                DB::table('collection_pages')
                    ->join('collections', 'collections.id', '=', 'collection_pages.collection_id')
                    ->where('collections.utilisateur_id', $membre->id)
                    ->where('collection_pages.a_convertir', true)
                    ->pluck('collection_pages.adresse_id')
            );

        return $ids->unique()->values()->all();
    }

    private function remplacer(User $membre, Adresse $ancienne, Adresse $nouvelle): void
    {
        // Marque-pages : si le membre garde DÉJÀ la page d'ici, l'importé disparaît (fusion).
        $dejaGardee = $membre->marquePages()->where('adresse_id', $nouvelle->id)->exists();
        $importes = $membre->marquePages()->where('adresse_id', $ancienne->id)->where('a_convertir', true);

        if ($dejaGardee) {
            $importes->delete();
        } else {
            // Si l'import contenait deux fois la même page d'ailleurs (deux adresses d'origine qui mènent ici),
            // un seul marque-page survit : l'unicité (membre, adresse) l'impose. On garde le plus ancien.
            $gardes = (clone $importes)->orderBy('cree_le')->orderBy('id')->get();
            foreach ($gardes->slice(1) as $doublon) {
                $doublon->delete();
            }
            $gardes->first()?->update(['adresse_id' => $nouvelle->id, 'a_convertir' => false]);
        }

        $membre->annotations()->where('adresse_id', $ancienne->id)->where('a_convertir', true)
            ->update(['adresse_id' => $nouvelle->id, 'a_convertir' => false]);

        // « whereKey » : les pages des collections de CE membre. Les positions ne bougent pas.
        CollectionPage::query()
            ->whereIn('collection_id', Collection::query()->where('utilisateur_id', $membre->id)->select('id'))
            ->where('adresse_id', $ancienne->id)->where('a_convertir', true)
            ->update(['adresse_id' => $nouvelle->id, 'a_convertir' => false]);
    }
}
