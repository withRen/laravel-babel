<?php

declare(strict_types=1);

namespace App\Services\Reseau;

use App\Enums\EtatBibliotheque;
use App\Models\Bibliotheque;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Notre serveur qui appelle l'API publique d'un autre (la même dans les 10 bibliothèques).
 *
 * Règles communes à tous les appels :
 *  - 2 secondes au plus (`babel.pair_delai`), connexion comprise, et aucune redirection suivie ;
 *  - la réponse est vérifiée contre le contrat (ReponsesDePair) avant d'être montrée ou utilisée ;
 *  - un 429 est un pair occupé, pas un pair en panne : le compteur d'échecs ne bouge pas ;
 *  - tout le reste (éteint, lent, erreur HTTP, hors contrat) ajoute un échec, et le troisième de suite passe le pair
 *    hors ligne (A9). Le pair ne reprend sa place qu'au prochain contrôle réussi.
 *
 * Sans jeton, sauf `textesBannis` : le jeton réseau ne part qu'à cette route, jamais ailleurs.
 */
final class ClientPair
{
    public function __construct(private readonly ReponsesDePair $reponses) {}

    /** POST /recherches chez le pair (B2). @return array{bibliotheque: string, texte_normalise: string, mode: string, adresse: string} */
    public function chercher(Bibliotheque $pair, string $texte, string $mode): array
    {
        $reponse = $this->envoyer($pair, fn (PendingRequest $r, string $base) => $r->asJson()->post($base.'/recherches', ['texte' => $texte, 'mode' => $mode]));

        return $this->exiger($pair, $reponse, fn (mixed $json) => $this->reponses->recherche($json, $pair->identifiant, $mode));
    }

    /**
     * POST /lectures chez le pair (B1) : le texte de chaque page, par lots de 50.
     * Une adresse que le pair refuse n'est pas une panne : son texte vaut null.
     *
     * @param  list<string>  $adresses
     * @return array<string, string|null> adresse => texte de la page
     */
    public function lire(Bibliotheque $pair, array $adresses): array
    {
        $textes = [];
        foreach (array_chunk(array_values(array_unique($adresses)), 50) as $lot) {
            $reponse = $this->envoyer($pair, fn (PendingRequest $r, string $base) => $r->asJson()->post($base.'/lectures', ['adresses' => $lot]));
            $json = $this->exiger($pair, $reponse, fn (mixed $json) => $this->reponses->lectures($json, $pair->identifiant, $lot));
            foreach ($json['elements'] as $element) {
                $textes[$element['adresse']] = $element['texte'];
            }
        }

        return $textes;
    }

    /** GET /collections chez le pair (B3). */
    public function collections(Bibliotheque $pair, int $lot, int $taille): array
    {
        $reponse = $this->envoyer($pair, fn (PendingRequest $r, string $base) => $r->get($base.'/collections', ['lot' => $lot, 'taille' => $taille]));

        return $this->exiger($pair, $reponse, fn (mixed $json) => $this->reponses->listeCollections($json));
    }

    /** GET /collections/{id} chez le pair (B3) ; null si elle n'existe pas ou n'est pas publique (404). */
    public function collection(Bibliotheque $pair, string $id): ?array
    {
        $reponse = $this->envoyer($pair, fn (PendingRequest $r, string $base) => $r->get($base.'/collections/'.rawurlencode($id)));
        if ($reponse->status() === 404) {
            return null;
        }

        return $this->exiger($pair, $reponse, fn (mixed $json) => $this->reponses->collection($json));
    }

    /** GET /pages-populaires chez le pair (B4). */
    public function pagesPopulaires(Bibliotheque $pair, int $taille): array
    {
        $reponse = $this->envoyer($pair, fn (PendingRequest $r, string $base) => $r->get($base.'/pages-populaires', ['taille' => $taille]));

        return $this->exiger($pair, $reponse, fn (mixed $json) => $this->reponses->pagesPopulaires($json));
    }

    /**
     * GET /textes-bannis chez le pair (B5), avec le jeton réseau. Un 401 veut dire que NOTRE jeton est refusé :
     * ce n'est pas une panne du pair, on le signale à part (null) sans toucher à son compteur.
     */
    public function textesBannis(Bibliotheque $pair, string $jeton): ?array
    {
        $reponse = $this->envoyer($pair, fn (PendingRequest $r, string $base) => $r->withToken($jeton)->get($base.'/textes-bannis'));
        if ($reponse->status() === 401) {
            return null;
        }

        return $this->exiger($pair, $reponse, fn (mixed $json) => $this->reponses->textesBannis($json));
    }

    /** Un échec de plus ; hors ligne au troisième de suite. Atomique : plusieurs tâches peuvent échouer en même temps. */
    public function noterEchec(Bibliotheque $pair): void
    {
        Bibliotheque::query()->whereKey($pair->getKey())->increment('echecs_consecutifs');

        Bibliotheque::query()
            ->whereKey($pair->getKey())
            ->where('echecs_consecutifs', '>=', (int) config('babel.pair_echecs_max', 3))
            ->update(['etat' => EtatBibliotheque::HorsLigne]);
    }

    /** @param \Closure(PendingRequest, string): Response $appel */
    private function envoyer(Bibliotheque $pair, \Closure $appel): Response
    {
        $delai = (int) config('babel.pair_delai', 2);
        $requete = Http::acceptJson()->connectTimeout($delai)->timeout($delai)->withoutRedirecting();

        try {
            $reponse = $appel($requete, rtrim($pair->url_actuelle, '/').'/api/v1');
        } catch (ConnectionException $e) {
            $this->noterEchec($pair);

            throw ErreurPair::injoignable($pair->identifiant, $e->getMessage());
        }

        if ($reponse->status() === 429) {
            $apres = $reponse->header('Retry-After');

            throw ErreurPair::occupe($pair->identifiant, ctype_digit($apres) && (int) $apres >= 1 ? (int) $apres : null);
        }

        return $reponse;
    }

    /**
     * @param  \Closure(mixed): (array|null)  $verifie
     */
    private function exiger(Bibliotheque $pair, Response $reponse, \Closure $verifie): array
    {
        $json = $reponse->status() === 200 ? $verifie($reponse->json()) : null;

        if ($json === null) {
            $this->noterEchec($pair);

            throw ErreurPair::injoignable($pair->identifiant, "Réponse hors contrat (HTTP {$reponse->status()}).");
        }

        return $json;
    }
}
