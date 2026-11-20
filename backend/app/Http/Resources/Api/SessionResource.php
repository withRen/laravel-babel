<?php

namespace App\Http\Resources\Api;

use App\Services\Compte\Session;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Schéma « Session » : le jeton Bearer en clair (donné UNE seule fois, Sanctum n'en garde que l'empreinte)
 * et le membre.
 *
 * @mixin Session
 */
class SessionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'jeton' => $this->jeton,
            'type' => 'Bearer',
            'utilisateur' => new UtilisateurResource($this->utilisateur),
        ];
    }
}
