<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Événement `resultat` du canal public `recherche-reseau.{identifiant}` : un pair a répondu, ou a échoué.
 * Schéma `EvenementResultat` du contrat. Diffusé tout de suite (la tâche qui le publie tourne déjà en fond).
 */
final class RechercheReseauResultat implements ShouldBroadcastNow
{
    use Dispatchable;

    /** @param array{bibliotheque: string, statut: string, adresse: string|null, texte_normalise: string|null, recu_le: string} $resultat */
    public function __construct(public readonly string $identifiant, public readonly array $resultat) {}

    public function broadcastOn(): Channel
    {
        // Canal public : l'identifiant (UUID v4 tiré par le navigateur) est impossible à deviner.
        return new Channel('recherche-reseau.'.$this->identifiant);
    }

    public function broadcastAs(): string
    {
        return 'resultat';
    }

    /** @return array{identifiant: string, resultat: array<string, mixed>} */
    public function broadcastWith(): array
    {
        return ['identifiant' => $this->identifiant, 'resultat' => $this->resultat];
    }
}
