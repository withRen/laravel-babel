<?php

declare(strict_types=1);

namespace App\Services\Moderation;

use App\Enums\StatutTexteBanni;
use App\Jobs\VerifierAdresses;
use App\Models\Adresse;
use App\Models\Bibliotheque;
use App\Models\TexteBanni;
use App\Services\Engine\Normaliseur;
use App\Services\Reseau\LecteurDePages;
use Babel\Moteur\Erreur\ErreurMoteur;
use Babel\Moteur\Moteur;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Modération par textes bannis (A5, A7). Règles 16 à 18 du cahier, et « Comment un texte banni masque ce qui est publié ».
 *
 * Appliquer un texte ne supprime rien : on marque (`adresses.texte_banni_id`) les adresses ENREGISTRÉES dont la page
 * contient le texte. Un même texte figure dans un nombre astronomique de pages, on ne peut pas toutes les trouver :
 * on vérifie celles que quelqu'un a désignées.
 */
final class ServiceBannissement
{
    public function __construct(
        private readonly Moteur $moteur,
        private readonly Normaliseur $normaliseur,
        private readonly LecteurDePages $lecteur,
    ) {}

    /**
     * A5 : déclarer un texte banni ici. Il est « applique » tout de suite (règle 17). Le texte est normalisé
     * comme une recherche : c'est ce texte-là qu'on cherche dans les pages.
     *
     * @throws InvalidArgumentException texte vide une fois normalisé, ou plus long qu'une page
     */
    public function declarer(string $texte, ?string $motif = null): TexteBanni
    {
        $normalise = $this->normaliseur->normaliser($texte);

        if ($normalise === '') {
            throw new InvalidArgumentException('Le texte est vide une fois normalisé (aucune lettre parmi les 29 symboles).');
        }

        if (mb_strlen($normalise) > Moteur::LONGUEUR_PAGE) {
            throw new InvalidArgumentException('Le texte dépasse '.Moteur::LONGUEUR_PAGE.' caractères : il ne tient pas dans une page.');
        }

        $locale = Bibliotheque::locale() ?? throw new InvalidArgumentException('La bibliothèque locale n\'existe pas encore (BibliothequeLocaleSeeder).');

        // L'empreinte est unique : un texte déjà connu (proposé par un pair, ou ancien bannissement levé) est repris.
        $banni = TexteBanni::query()->firstOrNew(['empreinte' => hash('sha256', $normalise)]);

        if (! $banni->exists) {
            $banni->texte = $normalise;
            $banni->origine_id = $locale->id;
            $banni->motif = $motif;
        } elseif (filled($motif)) {
            $banni->motif = $motif;
        }

        $banni->save();

        return $this->appliquer($banni);
    }

    /** A7 : appliquer un texte (proposé par un pair, ou ancien bannissement rétabli), puis marquer les adresses. */
    public function appliquer(TexteBanni $banni): TexteBanni
    {
        $banni->forceFill(['statut' => StatutTexteBanni::Applique, 'decide_le' => now()])->save();

        $this->marquerAdressesTrouvees($banni);

        return $banni;
    }

    /** A7 : refuser un texte proposé par un pair. Rien n'est marqué. */
    public function rejeter(TexteBanni $banni): TexteBanni
    {
        return $this->lever($banni);
    }

    /**
     * A5 : lever un bannissement (règle 17 : le texte passe à « rejete »). Les marques sont retirées, puis les pages
     * concernées sont revérifiées contre les AUTRES textes appliqués (une page peut en contenir deux).
     * La revérification est confiée à une tâche de fond : elle lit des pages, c'est long.
     */
    public function lever(TexteBanni $banni): TexteBanni
    {
        $liberees = DB::transaction(function () use ($banni): array {
            $banni->forceFill(['statut' => StatutTexteBanni::Rejete, 'decide_le' => now()])->save();
            $ids = Adresse::query()->where('texte_banni_id', $banni->id)->pluck('id')->all();
            Adresse::query()->where('texte_banni_id', $banni->id)->update(['texte_banni_id' => null]);

            return $ids;
        });

        if ($liberees !== []) {
            VerifierAdresses::dispatch($liberees);
        }

        return $banni;
    }

    /**
     * Étape 1 : chercher le texte en modes « exact » et « bruit », marquer les adresses obtenues si elles sont
     * enregistrées ici. Le moteur est déterministe : la même recherche redonne la même page.
     */
    private function marquerAdressesTrouvees(TexteBanni $banni): void
    {
        $locale = Bibliotheque::locale();

        if ($locale === null) {
            return;
        }

        foreach (['exact', 'bruit'] as $mode) {
            $adresse = $this->moteur->chercher($banni->texte, $mode);

            Adresse::query()
                ->where('bibliotheque_id', $locale->id)
                ->where('empreinte', hash('sha256', $adresse))
                ->whereNull('texte_banni_id')
                ->update(['texte_banni_id' => $banni->id]);

            // Un livre enregistré (adresse sans page) qui contient la page trouvée est marqué à son tour (étape 4).
            $livre = preg_replace('/:\d+$/', '', $adresse);
            Adresse::query()
                ->where('bibliotheque_id', $locale->id)
                ->whereNull('page')
                ->where('empreinte_livre', hash('sha256', (string) $livre))
                ->whereNull('texte_banni_id')
                ->update(['texte_banni_id' => $banni->id]);
        }
    }

    /**
     * Étapes 2, 3 et 5 : relire une adresse enregistrée ICI et la marquer si sa page (ou une des 410 pages de son
     * livre) contient un texte appliqué. À appeler à la publication d'un contenu et dans la tâche de fond.
     *
     * @return bool vrai si l'adresse est marquée à l'issue de la vérification
     */
    public function verifier(Adresse $adresse): bool
    {
        if ($adresse->texte_banni_id !== null) {
            return true;
        }

        $textes = TexteBanni::query()->where('statut', StatutTexteBanni::Applique)->orderBy('id')->get(['id', 'texte']);

        if ($textes->isEmpty()) {
            return false;
        }

        $pages = $adresse->page === null
            ? array_map(fn (int $p) => $adresse->ecritureLivre().':'.$p, range(1, Moteur::PAGES))
            : [$adresse->ecriture()];

        foreach ($pages as $page) {
            try {
                $contenu = $this->lecteur->lire($page);
            } catch (ErreurMoteur) {
                continue; // une adresse hors bibliothèque n'a rien à vérifier
            }

            foreach ($textes as $texte) {
                if (str_contains($contenu, $texte->texte)) {
                    $adresse->forceFill(['texte_banni_id' => $texte->id])->save();

                    return true;
                }
            }
        }

        return false;
    }
}
