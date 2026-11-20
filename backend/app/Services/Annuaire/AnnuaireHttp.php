<?php

declare(strict_types=1);

namespace App\Services\Annuaire;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Parle à l'annuaire en HTTP.
 *
 * ⚠️ HYPOTHÈSE À CONFIRMER : ni le cahier des charges ni openapi.yaml ne donnent l'URL ni le format de
 * l'annuaire. Ce qui est sûr : on s'annonce avec notre identifiant et notre URL, authentifiés par le
 * secret d'annonce, et on récupère la liste des autres. Ce qui est supposé, et concentré ici :
 *
 *   POST {BABEL_ANNUAIRE_URL}/annonce          corps {"identifiant": "...", "url": "..."}
 *   GET  {BABEL_ANNUAIRE_URL}/bibliotheques    réponse {"elements": [{"identifiant", "url", "nom"?}]}
 *
 * avec l'en-tête « Authorization: Bearer <BABEL_ANNOUNCE_SECRET> ». Une fois le vrai format connu,
 * on corrige les deux constantes et les deux méthodes ci-dessous, rien d'autre.
 */
final class AnnuaireHttp implements Annuaire
{
    public const CHEMIN_ANNONCE = '/annonce';

    public const CHEMIN_LISTE = '/bibliotheques';

    public function annoncer(string $identifiant, string $url): void
    {
        $reponse = $this->envoyer(fn (PendingRequest $r) => $r->post($this->adresse(self::CHEMIN_ANNONCE), [
            'identifiant' => $identifiant,
            'url' => $url,
        ]));

        if ($reponse->failed()) {
            throw new ErreurAnnuaire("L'annuaire a refusé l'annonce (HTTP {$reponse->status()}).");
        }
    }

    public function lister(): array
    {
        $reponse = $this->envoyer(fn (PendingRequest $r) => $r->get($this->adresse(self::CHEMIN_LISTE)));

        if ($reponse->failed()) {
            throw new ErreurAnnuaire("L'annuaire a refusé la demande de liste (HTTP {$reponse->status()}).");
        }

        $elements = $reponse->json('elements');
        if (! is_array($elements)) {
            throw new ErreurAnnuaire("La liste de l'annuaire n'a pas la forme attendue (« elements » manquant).");
        }

        $pairs = [];
        foreach ($elements as $element) {
            // Une ligne mal formée est ignorée : elle ne doit pas faire tomber toute la liste.
            if (is_array($element) && is_string($element['identifiant'] ?? null) && is_string($element['url'] ?? null)) {
                $nom = $element['nom'] ?? null;
                $pairs[] = new PairAnnonce($element['identifiant'], $element['url'], is_string($nom) ? $nom : null);
            }
        }

        return $pairs;
    }

    /** @param callable(PendingRequest): Response $appel */
    private function envoyer(callable $appel): Response
    {
        try {
            return $appel($this->requete());
        } catch (ConnectionException $e) {
            throw new ErreurAnnuaire("L'annuaire est injoignable : {$e->getMessage()}", 0, $e);
        }
    }

    private function requete(): PendingRequest
    {
        $secret = config('babel.announce_secret');
        if (! is_string($secret) || $secret === '') {
            throw new ErreurAnnuaire("BABEL_ANNOUNCE_SECRET est vide : le secret d'annonce remis par la plateforme manque.");
        }

        return Http::withToken($secret)
            ->acceptJson()
            ->timeout((int) config('babel.annuaire_delai', 10))
            ->withoutRedirecting();
    }

    private function adresse(string $chemin): string
    {
        $base = config('babel.annuaire_url');
        if (! is_string($base) || trim($base) === '') {
            throw new ErreurAnnuaire("BABEL_ANNUAIRE_URL est vide : l'adresse de l'annuaire n'est pas configurée.");
        }

        return rtrim($base, '/').$chemin;
    }
}
