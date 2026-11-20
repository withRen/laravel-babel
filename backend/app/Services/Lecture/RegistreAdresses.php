<?php

declare(strict_types=1);

namespace App\Services\Lecture;

use App\Models\Adresse;
use App\Models\Bibliotheque;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Le passage entre une adresse écrite et sa ligne de la table `adresses` (règle 2 : une adresse n'est
 * enregistrée qu'une fois, l'unicité porte sur (bibliothèque, SHA-256 de l'adresse écrite)).
 *
 * L'adresse reçue est SUPPOSÉE déjà validée (Valideur) : on ne la corrige pas, on la découpe.
 * Une adresse de page et l'adresse de son livre sont deux lignes (la seconde a `page` nul).
 */
final class RegistreAdresses
{
    private const MOTIF = '/^(?<hexagone>[0-9a-z]+)-w(?<mur>\d+)-s(?<etagere>\d+)-v(?<volume>\d+)(?::(?<page>\d+))?$/';

    /** L'adresse enregistrée, ou null si personne ne s'en est encore servi (aucun vote, marque-page, etc.). */
    public function trouver(Bibliotheque $bibliotheque, string $adresse): ?Adresse
    {
        return Adresse::query()
            ->where('bibliotheque_id', $bibliotheque->id)
            ->where('empreinte', hash('sha256', $adresse))
            ->first();
    }

    /** L'adresse enregistrée ; elle est créée à la première demande. */
    public function enregistrer(Bibliotheque $bibliotheque, string $adresse): Adresse
    {
        $existante = $this->trouver($bibliotheque, $adresse);
        if ($existante !== null) {
            return $existante;
        }

        if (preg_match(self::MOTIF, $adresse, $c) !== 1) {
            throw new \InvalidArgumentException('Adresse non validée : '.substr($adresse, 0, 40));
        }

        try {
            return Adresse::query()->create([
                'bibliotheque_id' => $bibliotheque->id,
                'hexagone' => $c['hexagone'],
                'mur' => (int) $c['mur'],
                'etagere' => (int) $c['etagere'],
                'volume' => (int) $c['volume'],
                'page' => isset($c['page']) && $c['page'] !== '' ? (int) $c['page'] : null,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Deux requêtes ont créé la même adresse en même temps : la base a refusé la seconde, on reprend la première.
            return $this->trouver($bibliotheque, $adresse) ?? throw new \RuntimeException('Adresse introuvable après conflit.');
        }
    }
}
