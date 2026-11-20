<?php

declare(strict_types=1);

namespace App\Services\Moderation;

use App\Enums\StatutTexteBanni;
use App\Models\Adresse;
use App\Models\TexteBanni;
use Babel\Moteur\Erreur\ErreurMoteur;
use Babel\Moteur\Moteur;

/**
 * Cherche dans une page les textes bannis APPLIQUÉS ici (règles 17 et 18, « Comment un texte banni masque
 * ce qui est publié », point 3) et marque l'adresse quand elle en contient un.
 *
 * Marquer, ce n'est pas supprimer : la page reste lisible, mais ce qui est publié vers elle (lien court,
 * annotation publique…) n'est plus montré aux autres. Seuls les textes au statut « appliqué » comptent :
 * un texte « proposé » (relevé chez un pair) ou « rejeté » ne masque rien.
 */
final class TextesBannisAppliques
{
    public function __construct(private readonly Moteur $moteur) {}

    /** Vrai s'il existe au moins un texte appliqué : sinon inutile de lire la moindre page. */
    public function existent(): bool
    {
        return TexteBanni::query()->where('statut', StatutTexteBanni::Applique)->exists();
    }

    /** Le premier texte appliqué que contient cette page (le premier suffit, règle 18), ou null. */
    public function dans(string $textePage): ?TexteBanni
    {
        foreach (TexteBanni::query()->where('statut', StatutTexteBanni::Applique)->orderBy('id')->cursor() as $banni) {
            if ($banni->texte !== '' && str_contains($textePage, $banni->texte)) {
                return $banni;
            }
        }

        return null;
    }

    /** Marque l'adresse (page) si son texte en contient un. Renvoie vrai si elle est marquée. */
    public function verifierPage(Adresse $adresse, string $textePage): bool
    {
        $banni = $this->dans($textePage);
        if ($banni === null) {
            return false;
        }

        $adresse->forceFill(['texte_banni_id' => $banni->id])->save();

        return true;
    }

    /**
     * Pour un livre local entier : les 410 pages sont lues, et c'est l'adresse du LIVRE qui est marquée.
     * Ne fait rien (et ne lit rien) tant qu'aucun texte n'est appliqué.
     */
    public function verifierLivreLocal(Adresse $livre): bool
    {
        if (! $this->existent()) {
            return false;
        }

        for ($page = 1; $page <= Moteur::PAGES; $page++) {
            try {
                $texte = $this->moteur->lire($livre->ecritureLivre().':'.$page);
            } catch (ErreurMoteur) {
                break; // le dernier hexagone n'a qu'une page : les suivantes n'existent pas
            }

            if ($this->verifierPage($livre, $texte)) {
                return true;
            }
        }

        return false;
    }
}
