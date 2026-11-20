<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Bibliotheque;
use App\Models\Reglage;
use App\Services\Engine\Bibliotheque as DimensionsBibliotheque;
use Babel\Moteur\Moteur;
use Illuminate\Http\JsonResponse;

/**
 * GET /app/infos : tout ce dont l'application web a besoin au démarrage (À propos V20, inscriptions V19,
 * constantes pour `voisine` F6, limites de saisie, paramètres du temps réel).
 */
class InfosController extends Controller
{
    /** Durée maximale d'un lien court créé sans compte, tant que l'administrateur n'a pas choisi (réglage). */
    public const DUREE_LIEN_VISITEUR_JOURS = 7;

    public function __invoke(Moteur $moteur, DimensionsBibliotheque $dimensions): JsonResponse
    {
        $locale = Bibliotheque::locale();

        return response()->json([
            'identifiant' => $locale?->identifiant ?? config('babel.identifiant'),
            'nom' => $locale?->nom ?? config('babel.nom'),
            'description' => $locale?->description ?? '',
            'empreinte_cle' => $moteur->empreinte(), // l'empreinte seule, jamais la clé
            'algorithme' => Moteur::ALGORITHME,
            'contrat' => IdentiteController::VERSION_CONTRAT,
            'application_web' => config('babel.frontend_url'),
            'inscriptions_ouvertes' => Reglage::inscriptionsOuvertes(),
            // Les constantes viennent du moteur : les mêmes dans les 10 bibliothèques, rien n'est recopié.
            'constantes' => [
                'alphabet' => Moteur::ALPHABET,
                'longueur_page' => Moteur::LONGUEUR_PAGE,
                'murs' => Moteur::MURS,
                'etageres' => Moteur::ETAGERES,
                'volumes' => Moteur::VOLUMES,
                'pages' => Moteur::PAGES,
                'longueur_max_hexagone' => Moteur::LONGUEUR_MAX_HEXAGONE,
                'dernier_hexagone' => gmp_strval($dimensions->dernierHexagone, 36),
            ],
            'limites' => [
                'taille_lot_max' => 50,
                'annotation_max' => 2000,
                'motif_signalement_max' => 500,
                'nom_collection_max' => 100,
                'description_collection_max' => 1000,
                'pseudo_max' => 30,
                'mot_de_passe_min' => 8,
                'duree_lien_visiteur_jours' => $this->dureeLienVisiteur(),
                'import_max_octets' => 10485760,
            ],
            'reseau' => ['delai_pair_ms' => (int) config('babel.pair_delai', 2) * 1000],
            'temps_reel' => $this->tempsReel(),
        ]);
    }

    private function dureeLienVisiteur(): int
    {
        $jours = Reglage::valeur('duree_lien_visiteur_jours');

        return $jours !== null && ctype_digit($jours) && (int) $jours >= 1 ? (int) $jours : self::DUREE_LIEN_VISITEUR_JOURS;
    }

    /**
     * Les paramètres de connexion à Reverb pour le navigateur, ou null quand le temps réel est coupé
     * (l'application web interroge alors GET /app/recherches-reseau/{identifiant}). La clé est publique.
     *
     * @return array{protocole: string, cle: string, hote: string, port: int, tls: bool}|null
     */
    private function tempsReel(): ?array
    {
        $cle = config('reverb.apps.apps.0.key');
        $hote = config('babel.temps_reel.hote');

        if (config('broadcasting.default') !== 'reverb' || blank($cle) || blank($hote)) {
            return null;
        }

        return [
            'protocole' => 'pusher',
            'cle' => (string) $cle,
            'hote' => (string) $hote,
            'port' => (int) config('babel.temps_reel.port'),
            'tls' => (bool) config('babel.temps_reel.tls'),
        ];
    }
}
