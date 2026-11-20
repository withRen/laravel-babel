<?php

declare(strict_types=1);

namespace App\Services\Membre\Donnees;

use App\Models\Bibliotheque;
use App\Services\Engine\ResultatValidation;
use App\Services\Engine\Valideur;

/**
 * M12 : vérifie un fichier d'export EN ENTIER contre le schéma ExportCompte du contrat, avant tout import.
 *
 * Une seule erreur par problème trouvé (ERR_INVALID_IMPORT), avec le « champ » écrit comme dans le contrat :
 * « marque_pages[3].adresse ». Le contrat dit « additionalProperties: false » partout : un champ inconnu est
 * donc une erreur, pas une chose à ignorer. Chaque adresse est jugée par le moteur (Valideur::valider), comme
 * partout ailleurs : un fichier ne contourne pas la règle qui s'applique à une requête.
 *
 * On s'arrête à 50 erreurs : un fichier de 10 Mo tout faux ne doit pas produire une réponse de 10 Mo, et le
 * membre n'a besoin que des premiers problèmes pour comprendre.
 */
final class ValideurDExport
{
    public const MAX_ERREURS = 50;

    private const RACINE = ['format', 'exporte_le', 'bibliotheque', 'profil', 'marque_pages', 'collections', 'annotations', 'votes', 'recherches', 'liens_courts'];

    private const IDENTIFIANT = '/\A[a-z0-9][a-z0-9-]{1,31}\z/';

    private const DATE = '/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:\d{2})\z/';

    /** @var list<array{code: string, champ: string|null, message: string}> */
    private array $erreurs = [];

    /** @var array<string, bool> adresses déjà jugées (une page revient souvent) : adresse => valide */
    private array $adressesJugees = [];

    /** @var array<string, true> */
    private array $connues = [];

    public function __construct(private readonly Valideur $valideur) {}

    /**
     * @return list<array{code: string, champ: string|null, message: string}>
     */
    public function erreurs(mixed $fichier): array
    {
        $this->erreurs = [];
        $this->adressesJugees = [];
        $this->connues = array_fill_keys(Bibliotheque::query()->pluck('identifiant')->all(), true);

        if (! $this->estObjet($fichier)) {
            $this->erreur(null, 'Le fichier doit être un objet JSON.');

            return $this->erreurs;
        }

        $this->cles('', $fichier, self::RACINE);

        if (($fichier['format'] ?? null) !== ExportDeCompte::FORMAT) {
            $this->erreur('format', 'Le format doit être '.ExportDeCompte::FORMAT.'.');
        }
        $this->date('exporte_le', $fichier['exporte_le'] ?? null);
        $this->identifiant('bibliotheque', $fichier['bibliotheque'] ?? null);
        $this->profil($fichier['profil'] ?? null);

        $this->liste('marque_pages', $fichier['marque_pages'] ?? null, fn (string $c, array $m) => $this->marquePage($c, $m));
        $this->liste('collections', $fichier['collections'] ?? null, fn (string $c, array $m) => $this->collection($c, $m));
        $this->liste('annotations', $fichier['annotations'] ?? null, fn (string $c, array $m) => $this->annotation($c, $m));
        $this->liste('votes', $fichier['votes'] ?? null, fn (string $c, array $m) => $this->vote($c, $m));
        $this->liste('recherches', $fichier['recherches'] ?? null, fn (string $c, array $m) => $this->recherche($c, $m));
        $this->liste('liens_courts', $fichier['liens_courts'] ?? null, fn (string $c, array $m) => $this->lienCourt($c, $m));

        return $this->erreurs;
    }

    // ---- les sections --------------------------------------------------------------------------

    private function profil(mixed $profil): void
    {
        if (! $this->objet('profil', $profil, ['pseudo', 'email', 'cree_le'])) {
            return;
        }
        $this->texte('profil.pseudo', $profil['pseudo'], 1);
        if (! is_string($profil['email']) || filter_var($profil['email'], FILTER_VALIDATE_EMAIL) === false) {
            $this->erreur('profil.email', "L'email n'est pas valide.");
        }
        $this->date('profil.cree_le', $profil['cree_le']);
    }

    /** @param array<string, mixed> $m */
    private function marquePage(string $c, array $m): void
    {
        $this->page($c, $m, true);
        $this->texteOuNull("$c.titre", $m['titre']);
        $this->date("$c.cree_le", $m['cree_le']);
    }

    /** @param array<string, mixed> $m */
    private function collection(string $c, array $m): void
    {
        $this->texte("$c.nom", $m['nom'], 1);
        $this->texte("$c.description", $m['description'], 0);
        $this->booleen("$c.est_publique", $m['est_publique']);
        $this->date("$c.cree_le", $m['cree_le']);
        $this->liste("$c.pages", $m['pages'], function (string $cp, array $page): void {
            $this->entier("$cp.position", $page['position'], 1);
            $this->page($cp, $page, true);
        }, ['position', 'bibliotheque', 'adresse']);
    }

    /** @param array<string, mixed> $m */
    private function annotation(string $c, array $m): void
    {
        $this->page($c, $m, true);
        if (! is_string($m['contenu']) || mb_strlen($m['contenu']) < 1 || mb_strlen($m['contenu']) > 2000) {
            $this->erreur("$c.contenu", 'Le contenu doit faire de 1 à 2 000 caractères.');
        }
        $this->booleen("$c.est_publique", $m['est_publique']);
        $this->date("$c.cree_le", $m['cree_le']);
        $this->date("$c.maj_le", $m['maj_le']);
    }

    /** @param array<string, mixed> $m */
    private function vote(string $c, array $m): void
    {
        $this->page($c, $m, false);
        if ($m['valeur'] !== 1 && $m['valeur'] !== -1) {
            $this->erreur("$c.valeur", 'La valeur doit être 1 ou -1.');
        }
        $this->date("$c.cree_le", $m['cree_le']);
    }

    /** @param array<string, mixed> $m */
    private function recherche(string $c, array $m): void
    {
        $this->texte("$c.texte", $m['texte'], 0);
        if (! in_array($m['mode'], ['exact', 'bruit', 'page'], true)) {
            $this->erreur("$c.mode", 'Le mode doit être exact, bruit ou page.');
        }
        if (! in_array($m['portee'], ['locale', 'reseau'], true)) {
            $this->erreur("$c.portee", 'La portée doit être locale ou reseau.');
        }
        $this->date("$c.cree_le", $m['cree_le']);
    }

    /** @param array<string, mixed> $m */
    private function lienCourt(string $c, array $m): void
    {
        $this->texte("$c.slug", $m['slug'], 1);
        $this->texteOuNull("$c.passage", $m['passage']);
        $this->entier("$c.visites", $m['visites'], 0);
        if ($m['expire_le'] !== null) {
            $this->date("$c.expire_le", $m['expire_le']);
        }
        $this->date("$c.cree_le", $m['cree_le']);

        $cible = $m['cible'];
        if (! $this->estObjet($cible) || ! in_array($cible['type'] ?? null, ['adresse', 'collection'], true)) {
            $this->erreur("$c.cible", 'La cible doit être de type adresse ou collection.');

            return;
        }
        if ($cible['type'] === 'collection') {
            if ($this->objet("$c.cible", $cible, ['type', 'collection'])) {
                $this->entier("$c.cible.collection", $cible['collection'], 0);
            }
        } elseif ($this->objet("$c.cible", $cible, ['type', 'bibliotheque', 'adresse'])) {
            // Un lien court peut viser un livre entier : adresse de page OU de livre.
            $this->page("$c.cible", $cible, false, true);
        }
    }

    // ---- les briques -----------------------------------------------------------------------------

    /**
     * Une page : « bibliotheque » + « adresse ». $recree dit que la ligne sera recréée par l'import : sa
     * bibliothèque doit alors être connue de notre annuaire (sinon aucune ligne ADRESSE ne peut la porter).
     *
     * @param  array<string, mixed>  $objet
     */
    private function page(string $chemin, array $objet, bool $recree, bool $livreAutorise = false): void
    {
        $identifiant = $objet['bibliotheque'];
        if ($this->identifiant("$chemin.bibliotheque", $identifiant) && $recree && ! isset($this->connues[$identifiant])) {
            $this->erreur("$chemin.bibliotheque", "Cette bibliothèque ne fait pas partie du réseau : l'import ne peut pas y rattacher la page.");
        }

        $adresse = $objet['adresse'];
        if (! is_string($adresse)) {
            $this->erreur("$chemin.adresse", "L'adresse doit être un texte.");

            return;
        }

        $cle = ($livreAutorise ? 'l:' : 'p:').$adresse;
        $this->adressesJugees[$cle] ??= $this->adresseValide($adresse, $livreAutorise);
        if (! $this->adressesJugees[$cle]) {
            $this->erreur("$chemin.adresse", $this->messageAdresse($adresse));
        }
    }

    private function adresseValide(string $adresse, bool $livreAutorise): bool
    {
        $validation = $this->valideur->valider($adresse);

        return $validation->valide && ($livreAutorise || $validation->niveau === ResultatValidation::NIVEAU_PAGE);
    }

    private function messageAdresse(string $adresse): string
    {
        $validation = $this->valideur->valider($adresse);

        return $validation->valide
            ? 'Cette adresse est celle d\'un livre : il faut une adresse de page.'
            : ($validation->erreursVersTableau()[0]['message'] ?? 'Cette adresse est mal écrite.');
    }

    /**
     * @param  callable(string, array<string, mixed>): void  $verifierElement
     * @param  list<string>|null  $cles  les clés exactes d'un élément (par défaut : celles de l'élément lui-même, déjà connues)
     */
    private function liste(string $chemin, mixed $valeur, callable $verifierElement, ?array $cles = null): void
    {
        if (! is_array($valeur) || ! array_is_list($valeur)) {
            $this->erreur($chemin, 'Ce champ doit être une liste.');

            return;
        }

        foreach ($valeur as $indice => $element) {
            if ($this->complet()) {
                return;
            }
            $cheminElement = "{$chemin}[{$indice}]";
            if ($this->objet($cheminElement, $element, $cles ?? self::CLES[$chemin] ?? [])) {
                $verifierElement($cheminElement, $element);
            }
        }
    }

    /** Les clés exactes de chaque élément de liste du contrat. */
    private const CLES = [
        'marque_pages' => ['bibliotheque', 'adresse', 'titre', 'cree_le'],
        'collections' => ['nom', 'description', 'est_publique', 'pages', 'cree_le'],
        'annotations' => ['bibliotheque', 'adresse', 'contenu', 'est_publique', 'cree_le', 'maj_le'],
        'votes' => ['bibliotheque', 'adresse', 'valeur', 'cree_le'],
        'recherches' => ['texte', 'mode', 'portee', 'cree_le'],
        'liens_courts' => ['slug', 'cible', 'passage', 'visites', 'expire_le', 'cree_le'],
    ];

    /**
     * Un objet aux clés EXACTES : ni une de moins (champ obligatoire), ni une de plus (additionalProperties: false).
     * Dit si l'on peut lire ses champs sans risque.
     *
     * @param  list<string>  $cles
     */
    private function objet(string $chemin, mixed $valeur, array $cles): bool
    {
        if (! $this->estObjet($valeur)) {
            $this->erreur($chemin, 'Ce champ doit être un objet.');

            return false;
        }

        return $this->cles($chemin, $valeur, $cles);
    }

    /**
     * @param  array<string, mixed>  $objet
     * @param  list<string>  $cles
     */
    private function cles(string $chemin, array $objet, array $cles): bool
    {
        $complet = true;
        $prefixe = $chemin === '' ? '' : "$chemin.";

        foreach ($cles as $cle) {
            if (! array_key_exists($cle, $objet)) {
                $this->erreur($prefixe.$cle, 'Ce champ est obligatoire.');
                $complet = false;
            }
        }
        foreach (array_keys($objet) as $cle) {
            if (! in_array($cle, $cles, true)) {
                $this->erreur($prefixe.$cle, 'Ce champ ne fait pas partie du format babel-export/v1.');
                $complet = false;
            }
        }

        return $complet;
    }

    private function estObjet(mixed $valeur): bool
    {
        // Un objet JSON est décodé en tableau associatif ; une liste (ou un tableau vide) n'en est pas un.
        return is_array($valeur) && ($valeur === [] ? false : ! array_is_list($valeur));
    }

    private function identifiant(string $chemin, mixed $valeur): bool
    {
        if (! is_string($valeur) || preg_match(self::IDENTIFIANT, $valeur) !== 1) {
            $this->erreur($chemin, "L'identifiant de la bibliothèque n'est pas valide.");

            return false;
        }

        return true;
    }

    private function date(string $chemin, mixed $valeur): void
    {
        if (! is_string($valeur) || preg_match(self::DATE, $valeur) !== 1 || date_create($valeur) === false) {
            $this->erreur($chemin, 'La date doit être au format ISO 8601 avec fuseau, par exemple 2026-11-20T14:05:00+01:00.');
        }
    }

    private function texte(string $chemin, mixed $valeur, int $min): void
    {
        if (! is_string($valeur) || mb_strlen($valeur) < $min) {
            $this->erreur($chemin, $min > 0 ? 'Ce champ doit être un texte non vide.' : 'Ce champ doit être un texte.');
        }
    }

    private function texteOuNull(string $chemin, mixed $valeur): void
    {
        if ($valeur !== null && ! is_string($valeur)) {
            $this->erreur($chemin, 'Ce champ doit être un texte, ou null.');
        }
    }

    private function entier(string $chemin, mixed $valeur, int $min): void
    {
        if (! is_int($valeur) || $valeur < $min) {
            $this->erreur($chemin, "Ce champ doit être un entier à partir de $min.");
        }
    }

    private function booleen(string $chemin, mixed $valeur): void
    {
        if (! is_bool($valeur)) {
            $this->erreur($chemin, 'Ce champ doit être true ou false.');
        }
    }

    private function erreur(?string $champ, string $message): void
    {
        if (! $this->complet()) {
            $this->erreurs[] = ['code' => 'ERR_INVALID_IMPORT', 'champ' => $champ, 'message' => $message];
        }
    }

    private function complet(): bool
    {
        return count($this->erreurs) >= self::MAX_ERREURS;
    }
}
