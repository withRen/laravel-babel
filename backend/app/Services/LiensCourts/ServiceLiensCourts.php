<?php

declare(strict_types=1);

namespace App\Services\LiensCourts;

use App\Exceptions\ErreurApi;
use App\Models\Collection;
use App\Models\LienCourt;
use App\Models\Reglage;
use App\Models\User;
use App\Services\Engine\Valideur;
use App\Services\Lecture\RegistreAdresses;
use App\Services\Lecture\ServiceLecture;
use App\Services\Moderation\TextesBannisAppliques;
use App\Services\Reseau\CollectionsPubliques;
use Babel\Moteur\Erreur\ErreurMoteur;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

/**
 * Créer et ouvrir des liens courts (V11, V12).
 *
 * Un lien court a exactement UNE cible : une adresse (page ou livre entier) ou une collection (règle 11).
 * Ouvert aux visiteurs : sans compte, il expire obligatoirement (la base le vérifie aussi, par un CHECK).
 */
final class ServiceLiensCourts
{
    /** Durée maximale d'un lien de visiteur, si l'administrateur n'a pas réglé `duree_lien_visiteur_jours`. */
    public const DUREE_VISITEUR_PAR_DEFAUT = 30;

    public function __construct(
        private readonly ServiceLecture $lecture,
        private readonly Valideur $valideur,
        private readonly RegistreAdresses $registre,
        private readonly TextesBannisAppliques $textesBannis,
        private readonly CollectionsPubliques $collections,
    ) {}

    public function dureeMaxVisiteur(): int
    {
        $jours = (int) Reglage::valeur('duree_lien_visiteur_jours', (string) self::DUREE_VISITEUR_PAR_DEFAUT);

        return $jours >= 1 ? $jours : self::DUREE_VISITEUR_PAR_DEFAUT;
    }

    /**
     * @param  array<string, mixed>  $cible  {type: adresse, bibliotheque?, adresse} ou {type: collection, id}
     */
    public function creer(array $cible, ?string $passage, ?CarbonImmutable $expireLe, ?User $membre): LienCourt
    {
        $expiration = $this->expiration($expireLe, $membre);

        if ($cible['type'] === 'collection') {
            if ($passage !== null) {
                throw ErreurApi::une(422, 'ERR_INVALID_PARAMETER', 'Un passage ne va qu\'avec une page.', 'passage');
            }
            $collection = $this->collectionVisible((string) $cible['id'], $membre);

            return $this->enregistrer(['collection_id' => $collection->id, 'adresse_id' => null], null, $expiration, $membre);
        }

        $bibliotheque = $this->lecture->bibliotheque($cible['bibliotheque'] ?? null);
        $adresse = (string) $cible['adresse'];

        // F5 : l'adresse est contrôlée avant tout (et avant tout appel à un pair). Page ou livre, les deux sont permis.
        $validation = $this->valideur->valider($adresse);
        if (! $validation->valide) {
            throw new ErreurMoteur($validation->erreurs);
        }
        $estUnePage = $validation->niveau === 'page';

        if ($passage !== null && ! $estUnePage) {
            throw ErreurApi::une(422, 'ERR_INVALID_PARAMETER', 'Un passage ne va qu\'avec une page, pas avec un livre entier.', 'passage');
        }

        // Le texte de la page n'est lu que s'il sert : vérifier le passage, ou chercher un texte banni.
        $banniesPossibles = $this->textesBannis->existent();
        $texte = null;
        if ($estUnePage && ($passage !== null || $banniesPossibles)) {
            try {
                $texte = $this->lecture->texte($bibliotheque, $adresse);
            } catch (ErreurApi $e) {
                // Sans passage à vérifier, une page d'ailleurs qui ne répond pas n'empêche pas le lien : elle
                // sera revérifiée plus tard (tâche de fond, hors de ce périmètre). Avec un passage, on ne peut pas conclure.
                if ($passage !== null || $bibliotheque->est_locale) {
                    throw $e;
                }
            }
        }

        if ($passage !== null && ! str_contains((string) $texte, $passage)) {
            // « repris tel quel, espaces comprises » : on ne devine pas, on ne corrige pas.
            throw ErreurApi::une(422, 'ERR_INVALID_PARAMETER', 'Ce passage ne figure pas dans la page.', 'passage');
        }

        $ligne = $this->registre->enregistrer($bibliotheque, $adresse);

        // Règle 18, point 3 : à la publication, on cherche les textes bannis appliqués. Le lien est créé quand même
        // (il ne s'ouvrira pas pour les autres) : on ne dit pas à celui qui publie qu'il est tombé sur un texte banni.
        if ($banniesPossibles) {
            if ($estUnePage && $texte !== null) {
                $this->textesBannis->verifierPage($ligne, $texte);
            } elseif (! $estUnePage && $bibliotheque->est_locale) {
                $this->textesBannis->verifierLivreLocal($ligne);
            }
        }

        return $this->enregistrer(['adresse_id' => $ligne->id, 'collection_id' => null], $passage, $expiration, $membre);
    }

    /**
     * Ouvre un lien (V12) et compte la visite. 404 si inconnu, masqué, ou si sa cible n'est plus montrée ;
     * 410 s'il a expiré. Les cas « 404 » donnent tous la même réponse : on ne laisse rien deviner.
     *
     * @return array{lien: LienCourt, collection: Collection|null}
     */
    public function ouvrir(string $slug): array
    {
        $introuvable = fn () => ErreurApi::nonTrouve("Ce lien n'existe pas ou n'est pas visible.", 'slug');

        // Un slug mal formé n'existe pas : 404 comme un slug inconnu (le contrat ne prévoit pas de 422 ici).
        if (preg_match('/^[A-Za-z0-9_-]{4,32}$/', $slug) !== 1) {
            throw $introuvable();
        }

        $lien = LienCourt::query()->with(['adresse.bibliotheque'])->where('slug', $slug)->first();
        if ($lien === null || $lien->masque_le !== null) {
            throw $introuvable();
        }

        $collection = null;
        if ($lien->collection_id !== null) {
            // Collection publique et non masquée, pages filtrées comme sur GET /collections/{id}.
            $collection = $this->collections->trouver((string) $lien->collection_id) ?? throw $introuvable();
        } elseif ($lien->adresse->texte_banni_id !== null) {
            throw $introuvable();
        }

        if ($lien->estExpire()) {
            throw ErreurApi::une(410, 'ERR_SHORT_LINK_EXPIRED', 'Ce lien a expiré.', 'slug');
        }

        // Compteur atomique (calculé dans la base, pas lu puis réécrit en PHP).
        LienCourt::query()->whereKey($lien->id)->increment('visites');
        $lien->visites++;

        return ['lien' => $lien, 'collection' => $collection];
    }

    /**
     * Sans compte : durée maximale obligatoire. Avec compte : pas d'expiration par défaut.
     * Une date passée est refusée dans les deux cas.
     */
    private function expiration(?CarbonImmutable $demandee, ?User $membre): ?CarbonImmutable
    {
        if ($demandee !== null && $demandee->isPast()) {
            throw ErreurApi::une(422, 'ERR_INVALID_PARAMETER', "La date d'expiration doit être dans le futur.", 'expire_le');
        }

        if ($membre !== null) {
            return $demandee;
        }

        $max = CarbonImmutable::now()->addDays($this->dureeMaxVisiteur());
        if ($demandee !== null && $demandee->greaterThan($max)) {
            throw ErreurApi::une(
                422, 'ERR_INVALID_PARAMETER',
                "Sans compte, un lien dure {$this->dureeMaxVisiteur()} jours au plus.", 'expire_le',
            );
        }

        return $demandee ?? $max;
    }

    /** Une collection publique non masquée, ou une collection du membre. Sinon 404 (jamais 403). */
    private function collectionVisible(string $id, ?User $membre): Collection
    {
        // « 12abc » n'est pas 12 : un id n'est qu'une suite de chiffres (et tient dans un entier 64 bits).
        $collection = ctype_digit($id) && strlen($id) <= 18 ? Collection::query()->find((int) $id) : null;

        $visible = $collection !== null && (
            ($collection->est_publique && $collection->masque_le === null)
            || ($membre !== null && $collection->utilisateur_id === $membre->id)
        );

        return $visible ? $collection : throw ErreurApi::nonTrouve("Cette collection n'existe pas ou n'est pas visible.", 'cible');
    }

    /** @param  array{adresse_id: int|null, collection_id: int|null}  $cible */
    private function enregistrer(array $cible, ?string $passage, ?CarbonImmutable $expiration, ?User $membre): LienCourt
    {
        // Le slug est tiré au hasard par le serveur (8 caractères) ; en cas de collision, la contrainte UNIQUE de la
        // base refuse et on retire un autre slug.
        for ($essai = 0; $essai < 5; $essai++) {
            try {
                $lien = LienCourt::query()->create($cible + [
                    'slug' => Str::random(8),
                    'utilisateur_id' => $membre?->id,
                    'passage' => $passage,
                    'visites' => 0,
                    'expire_le' => $expiration,
                ]);

                return $lien->load('adresse.bibliotheque');
            } catch (UniqueConstraintViolationException) {
                continue;
            }
        }

        throw new \RuntimeException('Impossible de trouver un slug libre.');
    }
}
