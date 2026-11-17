<?php

declare(strict_types=1);

namespace App\Services\Annuaire;

use App\Enums\EtatBibliotheque;
use App\Models\Bibliotheque;

/**
 * S'annonce à l'annuaire, puis range la liste des pairs dans la table `bibliotheques`.
 *
 * L'identifiant est l'identité (stable), l'URL n'est qu'une adresse du moment : un pair déjà connu
 * garde sa ligne et voit seulement `url_actuelle` changer. Rien n'est supprimé : il n'y a ni liste
 * blanche ni liste noire, un pair absent de la liste reste connu (avec son `vue_annuaire_le` plus ancien).
 */
final class SynchroniseurAnnuaire
{
    public function __construct(private readonly Annuaire $annuaire) {}

    /**
     * @return int le nombre de pairs vus dans l'annuaire (la nôtre exclue)
     *
     * @throws ErreurAnnuaire
     */
    public function synchroniser(): int
    {
        $identifiant = $this->identifiantLocal();
        $this->annuaire->annoncer($identifiant, $this->urlPublique());

        $vus = 0;
        foreach ($this->annuaire->lister() as $pair) {
            if ($pair->identifiant === $identifiant || ! $this->valide($pair)) {
                continue; // nous-mêmes, ou une ligne qui ne respecte pas le format du contrat
            }

            $bibliotheque = Bibliotheque::firstOrNew(['identifiant' => $pair->identifiant]);
            $bibliotheque->url_actuelle = rtrim($pair->url, '/');
            $bibliotheque->vue_annuaire_le = now();
            if (! $bibliotheque->exists) {
                // Le nom définitif viendra de l'identité du pair (B6) au premier contrôle.
                $bibliotheque->nom = $pair->nom ?? $pair->identifiant;
                $bibliotheque->etat = EtatBibliotheque::Inconnu;
                $bibliotheque->echecs_consecutifs = 0;
            }
            $bibliotheque->save();
            $vus++;
        }

        return $vus;
    }

    private function identifiantLocal(): string
    {
        // La ligne locale fait foi ; sinon la configuration (comme GET /identite).
        return Bibliotheque::locale()?->identifiant ?? (string) config('babel.identifiant');
    }

    private function urlPublique(): string
    {
        return rtrim((string) (config('babel.url_publique') ?: config('app.url')), '/');
    }

    /** Même motif que `IdentifiantBibliotheque` dans le contrat, et une vraie URL http(s). */
    private function valide(PairAnnonce $pair): bool
    {
        return preg_match('/^[a-z0-9][a-z0-9-]{1,31}$/', $pair->identifiant) === 1
            && filter_var($pair->url, FILTER_VALIDATE_URL) !== false
            && in_array(parse_url($pair->url, PHP_URL_SCHEME), ['http', 'https'], true);
    }
}
