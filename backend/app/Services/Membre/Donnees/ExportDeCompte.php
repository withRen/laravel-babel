<?php

declare(strict_types=1);

namespace App\Services\Membre\Donnees;

use App\Models\Adresse;
use App\Models\LienCourt;
use App\Models\User;
use App\Models\Vote;
use App\Services\Membre\Adresses;

/**
 * M11 : le fichier d'export d'un compte, au format commun « babel-export/v1 » (schéma ExportCompte du contrat).
 *
 * Le mot de passe n'y figure pas, même haché. Chaque adresse porte l'identifiant de SA bibliothèque : c'est ce
 * qui permet à une autre bibliothèque de lire la page chez nous puis de la retrouver chez elle (M12).
 * Rien n'est stocké : le fichier est calculé à chaque demande, c'est une vue de la base (règle 20).
 */
final class ExportDeCompte
{
    public const FORMAT = 'babel-export/v1';

    public function __construct(private readonly Adresses $adresses) {}

    /** @return array<string, mixed> */
    public function generer(User $membre): array
    {
        $collections = $membre->collections()->with('pages.adresse.bibliotheque')->orderBy('id')->get();
        // Le rang de chaque collection dans le tableau « collections » du fichier, à partir de 0 : les liens courts s'y réfèrent.
        $rangs = $collections->pluck('id')->flip()->all();

        return [
            'format' => self::FORMAT,
            'exporte_le' => now()->toIso8601String(),
            'bibliotheque' => $this->adresses->locale()->identifiant,
            'profil' => [
                'pseudo' => $membre->pseudo,
                'email' => $membre->email,
                'cree_le' => $membre->cree_le->toIso8601String(),
            ],
            'marque_pages' => $membre->marquePages()->with('adresse.bibliotheque')->orderBy('id')->get()
                ->map(fn ($m) => [
                    ...$this->page($m->adresse),
                    'titre' => $m->titre,
                    'cree_le' => $m->cree_le->toIso8601String(),
                ])->all(),
            'collections' => $collections->map(fn ($c) => [
                'nom' => $c->nom,
                'description' => $c->description ?? '',
                'est_publique' => $c->est_publique,
                'pages' => $c->pages->map(fn ($p) => ['position' => $p->position, ...$this->page($p->adresse)])->all(),
                'cree_le' => $c->cree_le->toIso8601String(),
            ])->all(),
            'annotations' => $membre->annotations()->with('adresse.bibliotheque')->orderBy('id')->get()
                ->map(fn ($a) => [
                    ...$this->page($a->adresse),
                    'contenu' => $a->contenu,
                    'est_publique' => $a->est_publique,
                    'cree_le' => $a->cree_le->toIso8601String(),
                    'maj_le' => $a->maj_le->toIso8601String(),
                ])->all(),
            'votes' => Vote::query()->where('utilisateur_id', $membre->id)->with('adresse.bibliotheque')
                ->orderBy('cree_le')->orderBy('adresse_id')->get()
                ->map(fn ($v) => [
                    ...$this->page($v->adresse),
                    'valeur' => $v->valeur,
                    'cree_le' => $v->cree_le->toIso8601String(),
                ])->all(),
            'recherches' => $membre->recherches()->orderBy('id')->get()
                ->map(fn ($r) => [
                    'texte' => $r->texte,
                    'mode' => $r->mode->value,
                    'portee' => $r->portee->value,
                    'cree_le' => $r->cree_le->toIso8601String(),
                ])->all(),
            'liens_courts' => $membre->liensCourts()->with('adresse.bibliotheque')->orderBy('id')->get()
                ->map(fn ($l) => $this->lienCourt($l, $rangs))
                ->filter()->values()->all(),
        ];
    }

    /**
     * @param  array<int, int>  $rangs  id de collection => rang dans le fichier
     * @return array<string, mixed>|null null si la cible n'est pas dans le fichier (collection d'un autre membre)
     */
    private function lienCourt(LienCourt $lien, array $rangs): ?array
    {
        if ($lien->collection_id !== null) {
            if (! isset($rangs[$lien->collection_id])) {
                return null; // le fichier ne contient que les données de ce membre
            }
            $cible = ['type' => 'collection', 'collection' => $rangs[$lien->collection_id]];
        } else {
            $cible = ['type' => 'adresse', ...$this->page($lien->adresse)];
        }

        return [
            'slug' => $lien->slug,
            'cible' => $cible,
            'passage' => $lien->passage,
            'visites' => $lien->visites,
            'expire_le' => $lien->expire_le?->toIso8601String(),
            'cree_le' => $lien->cree_le->toIso8601String(),
        ];
    }

    /** @return array{bibliotheque: string, adresse: string} */
    private function page(Adresse $adresse): array
    {
        return ['bibliotheque' => $adresse->bibliotheque->identifiant, 'adresse' => $adresse->ecriture()];
    }
}
