<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/** Événement `terminee` : toutes les tâches sont finies. Schéma `EvenementTerminee` du contrat (le bilan). */
final class RechercheReseauTerminee implements ShouldBroadcastNow
{
    use Dispatchable;

    /** @param list<string> $absents les pairs sans réponse, occupés ou hors ligne */
    public function __construct(
        public readonly string $identifiant,
        public readonly int $interroges,
        public readonly int $ontRepondu,
        public readonly array $absents,
    ) {}

    public function broadcastOn(): Channel
    {
        return new Channel('recherche-reseau.'.$this->identifiant);
    }

    public function broadcastAs(): string
    {
        return 'terminee';
    }

    /** @return array{identifiant: string, interroges: int, ont_repondu: int, absents: list<string>} */
    public function broadcastWith(): array
    {
        return [
            'identifiant' => $this->identifiant,
            'interroges' => $this->interroges,
            'ont_repondu' => $this->ontRepondu,
            'absents' => $this->absents,
        ];
    }
}
