<?php

declare(strict_types=1);

namespace App\Services\Annuaire;

use App\Enums\EtatBibliotheque;
use App\Models\Bibliotheque;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * Contrôle régulier d'un pair (A9) : on lit son identité (B6) avec 2 secondes au plus.
 *
 *  - réponse conforme : en ligne, compteur remis à 0, nom et description mis à jour, empreinte gardée ;
 *    si l'empreinte a changé, `cle_changee_le` est daté (les adresses vers ce pair ne désignent plus les mêmes textes) ;
 *  - 429 : le pair est occupé, pas en panne : on ne touche à rien, surtout pas au compteur ;
 *  - tout le reste (injoignable, trop lent, erreur HTTP, réponse hors contrat) : un échec de plus,
 *    et hors ligne au troisième de suite.
 */
final class ControleurDePair
{
    public function controler(Bibliotheque $pair): void
    {
        if ($pair->est_locale) {
            return; // on ne se contrôle pas soi-même
        }

        $delai = (int) config('babel.pair_delai', 2);

        try {
            $reponse = Http::acceptJson()
                ->connectTimeout($delai)
                ->timeout($delai)
                ->withoutRedirecting()
                ->get(rtrim($pair->url_actuelle, '/').'/api/v1/identite');
        } catch (ConnectionException) {
            $this->echec($pair);

            return;
        }

        if ($reponse->status() === 429) {
            return;
        }

        $identite = $reponse->successful() ? $this->identiteConforme($reponse->json(), $pair) : null;
        if ($identite === null) {
            $this->echec($pair);

            return;
        }

        $this->succes($pair, $identite);
    }

    /** @return array<string, mixed>|null l'identité si elle respecte le schéma `Identite` du contrat, sinon null */
    private function identiteConforme(mixed $json, Bibliotheque $pair): ?array
    {
        if (! is_array($json) || array_is_list($json)) {
            return null;
        }

        try {
            $valide = Validator::make($json, [
                'identifiant' => ['required', 'string', 'regex:/^[a-z0-9][a-z0-9-]{1,31}$/'],
                'nom' => ['required', 'string', 'min:1', 'max:100'],
                'description' => ['present', 'string', 'max:1000'],
                'empreinte_cle' => ['required', 'string', 'regex:/^[0-9a-f]{64}$/'],
                'algorithme' => ['required', 'in:babel-moteur/v1'],
                'contrat' => ['required', 'string', 'regex:/^1\.[0-9]+\.[0-9]+$/'],
                'application_web' => ['present', 'nullable', 'url'],
            ])->passes();
        } catch (Throwable) {
            $valide = false;
        }

        // additionalProperties: false dans le contrat ; et l'URL doit toujours désigner CETTE bibliothèque.
        $champs = ['identifiant', 'nom', 'description', 'empreinte_cle', 'algorithme', 'contrat', 'application_web'];
        if (! $valide || array_diff(array_keys($json), $champs) !== [] || $json['identifiant'] !== $pair->identifiant) {
            return null;
        }

        return $json;
    }

    /** @param array<string, mixed> $identite */
    private function succes(Bibliotheque $pair, array $identite): void
    {
        $changee = $pair->empreinte_cle !== null && $pair->empreinte_cle !== $identite['empreinte_cle'];

        $pair->fill([
            'nom' => $identite['nom'],
            'description' => $identite['description'] === '' ? null : $identite['description'],
            'empreinte_cle' => $identite['empreinte_cle'],
            'etat' => EtatBibliotheque::EnLigne,
            'echecs_consecutifs' => 0,
            'dernier_contact_le' => now(),
        ]);
        if ($changee) {
            $pair->cle_changee_le = now();
        }
        $pair->save();
    }

    private function echec(Bibliotheque $pair): void
    {
        $pair->echecs_consecutifs++;
        if ($pair->echecs_consecutifs >= (int) config('babel.pair_echecs_max', 3)) {
            $pair->etat = EtatBibliotheque::HorsLigne;
        }
        $pair->save();
    }
}
