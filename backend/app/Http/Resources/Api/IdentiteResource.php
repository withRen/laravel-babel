<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Schéma « Identite » du contrat. La ressource est un tableau déjà rassemblé par le contrôleur. */
class IdentiteResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'identifiant' => $this->resource['identifiant'],
            'nom' => $this->resource['nom'],
            'description' => $this->resource['description'],
            'empreinte_cle' => $this->resource['empreinte_cle'],
            'algorithme' => $this->resource['algorithme'],
            'contrat' => $this->resource['contrat'],
            'application_web' => $this->resource['application_web'],
        ];
    }
}
