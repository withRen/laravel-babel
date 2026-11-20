<?php

declare(strict_types=1);

namespace App\Services\Lecture;

use App\Exceptions\ErreurApi;
use App\Models\Adresse;
use App\Models\Annotation;
use App\Models\Bibliotheque;
use App\Models\MarquePage;
use App\Models\User;
use App\Models\Vote;
use App\Services\Engine\Valideur;
use App\Services\Reseau\LecteurDePages;
use App\Services\Reseau\LecteurDePair;
use Illuminate\Database\Eloquent\Builder;

/**
 * Lire une page pour l'application web (V1, V15, V16) : le texte et, en une seule réponse, tout ce que
 * les membres ont laissé autour (annotations, score, vote, marque-page).
 *
 * Le texte vient du moteur (bibliothèque locale) ou du pair (paramètre `bibliotheque`). Le reste vient de
 * NOTRE base, y compris pour une page d'ailleurs : les votes et annotations de nos membres sur cette page.
 */
final class ServiceLecture
{
    public function __construct(
        private readonly LecteurDePages $lecteurLocal,
        private readonly LecteurDePair $lecteurDePair,
        private readonly Valideur $valideur,
        private readonly RegistreAdresses $registre,
    ) {}

    /**
     * La bibliothèque demandée : la nôtre si le paramètre est absent ou égal à notre identifiant.
     * Un identifiant inconnu (ou qui n'est pas une chaîne) donne 404 ERR_UNKNOWN_LIBRARY.
     */
    public function bibliotheque(mixed $identifiant): Bibliotheque
    {
        $locale = Bibliotheque::locale()
            ?? throw new \RuntimeException("La bibliothèque locale n'est pas initialisée (php artisan db:seed).");

        if ($identifiant === null || $identifiant === '' || $identifiant === $locale->identifiant) {
            return $locale;
        }

        $bibliotheque = is_string($identifiant)
            ? Bibliotheque::query()->where('identifiant', $identifiant)->first()
            : null;

        return $bibliotheque ?? throw ErreurApi::une(
            404, 'ERR_UNKNOWN_LIBRARY', 'Cette bibliothèque ne fait pas partie du réseau.', 'bibliotheque',
        );
    }

    /**
     * Le texte d'une page, après contrôle de l'adresse (F5) : on ne contacte JAMAIS un pair avec une adresse
     * que nous refuserions chez nous, les bornes sont les mêmes partout.
     */
    public function texte(Bibliotheque $bibliotheque, string $adresse): string
    {
        if ($bibliotheque->est_locale) {
            return $this->lecteurLocal->lire($adresse); // contrôle l'adresse, puis lit
        }

        $validation = $this->valideur->valider($adresse);
        if (! $validation->valide || $validation->niveau !== 'page') {
            $this->lecteurLocal->lire($adresse); // lève les erreurs du contrat (liste complète, 404 ou 422)
        }

        return $this->lecteurDePair->lire($bibliotheque, $adresse);
    }

    /**
     * La réponse « PageLue » : texte + contexte.
     *
     * @return array<string, mixed>
     */
    public function pageLue(Bibliotheque $bibliotheque, string $adresse, string $texte, ?User $membre): array
    {
        // Une page que personne n'a encore touchée n'a pas de ligne : score 0, rien autour d'elle.
        $enregistree = $this->registre->trouver($bibliotheque, $adresse);

        return [
            'bibliotheque' => $bibliotheque->identifiant,
            'adresse' => $adresse, // telle que reçue : elle est valide, donc déjà canonique
            'adresse_livre' => explode(':', $adresse, 2)[0],
            'texte' => $texte,
            'score' => $enregistree === null ? 0 : $this->score($enregistree),
            'mon_vote' => $enregistree === null || $membre === null ? null : $this->monVote($enregistree, $membre),
            'marque_page' => $enregistree === null || $membre === null ? null : $this->monMarquePage($enregistree, $membre),
            'annotations' => $enregistree === null ? [] : $this->annotationsVisibles($enregistree, $membre),
        ];
    }

    /** Même formule que les pages populaires : somme des votes (+1/-1) + nombre de marque-pages. */
    private function score(Adresse $adresse): int
    {
        return (int) Vote::query()->where('adresse_id', $adresse->id)->sum('valeur')
            + MarquePage::query()->where('adresse_id', $adresse->id)->count();
    }

    private function monVote(Adresse $adresse, User $membre): ?int
    {
        $valeur = Vote::query()
            ->where('adresse_id', $adresse->id)
            ->where('utilisateur_id', $membre->id)
            ->value('valeur');

        return $valeur === null ? null : (int) $valeur;
    }

    /** @return array{id: string, titre: string|null}|null */
    private function monMarquePage(Adresse $adresse, User $membre): ?array
    {
        $marquePage = MarquePage::query()
            ->where('adresse_id', $adresse->id)
            ->where('utilisateur_id', $membre->id)
            ->first();

        return $marquePage === null ? null : ['id' => (string) $marquePage->id, 'titre' => $marquePage->titre];
    }

    /**
     * Les annotations que CE lecteur a le droit de voir, de la plus récente à la plus ancienne :
     * - celles du membre connecté, privées et masquées comprises (seul l'auteur voit une annotation masquée) ;
     * - celles des autres, seulement si publiques, non masquées, et si la page ne contient pas de texte banni
     *   appliqué (règle 18 : on ne touche pas à `masque_le`, on ne montre plus).
     *
     * @return list<array<string, mixed>>
     */
    private function annotationsVisibles(Adresse $adresse, ?User $membre): array
    {
        $autresVoientLesPubliques = $adresse->texte_banni_id === null;

        // Aucune condition possible = personne ne peut rien voir. On sort tout de suite : une clause « where »
        // vide ne filtrerait RIEN, elle montrerait toutes les annotations.
        if ($membre === null && ! $autresVoientLesPubliques) {
            return [];
        }

        $annotations = Annotation::query()
            ->with(['utilisateur', 'adresse.bibliotheque'])
            ->where('adresse_id', $adresse->id)
            ->where(function (Builder $q) use ($membre, $autresVoientLesPubliques): void {
                if ($membre !== null) {
                    $q->where('utilisateur_id', $membre->id);
                }
                if ($autresVoientLesPubliques) {
                    $q->orWhere(fn (Builder $autres) => $autres->where('est_publique', true)->whereNull('masque_le'));
                }
            })
            ->orderByDesc('cree_le')
            ->orderByDesc('id')
            ->get();

        return $annotations->map(fn (Annotation $a) => AnnotationPublique::depuis($a, $membre))->all();
    }
}
