<?php

namespace App\Http\Resources\Api;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Schéma « Utilisateur » : id (en chaîne), pseudo, email, role, cree_le. Rien d'autre :
 * ni mot de passe, ni suspendu_le, ni derniere_connexion_le (additionalProperties: false).
 *
 * @mixin User
 */
class UtilisateurResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'pseudo' => $this->pseudo,
            'email' => $this->email,
            'role' => $this->role->value,
            'cree_le' => $this->cree_le->toIso8601String(),
        ];
    }
}
