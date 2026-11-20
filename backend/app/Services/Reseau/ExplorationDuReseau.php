<?php

declare(strict_types=1);

namespace App\Services\Reseau;

use App\Exceptions\ErreurApi;
use App\Models\Bibliotheque;

/**
 * Parcourir les collections publiques et les pages populaires des autres bibliothèques (V21).
 *
 * Notre serveur appelle `GET /collections` ou `GET /pages-populaires` chez le pair (l'application web, elle, ne parle
 * jamais directement aux pairs : un seul point de contrôle), vérifie la réponse contre le contrat, puis retire les
 * pages qui contiennent un de NOS textes bannis. Rien n'est enregistré.
 */
final class ExplorationDuReseau
{
    public function __construct(private readonly ClientPair $client, private readonly FiltreTextesBannis $filtre) {}

    /** @throws ErreurApi 404 ERR_UNKNOWN_LIBRARY : l'identifiant n'est pas dans notre liste de pairs */
    public function bibliotheque(string $identifiant): Bibliotheque
    {
        return Bibliotheque::query()->where('identifiant', $identifiant)->first()
            ?? throw ErreurApi::bibliothequeInconnue();
    }

    /** Les collections publiques du pair, telles qu'il les publie (la liste ne contient aucune page à vérifier). */
    public function collections(Bibliotheque $pair, int $lot, int $taille): array
    {
        return $this->parPair(fn () => $this->client->collections($pair, $lot, $taille));
    }

    /**
     * Une collection du pair, sans les pages qu'on ne peut pas montrer ici. Les pages gardent leur position (les
     * autres ne se décalent pas) et `nombre_pages` ne compte que celles qui restent, comme chez un pair qui filtre.
     */
    public function collection(Bibliotheque $pair, string $id): array
    {
        $collection = $this->parPair(fn () => $this->client->collection($pair, $id))
            ?? throw ErreurApi::nonTrouve("Cette ressource n'existe pas ou n'est pas visible.", 'id');

        $montrables = $this->parPair(fn () => $this->filtre->montrables($collection['pages'], $pair));
        $collection['pages'] = array_values(array_filter(
            $collection['pages'],
            fn (array $page) => $montrables[FiltreTextesBannis::cle($page['bibliotheque'], $page['adresse'])] ?? false,
        ));
        $collection['nombre_pages'] = count($collection['pages']);

        return $collection;
    }

    /** Les pages populaires du pair, sans celles qu'on ne peut pas montrer ici. */
    public function pagesPopulaires(Bibliotheque $pair, int $taille): array
    {
        $liste = $this->parPair(fn () => $this->client->pagesPopulaires($pair, $taille));

        $montrables = $this->parPair(fn () => $this->filtre->montrables($liste['elements'], $pair));

        return ['elements' => array_values(array_filter(
            $liste['elements'],
            fn (array $page) => $montrables[FiltreTextesBannis::cle($page['bibliotheque'], $page['adresse'])] ?? false,
        ))];
    }

    /** Un pair qui n'a pas répondu devient 502 (injoignable) ou 503 (occupé), dans le format d'erreur du contrat. */
    private function parPair(\Closure $appel): mixed
    {
        try {
            return $appel();
        } catch (ErreurPair $e) {
            throw $e->versErreurApi();
        }
    }
}
