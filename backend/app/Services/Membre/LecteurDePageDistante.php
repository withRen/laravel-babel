<?php

declare(strict_types=1);

namespace App\Services\Membre;

use App\Models\Bibliotheque;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Lit une page chez une autre bibliothèque, par la porte réseau (B1 : GET {url}/api/v1/pages/{adresse}).
 *
 * Ne lève jamais : un pair éteint, trop lent ou hors contrat répond « null », et l'appelant réessaiera
 * plus tard (la lecture distante n'est jamais une raison de faire échouer la requête d'un membre).
 */
final class LecteurDePageDistante
{
    private const DELAI_SECONDES = 5;

    public function lire(Bibliotheque $origine, string $adresse): ?string
    {
        $url = (string) $origine->url_actuelle;

        // Seule une adresse http(s) est appelée : l'annuaire est une source extérieure.
        if (! preg_match('#\Ahttps?://#i', $url)) {
            return null;
        }

        try {
            $reponse = Http::timeout(self::DELAI_SECONDES)->connectTimeout(3)->acceptJson()
                ->get(rtrim($url, '/').'/api/v1/pages/'.rawurlencode($adresse));
        } catch (ConnectionException) {
            return null;
        }

        $texte = $reponse->successful() ? $reponse->json('texte') : null;

        // Une page fait exactement 3 200 caractères pris parmi les 29 symboles (schéma TextePage).
        return is_string($texte) && preg_match('/\A[a-z ,.]{3200}\z/', $texte) === 1 ? $texte : null;
    }
}
