<?php

declare(strict_types=1);

namespace App\Services\Reseau;

use App\Enums\EtatBibliotheque;
use App\Events\RechercheReseauResultat;
use App\Events\RechercheReseauTerminee;
use App\Exceptions\ErreurApi;
use App\Jobs\InterrogerPairPourRecherche;
use App\Models\Bibliotheque;
use App\Models\Recherche;
use App\Models\User;
use App\Services\Engine\Normaliseur;
use Babel\Moteur\Erreur\ErreurMoteur;
use Babel\Moteur\Moteur;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * La recherche sur tout le réseau (V15), selon le diagramme de séquence du livrable 4.
 *
 *  1. normaliser le texte une seule fois (F3) et le chercher chez nous avec le moteur ;
 *  2. lister les pairs : en ligne = interrogés, tous les autres = absents (jamais appelés) ;
 *  3. garder l'état de la recherche en cache (pas en base) : il reste lisible au moins 10 minutes ;
 *  4. déposer UNE tâche par pair (InterrogerPairPourRecherche) puis répondre tout de suite (202) ;
 *  5. chaque tâche publie son résultat, et la dernière publie le bilan `terminee`.
 *
 * Le cache est la source de vérité : le temps réel (Reverb) n'est qu'une notification. Si Reverb est éteint, l'application
 * web lit GET /app/recherches-reseau/{identifiant} et obtient les mêmes résultats.
 */
final class RechercheReseau
{
    public function __construct(private readonly Normaliseur $normaliseur, private readonly Moteur $moteur) {}

    /**
     * @return array<string, mixed> le corps de la réponse 202 (schéma `RechercheReseauLancee`)
     *
     * @throws ErreurMoteur le texte ou le mode est refusé (une seule erreur, 422)
     * @throws ErreurApi 409 : cet identifiant sert déjà
     */
    public function lancer(string $identifiant, string $texte, string $mode, ?User $membre): array
    {
        $normalise = $this->normaliseur->normaliser($texte, etapesDePage: $mode === 'page');
        // Le moteur contrôle dans l'ordre mode, texte vide, longueur, et lève l'unique erreur du contrat.
        $adresse = $this->moteur->chercher($normalise, $mode);

        $pairs = Bibliotheque::query()->where('est_locale', false)->orderBy('identifiant')->get();
        $interroges = $pairs->filter(fn (Bibliotheque $p) => $p->etat === EtatBibliotheque::EnLigne)->values();
        $absents = $pairs->reject(fn (Bibliotheque $p) => $p->etat === EtatBibliotheque::EnLigne)->pluck('identifiant')->values()->all();

        $lancee = [
            'identifiant' => $identifiant,
            'texte_normalise' => $normalise,
            'mode' => $mode,
            'locale' => [
                'bibliotheque' => Bibliotheque::locale()?->identifiant ?? config('babel.identifiant'),
                'adresse' => $adresse,
            ],
            'interroges' => $interroges->pluck('identifiant')->all(),
            'absents' => $absents,
            'delai_pair_ms' => (int) config('babel.pair_delai', 2) * 1000,
            'canal' => 'recherche-reseau.'.$identifiant,
        ];

        // `add` est atomique : deux lancements avec le même identifiant ne passent pas tous les deux.
        $etat = [
            'identifiant' => $identifiant,
            'texte_normalise' => $normalise,
            'mode' => $mode,
            'locale' => $lancee['locale'],
            'interroges' => $lancee['interroges'],
            'absents' => $absents,
            'resultats' => [],
            'lancee_le' => now()->toIso8601String(),
            'en_attente' => $interroges->count(),
        ];
        if (! Cache::add(self::cle($identifiant), $etat, $this->duree())) {
            throw ErreurApi::dejaExistant('identifiant', 'Une recherche utilise déjà cet identifiant.');
        }

        // M8 : seul un membre garde son historique, et seulement en mode exact ou bruit (le mode page est un outil).
        if ($membre !== null && in_array($mode, ['exact', 'bruit'], true)) {
            Recherche::create(['utilisateur_id' => $membre->id, 'texte' => $normalise, 'mode' => $mode, 'portee' => 'reseau']);
        }

        foreach ($interroges as $pair) {
            InterrogerPairPourRecherche::dispatch($identifiant, $pair->id, $normalise, $mode);
        }

        if ($interroges->isEmpty()) {
            // Aucune tâche ne publiera le bilan : on le publie tout de suite (le navigateur est déjà abonné).
            $this->diffuser(new RechercheReseauTerminee($identifiant, 0, 0, $absents));
        }

        return $lancee;
    }

    /**
     * Range le résultat d'un pair et le diffuse ; si c'est le dernier attendu, diffuse aussi le bilan.
     * Le verrou évite qu'une tâche écrase le résultat d'une autre (lecture, modification, écriture).
     *
     * @param  array{bibliotheque: string, statut: string, adresse: string|null, texte_normalise: string|null}  $resultat
     */
    public function enregistrer(string $identifiant, array $resultat): void
    {
        $resultat['recu_le'] = now()->toIso8601String();

        Cache::lock(self::cle($identifiant).':verrou', 10)->block(5, function () use ($identifiant, $resultat): void {
            $etat = Cache::get(self::cle($identifiant));
            if ($etat === null || collect($etat['resultats'])->contains('bibliotheque', $resultat['bibliotheque'])) {
                return; // recherche expirée, ou résultat déjà reçu (une tâche rejouée ne compte pas deux fois)
            }

            $etat['resultats'][] = $resultat;
            $etat['en_attente'] = max(0, $etat['en_attente'] - 1);
            Cache::put(self::cle($identifiant), $etat, $this->duree());

            $this->diffuser(new RechercheReseauResultat($identifiant, $resultat));

            if ($etat['en_attente'] === 0) {
                $this->diffuser($this->bilan($etat));
            }
        });
    }

    /** @return array<string, mixed>|null l'état (schéma `EtatRechercheReseau`), ou null si inconnu ou expiré */
    public function etat(string $identifiant): ?array
    {
        $etat = Cache::get(self::cle($identifiant));
        if ($etat === null) {
            return null;
        }

        return [
            'identifiant' => $etat['identifiant'],
            'texte_normalise' => $etat['texte_normalise'],
            'mode' => $etat['mode'],
            'etat' => $etat['en_attente'] === 0 ? 'terminee' : 'en_cours',
            'locale' => $etat['locale'],
            'interroges' => $etat['interroges'],
            'absents' => $etat['absents'],
            'resultats' => $etat['resultats'],
            'lancee_le' => $etat['lancee_le'],
        ];
    }

    private function bilan(array $etat): RechercheReseauTerminee
    {
        $sansReponse = collect($etat['resultats'])->where('statut', '!=', 'trouve')->pluck('bibliotheque')->all();

        return new RechercheReseauTerminee(
            $etat['identifiant'],
            count($etat['interroges']),
            collect($etat['resultats'])->where('statut', 'trouve')->count(),
            [...$etat['absents'], ...$sansReponse],
        );
    }

    /** Reverb éteint ne doit pas faire échouer la recherche : l'état en cache reste lisible. */
    private function diffuser(RechercheReseauResultat|RechercheReseauTerminee $evenement): void
    {
        try {
            broadcast($evenement);
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function duree(): \DateTimeInterface
    {
        return now()->addMinutes((int) config('babel.recherche_reseau_minutes', 15));
    }

    private static function cle(string $identifiant): string
    {
        return 'recherche-reseau:'.$identifiant;
    }
}
