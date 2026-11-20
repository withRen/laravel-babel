<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ErreurApi;
use App\Http\Controllers\Controller;
use App\Jobs\ConvertirAdressesImportees;
use App\Services\Membre\Adresses;
use App\Services\Membre\Donnees\ConvertisseurDAdresses;
use App\Services\Membre\Donnees\ExportDeCompte;
use App\Services\Membre\Donnees\ImportDeCompte;
use App\Services\Membre\Donnees\ValideurDExport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Les données du membre (M11, M12) : l'export, l'import, et l'avancement de la conversion des adresses importées.
 * Les comptes sont locaux : on ne migre pas un compte entre bibliothèques, on emporte ses données (cahier des charges).
 */
class DonneesController extends Controller
{
    /** Taille maximale d'un fichier d'import : 10 Mo (contrat). */
    private const TAILLE_MAX = 10 * 1024 * 1024;

    /** M11 : télécharge toutes les données du membre, au format commun « babel-export/v1 ». */
    public function exporter(Request $requete, ExportDeCompte $export, Adresses $adresses): JsonResponse
    {
        $fichier = $export->generer($requete->user());
        $nom = sprintf('babel-export-%s-%s.json', $adresses->locale()->identifiant, now()->format('Y-m-d'));

        return response()->json($fichier, 200, ['Content-Disposition' => 'attachment; filename="'.$nom.'"'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * M12 : importe un fichier d'export. Le fichier est lu BRUT (pas via les entrées de la requête) : Laravel retirerait
     * les espaces en bord de chaque texte et changerait les contenus importés. Il est vérifié en entier AVANT d'écrire quoi
     * que ce soit ; la conversion des adresses d'une autre bibliothèque est confiée à une tâche de fond (202).
     */
    public function importer(Request $requete, ValideurDExport $valideur, ImportDeCompte $import): JsonResponse
    {
        $contenu = $requete->getContent();

        if (strlen($contenu) > self::TAILLE_MAX) {
            throw ErreurApi::une(422, 'ERR_INVALID_IMPORT', 'Le fichier dépasse 10 Mo.');
        }

        try {
            $fichier = json_decode($contenu, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw ErreurApi::une(422, 'ERR_INVALID_IMPORT', "Le fichier n'est pas un JSON valide.");
        }

        $erreurs = $valideur->erreurs($fichier);
        if ($erreurs !== []) {
            throw new ErreurApi(422, $erreurs);
        }

        $resultat = $import->importer($requete->user(), $fichier);

        if ($resultat['a_convertir'] > 0) {
            ConvertirAdressesImportees::dispatch($requete->user()->id)->afterResponse();
        }

        return response()->json($resultat, 202);
    }

    /** M12 : combien d'adresses importées restent à convertir, et quand la dernière tentative a eu lieu. */
    public function etatImport(Request $requete, ConvertisseurDAdresses $convertisseur): JsonResponse
    {
        return response()->json([
            'a_convertir' => $convertisseur->restantes($requete->user()),
            'derniere_tentative_le' => $requete->user()->import_tentative_le?->toIso8601String(),
        ]);
    }
}
