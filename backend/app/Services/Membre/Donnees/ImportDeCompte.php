<?php

declare(strict_types=1);

namespace App\Services\Membre\Donnees;

use App\Models\Adresse;
use App\Models\Annotation;
use App\Models\Collection;
use App\Models\MarquePage;
use App\Models\User;
use App\Services\Membre\Adresses;
use App\Services\Membre\PagesDeCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * M12 : recrée chez nous, pour le membre, ce que son fichier d'export contient. Le fichier a DÉJÀ été vérifié en entier
 * (ValideurDExport) : ici on ne refuse plus rien, on écrit, dans UNE transaction (tout ou rien).
 *
 *  - on ne recrée que les marque-pages, les collections et les annotations (le reste du fichier est informatif) ;
 *  - collections et annotations arrivent PRIVÉES : le membre republie ce qu'il veut, et cela passe par notre modération ;
 *  - les dates d'origine sont gardées (le membre retrouve son historique), les textes trop longs pour nos limites
 *    (titre 100, nom 100, description 1 000) sont coupés plutôt que de faire échouer tout l'import ;
 *  - un marque-page déjà présent sur la même page est FUSIONNÉ, pas recréé (règle 7 : une page ne se garde qu'une fois) ;
 *  - toute adresse qui désigne une AUTRE bibliothèque est marquée « à convertir » : la tâche de fond la lira chez
 *    son origine puis la retrouvera ici (ConvertisseurDAdresses). D'ici là, elle reste lisible chez l'origine (M13).
 */
final class ImportDeCompte
{
    public function __construct(
        private readonly Adresses $adresses,
        private readonly PagesDeCollection $pages,
    ) {}

    /**
     * @param  array<string, mixed>  $fichier  un fichier déjà vérifié par ValideurDExport
     * @return array{importes: array{marque_pages: int, collections: int, annotations: int}, fusionnes: int, a_convertir: int}
     */
    public function importer(User $membre, array $fichier): array
    {
        $resultat = ['importes' => ['marque_pages' => 0, 'collections' => 0, 'annotations' => 0], 'fusionnes' => 0];
        $aConvertir = []; // id d'adresse => true : une adresse compte une fois, combien de fois qu'elle serve

        DB::transaction(function () use ($membre, $fichier, &$resultat, &$aConvertir): void {
            foreach ($fichier['marque_pages'] as $m) {
                $adresse = $this->adresses->trouverOuCreer($m['bibliotheque'], $m['adresse']);
                $existant = $membre->marquePages()->where('adresse_id', $adresse->id)->first();

                if ($existant !== null) {
                    $resultat['fusionnes']++;
                    if ($existant->titre === null && $m['titre'] !== null) {
                        $existant->update(['titre' => mb_substr($m['titre'], 0, 100)]);
                    }

                    continue;
                }

                $marquePage = new MarquePage([
                    'utilisateur_id' => $membre->id,
                    'adresse_id' => $adresse->id,
                    'titre' => $m['titre'] === null ? null : mb_substr($m['titre'], 0, 100),
                    'a_convertir' => ! $adresse->bibliotheque->est_locale,
                ]);
                $marquePage->cree_le = Carbon::parse($m['cree_le']);
                $marquePage->save();

                $resultat['importes']['marque_pages']++;
                $this->noter($aConvertir, $adresse);
            }

            foreach ($fichier['collections'] as $c) {
                $collection = new Collection([
                    'utilisateur_id' => $membre->id,
                    'nom' => mb_substr($c['nom'], 0, 100),
                    'description' => mb_substr($c['description'], 0, 1000),
                    'est_publique' => false,
                ]);
                $collection->cree_le = Carbon::parse($c['cree_le']);
                $collection->save();

                // Les positions du fichier donnent l'ORDRE ; elles sont renumérotées 1, 2, 3… sans trou.
                $pages = $c['pages'];
                usort($pages, fn (array $a, array $b) => $a['position'] <=> $b['position']); // stable depuis PHP 8

                $lignes = [];
                foreach ($pages as $p) {
                    $adresse = $this->adresses->trouverOuCreer($p['bibliotheque'], $p['adresse']);
                    $lignes[] = ['adresse_id' => $adresse->id, 'a_convertir' => ! $adresse->bibliotheque->est_locale];
                    $this->noter($aConvertir, $adresse);
                }
                $this->pages->ecrire($collection, $lignes);

                $resultat['importes']['collections']++;
            }

            foreach ($fichier['annotations'] as $a) {
                $adresse = $this->adresses->trouverOuCreer($a['bibliotheque'], $a['adresse']);

                $annotation = new Annotation([
                    'utilisateur_id' => $membre->id,
                    'adresse_id' => $adresse->id,
                    'contenu' => $a['contenu'],
                    'est_publique' => false,
                    'a_convertir' => ! $adresse->bibliotheque->est_locale,
                ]);
                $annotation->cree_le = Carbon::parse($a['cree_le']);
                $annotation->maj_le = Carbon::parse($a['maj_le']);
                $annotation->save();

                $resultat['importes']['annotations']++;
                $this->noter($aConvertir, $adresse);
            }
        });

        return $resultat + ['a_convertir' => count($aConvertir)];
    }

    /** @param array<int, true> $aConvertir */
    private function noter(array &$aConvertir, Adresse $adresse): void
    {
        if (! $adresse->bibliotheque->est_locale) {
            $aConvertir[$adresse->id] = true;
        }
    }
}
