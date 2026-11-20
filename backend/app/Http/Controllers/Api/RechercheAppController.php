<?php

namespace App\Http\Controllers\Api;

use App\Enums\ModeRecherche;
use App\Enums\PorteeRecherche;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\RechercheRequest;
use App\Models\Bibliotheque;
use App\Models\Recherche;
use App\Services\Activite\Compteur;
use App\Services\Engine\Normaliseur;
use Babel\Moteur\Moteur;
use Illuminate\Http\JsonResponse;

/**
 * POST /app/recherches (F2, V4, V5, V6, M8) : même traitement que POST /recherches, mais l'application web
 * connaît le membre. Un membre retrouve la recherche dans son historique ; un visiteur ne laisse qu'un compteur.
 */
class RechercheAppController extends Controller
{
    public function __invoke(RechercheRequest $requete, Normaliseur $normaliseur, Moteur $moteur, Compteur $compteur): JsonResponse
    {
        $mode = $requete->mode();

        // Comme la porte réseau : on normalise TOUJOURS avant le moteur (étapes 1 à 3 seulement en mode « page »),
        // puis le moteur lève l'unique erreur du contrat (mode, texte vide, longueur, dans cet ordre).
        $texte = $normaliseur->normaliser($requete->texte(), etapesDePage: $mode === 'page');
        $adresse = $moteur->chercher($texte, $mode);

        $compteur->incrementer(Compteur::RECHERCHES);

        // L'historique ne connaît que les modes de l'interface (exact, bruit : V5). Le mode « page » sert
        // à retrouver l'adresse d'une page entière (import) et ne s'enregistre pas.
        $membre = $requete->user();
        $modeHistorique = ModeRecherche::tryFrom($mode);
        if ($membre !== null && $modeHistorique !== null) {
            Recherche::query()->create([
                'utilisateur_id' => $membre->id,
                'texte' => $texte, // le texte normalisé, tel qu'il a été cherché
                'mode' => $modeHistorique,
                'portee' => PorteeRecherche::Locale,
            ]);
        }

        return response()->json([
            'bibliotheque' => Bibliotheque::locale()?->identifiant ?? config('babel.identifiant'),
            'texte_normalise' => $texte,
            'mode' => $mode,
            'adresse' => $adresse,
        ]);
    }
}
