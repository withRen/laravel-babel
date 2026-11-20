<?php

declare(strict_types=1);

namespace App\Services\Membre;

use App\Exceptions\ErreurApi;
use App\Models\Adresse;
use App\Models\Bibliotheque;
use App\Services\Engine\ResultatValidation;
use App\Services\Engine\Valideur;
use Babel\Moteur\Erreur\CodeErreur;
use Babel\Moteur\Erreur\ErreurMoteur;
use Illuminate\Database\UniqueConstraintViolationException;
use LogicException;

/**
 * De « l'adresse écrite que le membre envoie » à « la ligne ADRESSE » (règle 2 : une adresse n'est enregistrée
 * qu'une fois). Toutes les routes membre (marque-pages, votes, collections, annotations, import) passent par ici.
 *
 * Trois contrôles, toujours dans cet ordre :
 *  1. la bibliothèque existe dans notre annuaire (404 ERR_UNKNOWN_LIBRARY) ;
 *  2. l'adresse est celle d'une PAGE bien écrite qui existe (le moteur dit 422, ou 404 si seule la limite est dépassée) ;
 *  3. seulement alors, la ligne est cherchée, ou créée.
 *
 * Marque-pages, annotations, votes et pages de collection visent toujours une page, jamais un livre (règle 4).
 * L'adresse n'est jamais corrigée : celle qui est enregistrée est celle qui a été validée, à l'octet près.
 */
final class Adresses
{
    private ?Bibliotheque $locale = null;

    /** @var array<string, Bibliotheque|null> */
    private array $connues = [];

    /** @var array<string, true> adresses de page déjà jugées valides (un import en répète des milliers) */
    private array $verifiees = [];

    public function __construct(private readonly Valideur $valideur) {}

    /** La bibliothèque demandée ; absente ou égale à la nôtre, c'est la bibliothèque locale. */
    public function bibliotheque(?string $identifiant, string $champ = 'bibliotheque'): Bibliotheque
    {
        $locale = $this->locale();

        if ($identifiant === null || $identifiant === $locale->identifiant) {
            return $locale;
        }

        $bibliotheque = $this->connues[$identifiant] ??= Bibliotheque::query()->where('identifiant', $identifiant)->first();

        return $bibliotheque ?? throw ErreurApi::une(404, 'ERR_UNKNOWN_LIBRARY', 'Cette bibliothèque ne fait pas partie du réseau.', $champ);
    }

    public function locale(): Bibliotheque
    {
        return $this->locale ??= Bibliotheque::locale()
            ?? throw new LogicException('Aucune bibliothèque locale : lancer le seeder BibliothequeLocaleSeeder.');
    }

    /**
     * Refuse une adresse qui n'est pas celle d'une page valide.
     *
     * @throws ErreurMoteur
     */
    public function verifier(string $adresse): void
    {
        if (isset($this->verifiees[$adresse])) {
            return;
        }

        $validation = $this->valideur->valider($adresse);

        if (! $validation->valide) {
            throw new ErreurMoteur($validation->erreurs);
        }

        if ($validation->niveau !== ResultatValidation::NIVEAU_PAGE) {
            // Une adresse de livre est bien écrite mais ne désigne pas de page : même erreur que GET /pages/{adresse}.
            throw ErreurMoteur::une(CodeErreur::AdresseMalEcrite, 'page');
        }

        $this->verifiees[$adresse] = true;
    }

    /**
     * Le filtre « livre » d'une liste : l'adresse d'un LIVRE (sans « :<page> »), bien écrite et existante.
     * C'est un paramètre de liste, pas une page désignée : l'erreur est donc ERR_INVALID_PARAMETER, champ « livre ».
     */
    public function verifierLivre(string $livre): void
    {
        $validation = $this->valideur->valider($livre);

        if (! $validation->valide || $validation->niveau !== ResultatValidation::NIVEAU_LIVRE) {
            throw ErreurApi::une(422, 'ERR_INVALID_PARAMETER', "L'adresse du livre est mal écrite, ou n'existe pas (sans « :<page> »).", 'livre');
        }
    }

    /** La ligne ADRESSE de cette page, ou null si personne ne l'a encore enregistrée. */
    public function trouver(?string $identifiantBibliotheque, string $adresse): ?Adresse
    {
        $bibliotheque = $this->bibliotheque($identifiantBibliotheque);
        $this->verifier($adresse);

        return $this->chercher($bibliotheque, $adresse);
    }

    /** La ligne ADRESSE de cette page, créée au besoin (première fois qu'un membre la désigne). */
    public function trouverOuCreer(?string $identifiantBibliotheque, string $adresse): Adresse
    {
        $bibliotheque = $this->bibliotheque($identifiantBibliotheque);
        $this->verifier($adresse);

        return $this->chercher($bibliotheque, $adresse) ?? $this->creer($bibliotheque, $adresse);
    }

    private function chercher(Bibliotheque $bibliotheque, string $adresse): ?Adresse
    {
        // Même calcul que le modèle à l'enregistrement : l'adresse est déjà validée, donc sous sa forme unique.
        return Adresse::query()
            ->where('bibliotheque_id', $bibliotheque->id)
            ->where('empreinte', hash('sha256', $adresse))
            ->first()
            ?->setRelation('bibliotheque', $bibliotheque);
    }

    private function creer(Bibliotheque $bibliotheque, string $adresse): Adresse
    {
        // L'adresse est validée : la découper ne peut pas échouer.
        preg_match('/\A([0-9a-z]+)-w(\d+)-s(\d+)-v(\d+):(\d+)\z/', $adresse, $m);

        try {
            return Adresse::query()->create([
                'bibliotheque_id' => $bibliotheque->id,
                'hexagone' => $m[1],
                'mur' => (int) $m[2],
                'etagere' => (int) $m[3],
                'volume' => (int) $m[4],
                'page' => (int) $m[5],
            ])->setRelation('bibliotheque', $bibliotheque);
        } catch (UniqueConstraintViolationException) {
            // Deux requêtes ont enregistré la même page en même temps : la base a départagé, on reprend l'autre.
            return $this->chercher($bibliotheque, $adresse) ?? throw new LogicException('Adresse introuvable après un doublon.');
        }
    }
}
