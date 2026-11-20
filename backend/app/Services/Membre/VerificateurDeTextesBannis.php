<?php

declare(strict_types=1);

namespace App\Services\Membre;

use App\Enums\StatutTexteBanni;
use App\Models\Adresse;
use App\Models\TexteBanni;
use App\Services\Reseau\LecteurDePages;
use Babel\Moteur\Erreur\ErreurMoteur;

/**
 * Quand un membre PUBLIE (annotation publique, page dans une collection publique), le serveur lit la page visée
 * et y cherche les textes bannis appliqués (cahier des charges, « Comment un texte banni masque ce qui est publié »,
 * point 3). Si elle en contient un, l'adresse est marquée (règle 18) : ce qui la vise n'est plus montré au public.
 * Rien n'est supprimé et la publication n'est jamais refusée : le membre voit « masquee » dans ses données.
 *
 * Une page d'une autre bibliothèque est lue chez elle ; si elle ne répond pas, la page reste non marquée
 * et sera revue par la tâche de fond de modération.
 */
final class VerificateurDeTextesBannis
{
    /** @var list<array{id: int, texte: string}>|null */
    private ?array $appliques = null;

    public function __construct(
        private readonly LecteurDePages $lecteur,
        private readonly LecteurDePageDistante $distant,
    ) {}

    public function verifier(Adresse $adresse): void
    {
        if ($adresse->texte_banni_id !== null || $adresse->estLivre()) {
            return;
        }

        $textes = $this->appliques();
        if ($textes === []) {
            return; // rien n'est banni : inutile de lire la page
        }

        $page = $this->lirePage($adresse);
        if ($page === null) {
            return;
        }

        foreach ($textes as $banni) {
            if (str_contains($page, $banni['texte'])) {
                $adresse->update(['texte_banni_id' => $banni['id']]);

                return;
            }
        }
    }

    private function lirePage(Adresse $adresse): ?string
    {
        if ($adresse->bibliotheque->est_locale) {
            try {
                return $this->lecteur->lire($adresse->ecriture());
            } catch (ErreurMoteur) {
                return null; // adresse déjà validée : ne devrait pas arriver, et ne doit pas bloquer la publication
            }
        }

        return $this->distant->lire($adresse->bibliotheque, $adresse->ecriture());
    }

    /** @return list<array{id: int, texte: string}> */
    private function appliques(): array
    {
        return $this->appliques ??= TexteBanni::query()
            ->where('statut', StatutTexteBanni::Applique)
            ->orderBy('id')
            ->get(['id', 'texte'])
            ->map(fn (TexteBanni $t) => ['id' => $t->id, 'texte' => $t->texte])
            ->all();
    }
}
