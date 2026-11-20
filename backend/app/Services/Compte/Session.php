<?php

namespace App\Services\Compte;

use App\Models\User;

/** Une session ouverte : le membre et son jeton Bearer en clair (visible une seule fois). */
final readonly class Session
{
    public function __construct(public User $utilisateur, public string $jeton) {}
}
