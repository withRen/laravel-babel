<?php

declare(strict_types=1);

namespace App\Services\Reseau;

use App\Enums\StatutTexteBanni;
use App\Models\Bibliotheque;
use App\Models\TexteBanni;
use App\Services\Engine\Normaliseur;
use Illuminate\Support\Facades\Log;

/**
 * Relever les textes bannis d'un pair (B5, A7).
 *
 * Chaque bibliothèque décide chez elle : un texte relevé chez un pair arrive ici comme « proposé » et ne masque RIEN
 * tant que l'administrateur ne l'a pas appliqué. On ne republie jamais un texte venu d'ailleurs (la route
 * GET /textes-bannis ne sert que les textes d'origine locale).
 *
 *  - un texte déjà connu (même empreinte) n'est pas retouché, quel que soit son statut : un texte que l'administrateur a
 *    rejeté ne revient pas en « proposé » au relevé suivant ;
 *  - les textes qu'un pair ne publie plus ne sont pas retirés : appliqué ou non, c'est notre décision ;
 *  - le jeton réseau ne part que vers GET /textes-bannis (ClientPair), et il vient de la configuration du serveur.
 */
final class ReleveDesTextesBannis
{
    public function __construct(private readonly ClientPair $client, private readonly Normaliseur $normaliseur) {}

    /**
     * @return int le nombre de textes nouveaux, proposés à l'administrateur
     *
     * @throws ErreurPair le pair n'a pas répondu, est occupé ou a répondu hors contrat
     */
    public function relever(Bibliotheque $pair): int
    {
        $jeton = config('babel.network_token');
        if (blank($jeton)) {
            Log::warning('Relevé des textes bannis impossible : BABEL_NETWORK_TOKEN est vide.');

            return 0; // sans jeton, la route du pair refuserait : on ne l'appelle pas pour rien
        }

        $liste = $this->client->textesBannis($pair, (string) $jeton);
        if ($liste === null) {
            // 401 : c'est NOTRE jeton qui est refusé, pas une panne du pair.
            Log::warning("Le pair {$pair->identifiant} refuse notre jeton réseau.");

            return 0;
        }

        $nouveaux = 0;
        foreach ($liste['elements'] as $element) {
            // Déjà normalisé selon le contrat ; on le repasse par F3 (idempotente) : ce qu'on stocke est à nous.
            $texte = $this->normaliseur->normaliser($element['texte']);
            if ($texte === '') {
                continue;
            }

            // insertOrIgnore : atomique, l'unicité de l'empreinte fait foi (deux relevés en même temps ne doublonnent pas).
            $nouveaux += TexteBanni::query()->insertOrIgnore([
                'texte' => $texte,
                'empreinte' => hash('sha256', $texte),
                'motif' => $element['motif'],
                'origine_id' => $pair->id,
                'statut' => StatutTexteBanni::Propose->value,
                'cree_le' => now(),
            ]);
        }

        return $nouveaux;
    }
}
