<?php

declare(strict_types=1);

namespace App\Services\Reseau;

use App\Services\Engine\ResultatValidation;
use App\Services\Engine\Valideur;
use DateTimeImmutable;
use Throwable;

/**
 * « Vérifie la réponse contre le contrat » : ce qu'un pair renvoie n'est jamais cru sur parole.
 *
 * Chaque méthode reçoit le JSON décodé et renvoie la même donnée si elle respecte `docs/openapi.yaml`
 * (champs exactement ceux du schéma : `additionalProperties: false`, types, motifs, bornes), ou null.
 * Un pair qui répond hors contrat est traité comme un pair en échec : on ne montre rien de sa réponse.
 */
final class ReponsesDePair
{
    public function __construct(private readonly Valideur $valideur) {}

    /** ResultatRecherche (POST /recherches) : l'adresse trouvée chez le pair. */
    public function recherche(mixed $json, string $bibliotheque, string $mode): ?array
    {
        if (! $this->objet($json, ['bibliotheque', 'texte_normalise', 'mode', 'adresse'])) {
            return null;
        }

        $ok = $json['bibliotheque'] === $bibliotheque
            && $json['mode'] === $mode
            && is_string($json['texte_normalise'])
            && preg_match('/^[a-z ,.]{1,3200}\z/', $json['texte_normalise']) === 1
            && $this->adressePage($json['adresse']);

        return $ok ? $json : null;
    }

    /** ResultatLectures (POST /lectures) : une entrée par adresse demandée, dans le même ordre. */
    public function lectures(mixed $json, string $bibliotheque, array $demandees): ?array
    {
        if (! $this->objet($json, ['bibliotheque', 'elements']) || $json['bibliotheque'] !== $bibliotheque
            || ! is_array($json['elements']) || ! array_is_list($json['elements'])
            || count($json['elements']) !== count($demandees)) {
            return null;
        }

        foreach ($json['elements'] as $i => $element) {
            if (! $this->objet($element, ['adresse', 'texte', 'erreurs']) || $element['adresse'] !== $demandees[$i]) {
                return null;
            }
            $texteOk = is_string($element['texte']) && preg_match('/^[a-z ,.]{3200}\z/', $element['texte']) === 1;
            $refuseeOk = $element['texte'] === null && $this->erreurs($element['erreurs']);
            if (! ($texteOk && $element['erreurs'] === null) && ! $refuseeOk) {
                return null;
            }
        }

        return $json;
    }

    /** ListeCollections (GET /collections). */
    public function listeCollections(mixed $json): ?array
    {
        if (! $this->objet($json, ['lot', 'taille', 'total', 'elements'])
            || ! $this->entier($json['lot'], 1) || ! $this->entier($json['taille'], 1, 50) || ! $this->entier($json['total'], 0)
            || ! is_array($json['elements']) || ! array_is_list($json['elements']) || count($json['elements']) > 50) {
            return null;
        }

        foreach ($json['elements'] as $resume) {
            if (! $this->resumeCollection($resume)) {
                return null;
            }
        }

        return $json;
    }

    /** Collection (GET /collections/{id}) : le résumé, plus les pages par position croissante. */
    public function collection(mixed $json): ?array
    {
        if (! $this->objet($json, ['id', 'nom', 'description', 'nombre_pages', 'maj_le', 'pages']) || ! $this->resumeCollection($json, true)
            || ! is_array($json['pages']) || ! array_is_list($json['pages']) || count($json['pages']) !== $json['nombre_pages']) {
            return null;
        }

        $precedente = 0;
        foreach ($json['pages'] as $page) {
            if (! $this->objet($page, ['position', 'bibliotheque', 'adresse'])
                || ! $this->entier($page['position'], $precedente + 1)
                || ! $this->identifiant($page['bibliotheque']) || ! $this->adressePage($page['adresse'])) {
                return null;
            }
            $precedente = $page['position'];
        }

        return $json;
    }

    /** ListePagesPopulaires (GET /pages-populaires) : par score décroissant. */
    public function pagesPopulaires(mixed $json): ?array
    {
        if (! $this->objet($json, ['elements']) || ! is_array($json['elements']) || ! array_is_list($json['elements']) || count($json['elements']) > 50) {
            return null;
        }

        $precedent = PHP_INT_MAX;
        foreach ($json['elements'] as $page) {
            if (! $this->objet($page, ['bibliotheque', 'adresse', 'score']) || ! $this->identifiant($page['bibliotheque'])
                || ! $this->adressePage($page['adresse']) || ! $this->entier($page['score']) || $page['score'] > $precedent) {
                return null;
            }
            $precedent = $page['score'];
        }

        return $json;
    }

    /** ListeTextesBannis (GET /textes-bannis) : l'empreinte doit être celle du texte. */
    public function textesBannis(mixed $json): ?array
    {
        if (! $this->objet($json, ['elements']) || ! is_array($json['elements']) || ! array_is_list($json['elements'])) {
            return null;
        }

        foreach ($json['elements'] as $texte) {
            if (! $this->objet($texte, ['texte', 'empreinte', 'motif', 'banni_le'])
                || ! is_string($texte['texte']) || strlen($texte['texte']) > 3200
                || preg_match('/^[a-z,.]+( [a-z,.]+)*\z/', $texte['texte']) !== 1
                || $texte['empreinte'] !== hash('sha256', $texte['texte'])
                || ! ($texte['motif'] === null || (is_string($texte['motif']) && mb_strlen($texte['motif']) <= 500))
                || ! $this->dateHeure($texte['banni_le'])) {
                return null;
            }
        }

        return $json;
    }

    private function resumeCollection(mixed $c, bool $avecPages = false): bool
    {
        return is_array($c)
            && isset($c['id'], $c['nom'], $c['maj_le']) && array_key_exists('description', $c) && array_key_exists('nombre_pages', $c)
            && is_string($c['id']) && preg_match('/^[A-Za-z0-9_-]{1,64}\z/', $c['id']) === 1
            && is_string($c['nom']) && mb_strlen($c['nom']) >= 1 && mb_strlen($c['nom']) <= 100
            && is_string($c['description']) && mb_strlen($c['description']) <= 1000
            && $this->entier($c['nombre_pages'], 0)
            && $this->dateHeure($c['maj_le'])
            && array_diff(array_keys($c), ['id', 'nom', 'description', 'nombre_pages', 'maj_le', ...($avecPages ? ['pages'] : [])]) === [];
    }

    /** Schéma `Erreur` : une liste non vide de {code, champ, message}. */
    private function erreurs(mixed $erreurs): bool
    {
        if (! is_array($erreurs) || $erreurs === [] || ! array_is_list($erreurs)) {
            return false;
        }
        foreach ($erreurs as $e) {
            if (! $this->objet($e, ['code', 'champ', 'message']) || ! is_string($e['code']) || ! is_string($e['message'])
                || ! ($e['champ'] === null || is_string($e['champ']))) {
                return false;
            }
        }

        return true;
    }

    /** Une adresse de PAGE bien écrite (F5) : ni un livre, ni une adresse qui sort de la bibliothèque. */
    private function adressePage(mixed $adresse): bool
    {
        if (! is_string($adresse)) {
            return false;
        }

        try {
            $resultat = $this->valideur->valider($adresse);
        } catch (Throwable) {
            return false;
        }

        return $resultat->valide && $resultat->niveau === ResultatValidation::NIVEAU_PAGE;
    }

    private function identifiant(mixed $valeur): bool
    {
        return is_string($valeur) && preg_match('/^[a-z0-9][a-z0-9-]{1,31}\z/', $valeur) === 1;
    }

    private function dateHeure(mixed $valeur): bool
    {
        return is_string($valeur)
            && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:\d{2})\z/', $valeur) === 1
            && DateTimeImmutable::createFromFormat(DATE_RFC3339, preg_replace('/\.\d+/', '', $valeur)) !== false;
    }

    private function entier(mixed $valeur, ?int $min = null, ?int $max = null): bool
    {
        return is_int($valeur) && ($min === null || $valeur >= $min) && ($max === null || $valeur <= $max);
    }

    /**
     * Un objet JSON (pas une liste) dont les champs sont EXACTEMENT ceux du schéma.
     *
     * @param  list<string>  $champs
     */
    private function objet(mixed $valeur, array $champs): bool
    {
        if (! is_array($valeur) || ($valeur !== [] && array_is_list($valeur))) {
            return false;
        }
        $cles = array_keys($valeur);
        sort($cles);
        sort($champs);

        return $cles === $champs;
    }
}
