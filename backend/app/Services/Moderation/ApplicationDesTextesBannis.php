<?php

declare(strict_types=1);

namespace App\Services\Moderation;

use App\Enums\StatutTexteBanni;
use App\Models\Adresse;
use App\Models\Bibliotheque;
use App\Models\TexteBanni;
use App\Services\Reseau\FiltreTextesBannis;
use App\Services\Reseau\LecteurDePages;
use Babel\Moteur\Erreur\ErreurMoteur;
use Babel\Moteur\Moteur;
use Illuminate\Support\Facades\DB;

/**
 * Appliquer ou lever un texte banni (A5, A7) : masquer, ou ne plus masquer, ce qui est publié vers lui.
 *
 * Un même texte figure dans un nombre astronomique de pages : on ne peut pas toutes les trouver. On marque donc les
 * pages ENREGISTRÉES (adresses en base) qui le contiennent, en commençant par celles que le moteur renvoie pour ce
 * texte en modes `exact` et `bruit`. Ce qui est publié vers une page marquée n'est plus montré au public
 * (CollectionsPubliques, PagesPopulaires) ; la page reste lisible, rien n'est supprimé, `masque_le` n'est pas touché.
 * La relecture des pages déjà publiées est le travail d'une tâche de fond, pas de cette classe.
 */
final class ApplicationDesTextesBannis
{
    public function __construct(private readonly Moteur $moteur, private readonly LecteurDePages $lecteur) {}

    /**
     * A7, étape 4 : ce qui serait masqué. Ne change rien.
     *
     * @return list<Adresse> les adresses enregistrées chez nous qui contiennent le texte
     */
    public function adressesConcernees(TexteBanni $texte): array
    {
        $locale = Bibliotheque::locale();
        if ($locale === null) {
            return [];
        }

        $empreintes = [];
        foreach (['exact', 'bruit'] as $mode) {
            try {
                $empreintes[] = hash('sha256', $this->moteur->chercher($texte->texte, $mode));
            } catch (ErreurMoteur) {
                // Un texte que le moteur refuse (trop long, vide) ne désigne aucune page.
            }
        }

        return Adresse::query()
            ->where('bibliotheque_id', $locale->id)
            ->whereNotNull('page')
            ->whereIn('empreinte', $empreintes)
            ->get()
            ->all();
    }

    /**
     * Applique le texte : il masque, dès maintenant, ce qui est publié vers les pages enregistrées qui le contiennent.
     * Une page déjà marquée par un autre texte garde sa marque : le premier trouvé suffit.
     *
     * @return int le nombre d'adresses nouvellement marquées
     */
    public function appliquer(TexteBanni $texte): int
    {
        return DB::transaction(function () use ($texte): int {
            $texte->forceFill(['statut' => StatutTexteBanni::Applique, 'decide_le' => now()])->save();

            $marquees = 0;
            foreach ($this->adressesConcernees($texte) as $adresse) {
                if ($adresse->texte_banni_id === null) {
                    $adresse->forceFill(['texte_banni_id' => $texte->id])->save();
                    $marquees++;
                }
            }

            return $marquees;
        });
    }

    /**
     * Rejette le texte, ou lève un bannissement : ses marques sont retirées, et les pages concernées sont revérifiées
     * contre les AUTRES textes appliqués (une page peut en contenir plusieurs).
     */
    public function lever(TexteBanni $texte): void
    {
        DB::transaction(function () use ($texte): void {
            $texte->forceFill(['statut' => StatutTexteBanni::Rejete, 'decide_le' => now()])->save();

            $autres = TexteBanni::query()
                ->where('statut', StatutTexteBanni::Applique)
                ->whereKeyNot($texte->id)
                ->get(['id', 'texte']);

            Adresse::query()->where('texte_banni_id', $texte->id)->with('bibliotheque')->get()->each(
                function (Adresse $adresse) use ($autres): void {
                    $adresse->forceFill(['texte_banni_id' => $this->autreTexteContenu($adresse, $autres)?->id])->save();
                },
            );
        });
    }

    /**
     * Le premier autre texte appliqué que la page contient. Une page d'une autre bibliothèque (M13) n'est pas relue ici :
     * elle perd sa marque et la tâche de fond la revérifiera, chez elle, au passage suivant.
     *
     * @param  \Illuminate\Support\Collection<int, TexteBanni>  $autres
     */
    private function autreTexteContenu(Adresse $adresse, $autres): ?TexteBanni
    {
        if ($autres->isEmpty() || ! $adresse->bibliotheque?->est_locale) {
            return null;
        }

        try {
            $page = $this->lecteur->lire($adresse->ecriture());
        } catch (ErreurMoteur) {
            return null;
        }

        return $autres->first(fn (TexteBanni $t) => FiltreTextesBannis::contient($page, [$t->texte]));
    }
}
