<?php

namespace App\Services\Compte;

use App\Exceptions\ErreurApi;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * La logique des comptes de membres (V19, M1, M9, M10). Les contrôleurs restent minces :
 * ils reçoivent une requête déjà validée (FormRequest), appellent ce service, et rendent une Resource.
 */
class ServiceCompte
{
    /** Nom du jeton Sanctum créé à chaque connexion (colonne `name` de personal_access_tokens). */
    public const NOM_JETON = 'application-web';

    /** Hash jetable, calculé une fois : sert à « perdre le même temps » quand l'email est inconnu. */
    private static ?string $hashFactice = null;

    /** V19 : crée le compte (rôle « membre » : on ne devient jamais admin en s'inscrivant) et ouvre la session. */
    public function inscrire(string $pseudo, string $email, string $motDePasse): Session
    {
        $this->refuserDoublon($pseudo, $email);

        try {
            $membre = User::create(['pseudo' => $pseudo, 'email' => $email, 'password' => $motDePasse]);
            $membre->refresh(); // relit les valeurs par défaut posées par la base (role = « membre »)
        } catch (UniqueConstraintViolationException) {
            // Deux inscriptions simultanées ont passé le contrôle ci-dessus : la contrainte UNIQUE de la base tranche.
            $this->refuserDoublon($pseudo, $email);
            throw ErreurApi::dejaExistant('email', 'Un compte utilise déjà cet email.');
        }

        return $this->ouvrirSession($membre);
    }

    /**
     * M1 : vérifie les identifiants, refuse un compte suspendu, ouvre la session.
     *
     * Email inconnu et mot de passe faux donnent la MÊME réponse 401 et prennent le même temps (un hash est
     * toujours comparé) : on ne laisse pas deviner quels emails ont un compte. Le test « suspendu » vient APRÈS
     * le mot de passe : seul quelqu'un qui connaît le mot de passe apprend que le compte est suspendu.
     */
    public function connecter(string $email, string $motDePasse): Session
    {
        $membre = User::where('email', $email)->first();

        $valide = Hash::check($motDePasse, $membre?->password ?? $this->hashFactice());

        if ($membre === null || ! $valide) {
            throw ErreurApi::identifiantsInvalides();
        }

        if ($membre->estSuspendu()) {
            throw ErreurApi::compteSuspendu();
        }

        return $this->ouvrirSession($membre);
    }

    /** M9 : modifie les champs envoyés. Email et mot de passe demandent le mot de passe actuel. */
    public function modifier(User $membre, array $donnees): User
    {
        if ((isset($donnees['email']) || isset($donnees['mot_de_passe']))
            && ! Hash::check($donnees['mot_de_passe_actuel'], $membre->password)) {
            throw ErreurApi::identifiantsInvalides();
        }

        $this->refuserDoublon($donnees['pseudo'] ?? null, $donnees['email'] ?? null, sauf: $membre);

        $membre->fill(Arr::only($donnees, ['pseudo', 'email']));
        if (isset($donnees['mot_de_passe'])) {
            $membre->password = $donnees['mot_de_passe']; // le cast « hashed » le hache
        }

        try {
            $membre->save();
        } catch (UniqueConstraintViolationException) {
            $this->refuserDoublon($donnees['pseudo'] ?? null, $donnees['email'] ?? null, sauf: $membre);
            throw ErreurApi::dejaExistant('email', 'Un compte utilise déjà cet email.');
        }

        // Mot de passe changé : toutes les AUTRES sessions sont fermées (un jeton volé ne survit pas au changement).
        if (isset($donnees['mot_de_passe'])) {
            $courant = $membre->currentAccessToken();
            $membre->tokens()
                ->when($courant instanceof PersonalAccessToken, fn ($q) => $q->whereKeyNot($courant->getKey()))
                ->delete();
        }

        return $membre;
    }

    /**
     * M10 (RGPD, règle 19) : supprime le compte. Les marque-pages, collections, annotations, votes, recherches et
     * liens courts partent avec lui (clés étrangères en cascade) ; ses signalements restent, sans auteur
     * (nullOnDelete). Tous ses jetons sont révoqués.
     */
    public function supprimer(User $membre, string $motDePasse): void
    {
        if (! Hash::check($motDePasse, $membre->password)) {
            throw ErreurApi::identifiantsInvalides();
        }

        DB::transaction(function () use ($membre): void {
            $membre->tokens()->delete();
            $membre->delete();
        });
    }

    /** Une session = un jeton Sanctum + la date de dernière connexion (utilisée par le back-office, A1). */
    private function ouvrirSession(User $membre): Session
    {
        $membre->forceFill(['derniere_connexion_le' => now()])->save();

        return new Session($membre, $membre->createToken(self::NOM_JETON)->plainTextToken);
    }

    /** 409 ERR_ALREADY_EXISTS, avec le champ en cause. Le pseudo se compare sans tenir compte de la casse (« Admin » ≠ « admin » prêterait à confusion). */
    private function refuserDoublon(?string $pseudo, ?string $email, ?User $sauf = null): void
    {
        if ($email !== null
            && User::where('email', $email)->when($sauf, fn ($q) => $q->whereKeyNot($sauf->getKey()))->exists()) {
            throw ErreurApi::dejaExistant('email', 'Un compte utilise déjà cet email.');
        }

        if ($pseudo !== null
            && User::whereRaw('lower(pseudo) = ?', [mb_strtolower($pseudo)])
                ->when($sauf, fn ($q) => $q->whereKeyNot($sauf->getKey()))->exists()) {
            throw ErreurApi::dejaExistant('pseudo', 'Ce pseudo est déjà pris.');
        }
    }

    private function hashFactice(): string
    {
        return self::$hashFactice ??= Hash::make('mot-de-passe-factice');
    }
}
