<?php

declare(strict_types=1);

namespace App\Services\Reseau;

use App\Enums\StatutTexteBanni;
use App\Models\Bibliotheque;
use App\Models\TexteBanni;
use Babel\Moteur\Erreur\ErreurMoteur;

/**
 * « Cette page contient-elle un texte que NOUS avons banni ? » (A7, V21).
 *
 * Avant de montrer une collection ou des pages populaires d'une autre bibliothèque, le serveur lit les pages
 * désignées (chez la bibliothèque indiquée, ou chez lui) et ne montre pas celles qui contiennent un texte banni
 * appliqué ici. Rien n'est enregistré : c'est une vérification au moment de l'affichage.
 *
 * Contenir = trouver le texte tel quel dans les 3 200 caractères. Les modes `exact` et `bruit` du moteur placent
 * le texte cherché d'un seul tenant dans la page, donc une recherche de sous-chaîne retrouve exactement les pages
 * que `chercher` aurait pu produire pour ce texte.
 */
final class FiltreTextesBannis
{
    public function __construct(
        private readonly ClientPair $client,
        private readonly LecteurDePages $lecteur,
    ) {}

    /** Les textes appliqués ici : ceux qu'on a bannis nous-mêmes comme ceux d'un pair qu'on a acceptés (A7). */
    public function textesAppliques(): array
    {
        return TexteBanni::query()->where('statut', StatutTexteBanni::Applique)->pluck('texte')->all();
    }

    /** @param list<string> $textesBannis */
    public static function contient(string $page, array $textesBannis): bool
    {
        foreach ($textesBannis as $texte) {
            if ($texte !== '' && str_contains($page, $texte)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Quelles pages peut-on montrer ?
     *
     * Une page qu'on ne peut PAS vérifier (bibliothèque inconnue, éteinte, hors contrat) n'est pas montrée : mieux vaut
     * une page de moins qu'une page bannie. Seule exception : la bibliothèque $principale (celle que l'application web
     * interroge). Si elle ne répond pas, c'est toute la requête qui échoue (502 ou 503), pas un trou dans la liste.
     * Sans texte appliqué, il n'y a rien à vérifier : on ne lit aucune page (ni quota, ni attente inutiles).
     *
     * @param  list<array{bibliotheque: string, adresse: string}>  $pages
     * @return array<string, bool> clé « bibliotheque|adresse » => on peut la montrer
     *
     * @throws ErreurPair la bibliothèque principale ne répond pas
     */
    public function montrables(array $pages, ?Bibliotheque $principale = null): array
    {
        $bannis = $this->textesAppliques();
        $resultat = [];

        $parBibliotheque = [];
        foreach ($pages as $page) {
            $parBibliotheque[$page['bibliotheque']][$page['adresse']] = true;
        }

        foreach ($parBibliotheque as $identifiant => $adresses) {
            $adresses = array_keys($adresses);
            $textes = $bannis === [] ? array_fill_keys($adresses, '') : $this->lire((string) $identifiant, $adresses, $principale);

            foreach ($adresses as $adresse) {
                $texte = $textes[$adresse] ?? null;
                $resultat[self::cle((string) $identifiant, $adresse)] = $texte !== null && ! self::contient($texte, $bannis);
            }
        }

        return $resultat;
    }

    public static function cle(string $bibliotheque, string $adresse): string
    {
        return $bibliotheque.'|'.$adresse;
    }

    /**
     * @param  list<string>  $adresses
     * @return array<string, string|null> adresse => texte (null : illisible)
     */
    private function lire(string $identifiant, array $adresses, ?Bibliotheque $principale): array
    {
        $bibliotheque = Bibliotheque::query()->where('identifiant', $identifiant)->first();
        if ($bibliotheque === null) {
            return array_fill_keys($adresses, null); // une bibliothèque qu'on ne connaît pas ne se vérifie pas
        }

        if ($bibliotheque->est_locale) {
            $textes = [];
            foreach ($adresses as $adresse) {
                try {
                    $textes[$adresse] = $this->lecteur->lire($adresse);
                } catch (ErreurMoteur) {
                    $textes[$adresse] = null;
                }
            }

            return $textes;
        }

        try {
            return $this->client->lire($bibliotheque, $adresses);
        } catch (ErreurPair $e) {
            if ($principale !== null && $principale->is($bibliotheque)) {
                throw $e;
            }

            return array_fill_keys($adresses, null);
        }
    }
}
