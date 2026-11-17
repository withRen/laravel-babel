<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\IdentiteResource;
use App\Models\Bibliotheque;
use Babel\Moteur\Moteur;

/** GET /identite (B6, A9) : qui sommes-nous, et notre clé a-t-elle changé ? */
class IdentiteController extends Controller
{
    /** La version du contrat d'API (docs/openapi.yaml) que cette bibliothèque respecte. */
    public const VERSION_CONTRAT = '1.1.0';

    public function __invoke(Moteur $moteur): IdentiteResource
    {
        // Nom et description se modifient dans le back-office (A8) : ils viennent de la ligne locale
        // de la table bibliotheques. Si elle manque (base pas encore amorcée), la configuration répond.
        $locale = Bibliotheque::locale();

        return new IdentiteResource([
            'identifiant' => $locale?->identifiant ?? config('babel.identifiant'),
            'nom' => $locale?->nom ?? config('babel.nom'),
            'description' => $locale?->description ?? '', // le contrat : chaîne vide, jamais null
            // L'empreinte seule sort, jamais la clé ; on la donne « telle quelle » (F8), sans la retoucher.
            'empreinte_cle' => $moteur->empreinte(),
            'algorithme' => Moteur::ALGORITHME,
            'contrat' => self::VERSION_CONTRAT,
            'application_web' => config('babel.frontend_url'),
        ]);
    }
}
