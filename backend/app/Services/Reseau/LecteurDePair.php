<?php

declare(strict_types=1);

namespace App\Services\Reseau;

use App\Enums\EtatBibliotheque;
use App\Exceptions\ErreurApi;
use App\Models\Bibliotheque;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Lit une page chez une autre bibliothèque (V15, V16, M12, M13) : `GET {url}/api/v1/pages/{adresse}`.
 *
 * C'est NOTRE serveur qui appelle le pair, jamais le navigateur : le pair voit une requête de plus
 * d'un serveur du réseau, pas l'IP d'un visiteur. Règles du contrat :
 * - 2 secondes au plus (délai_pair_ms) ;
 * - un 429 n'est PAS un échec du pair : son compteur ne bouge pas (503 ERR_PEER_BUSY) ;
 * - pas de réponse, extinction, ou réponse hors contrat : 502 ERR_PEER_UNREACHABLE, et le compteur
 *   d'échecs du pair augmente (A9) ; au 3e échec de suite il est « hors ligne » ;
 * - un succès remet le compteur à zéro.
 * On ne suit jamais de redirection : une URL d'annuaire ne doit pas pouvoir nous envoyer ailleurs.
 */
final class LecteurDePair
{
    public const DELAI_SECONDES = 2;

    public const ECHECS_AVANT_HORS_LIGNE = 3;

    /**
     * @throws ErreurApi 502 injoignable, 503 occupé
     */
    public function lire(Bibliotheque $pair, string $adresse): string
    {
        $url = $this->urlDe($pair);

        try {
            $reponse = Http::timeout(self::DELAI_SECONDES)
                ->withoutRedirecting()
                ->acceptJson()
                ->get($url.'/api/v1/pages/'.$adresse);
        } catch (ConnectionException) {
            throw $this->echec($pair);
        }

        if ($reponse->status() === 429) {
            $secondes = (int) $reponse->header('Retry-After');
            throw new ErreurApi(
                503,
                [['code' => 'ERR_PEER_BUSY', 'champ' => 'bibliotheque', 'message' => 'La bibliothèque voisine est occupée, réessayez plus tard.']],
                $secondes > 0 ? ['Retry-After' => (string) $secondes] : [],
            );
        }

        $texte = $this->texteDe($reponse, $pair, $adresse);
        if ($texte === null) {
            throw $this->echec($pair);
        }

        $pair->forceFill([
            'etat' => EtatBibliotheque::EnLigne,
            'echecs_consecutifs' => 0,
            'dernier_contact_le' => now(),
        ])->save();

        return $texte;
    }

    private function urlDe(Bibliotheque $pair): string
    {
        $url = rtrim((string) $pair->url_actuelle, '/');

        if (! preg_match('#^https?://[^\s/]+#i', $url)) {
            throw $this->echec($pair);
        }

        return $url;
    }

    /** Le texte de la réponse, ou null si elle sort du contrat (statut, forme, identité, longueur, alphabet). */
    private function texteDe(Response $reponse, Bibliotheque $pair, string $adresse): ?string
    {
        if ($reponse->status() !== 200) {
            return null;
        }

        $corps = $reponse->json();
        if (! is_array($corps)
            || ($corps['bibliotheque'] ?? null) !== $pair->identifiant
            || ($corps['adresse'] ?? null) !== $adresse
            || ! is_string($corps['texte'] ?? null)
            || preg_match('/^[a-z ,.]{3200}$/D', $corps['texte']) !== 1) {
            return null;
        }

        return $corps['texte'];
    }

    private function echec(Bibliotheque $pair): ErreurApi
    {
        $echecs = $pair->echecs_consecutifs + 1;
        $pair->forceFill([
            'echecs_consecutifs' => $echecs,
            'etat' => $echecs >= self::ECHECS_AVANT_HORS_LIGNE ? EtatBibliotheque::HorsLigne : $pair->etat,
        ])->save();

        return ErreurApi::une(502, 'ERR_PEER_UNREACHABLE', 'La bibliothèque voisine ne répond pas.', 'bibliotheque');
    }
}
