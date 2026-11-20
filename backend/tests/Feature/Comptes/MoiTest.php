<?php

use App\Models\Annotation;
use App\Models\Collection;
use App\Models\LienCourt;
use App\Models\MarquePage;
use App\Models\Recherche;
use App\Models\Signalement;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;

/** Ouvre une vraie session (jeton Sanctum) pour un membre. */
function ouvrirSession(User $membre, string $mot = 'password'): string
{
    app('auth')->forgetGuards();

    return test()->postJson('/api/v1/app/auth/connexion', ['email' => $membre->email, 'mot_de_passe' => $mot])->json('jeton');
}

beforeEach(function () {
    $this->membre = User::factory()->create(['pseudo' => 'ada', 'email' => 'ada@babel.test']);
    $this->jeton = ouvrirSession($this->membre);
});

// ---- GET /moi ---------------------------------------------------------------------------------

it('rend le profil du membre connecté (schéma Utilisateur exact)', function () {
    $reponse = $this->withToken($this->jeton)->getJson('/api/v1/app/moi');

    $reponse->assertOk();
    expect(array_keys($reponse->json()))->toBe(['id', 'pseudo', 'email', 'role', 'cree_le']);
    expect($reponse->json('id'))->toBe((string) $this->membre->id);
    expect($reponse->json('role'))->toBe('membre');
});

it('indique le rôle admin pour un administrateur', function () {
    $admin = User::factory()->admin()->create();

    $this->withToken(ouvrirSession($admin))->getJson('/api/v1/app/moi')->assertOk()->assertJsonPath('role', 'admin');
});

it('répond 401 ERR_UNAUTHENTICATED sans jeton, avec un jeton faux ou avec un mauvais schéma', function () {
    attendErreurContrat($this->getJson('/api/v1/app/moi'), 401, 'ERR_UNAUTHENTICATED', null);
    attendErreurContrat($this->withToken('1|faux')->getJson('/api/v1/app/moi'), 401, 'ERR_UNAUTHENTICATED', null);
    attendErreurContrat($this->withHeader('Authorization', 'Basic '.base64_encode('ada:password'))->getJson('/api/v1/app/moi'), 401, 'ERR_UNAUTHENTICATED', null);
});

it('répond 401 en JSON du contrat même si le client n\'envoie pas « Accept: application/json »', function () {
    $reponse = $this->get('/api/v1/app/moi');

    attendErreurContrat($reponse, 401, 'ERR_UNAUTHENTICATED', null);
});

it('refuse un membre suspendu APRÈS sa connexion : son jeton est encore valide mais plus accepté (403)', function () {
    $this->membre->forceFill(['suspendu_le' => now()])->save();

    foreach (['getJson', 'patchJson', 'deleteJson'] as $methode) {
        app('auth')->forgetGuards();
        attendErreurContrat($this->withToken($this->jeton)->$methode('/api/v1/app/moi', ['pseudo' => 'x', 'mot_de_passe' => 'password']), 403, 'ERR_ACCOUNT_SUSPENDED', null);
    }
    expect(User::find($this->membre->id))->not->toBeNull();
});

it('accepte de nouveau un membre rétabli', function () {
    $this->membre->forceFill(['suspendu_le' => now()])->save();
    $this->membre->forceFill(['suspendu_le' => null])->save();

    $this->withToken($this->jeton)->getJson('/api/v1/app/moi')->assertOk();
});

// ---- PATCH /moi -------------------------------------------------------------------------------

it('modifie le pseudo seul, sans demander le mot de passe', function () {
    $reponse = $this->withToken($this->jeton)->patchJson('/api/v1/app/moi', ['pseudo' => 'ada-lovelace']);

    $reponse->assertOk()->assertJsonPath('pseudo', 'ada-lovelace')->assertJsonPath('email', 'ada@babel.test');
    expect($this->membre->fresh()->pseudo)->toBe('ada-lovelace');
});

it('ne change que les champs envoyés', function () {
    $ancien = $this->membre->password;

    $this->withToken($this->jeton)->patchJson('/api/v1/app/moi', ['pseudo' => 'nouveau'])->assertOk();

    expect($this->membre->fresh()->email)->toBe('ada@babel.test')->and($this->membre->fresh()->password)->toBe($ancien);
});

it('change l\'email avec le mot de passe actuel, en minuscules', function () {
    $this->withToken($this->jeton)->patchJson('/api/v1/app/moi', ['email' => 'Ada@Nouveau.test', 'mot_de_passe_actuel' => 'password'])
        ->assertOk()->assertJsonPath('email', 'ada@nouveau.test');

    $this->postJson('/api/v1/app/auth/connexion', ['email' => 'ada@nouveau.test', 'mot_de_passe' => 'password'])->assertOk();
});

it('exige le mot de passe actuel pour changer l\'email ou le mot de passe (422 sur mot_de_passe_actuel)', function () {
    foreach ([['email' => 'x@babel.test'], ['mot_de_passe' => 'nouveau-mot-de-passe']] as $corps) {
        $reponse = $this->withToken($this->jeton)->patchJson('/api/v1/app/moi', $corps);

        attendErreurContrat($reponse, 422, 'ERR_INVALID_PARAMETER', 'mot_de_passe_actuel');
    }
    expect($this->membre->fresh()->email)->toBe('ada@babel.test');
});

it('répond 401 ERR_INVALID_CREDENTIALS quand le mot de passe actuel est faux', function () {
    $reponse = $this->withToken($this->jeton)->patchJson('/api/v1/app/moi', ['email' => 'x@babel.test', 'mot_de_passe_actuel' => 'faux']);

    attendErreurContrat($reponse, 401, 'ERR_INVALID_CREDENTIALS', null);
    expect($this->membre->fresh()->email)->toBe('ada@babel.test');
});

it('change le mot de passe : l\'ancien ne marche plus, le nouveau si', function () {
    $this->withToken($this->jeton)->patchJson('/api/v1/app/moi', ['mot_de_passe' => 'nouveau-mot-de-passe', 'mot_de_passe_actuel' => 'password'])->assertOk();

    $this->postJson('/api/v1/app/auth/connexion', ['email' => 'ada@babel.test', 'mot_de_passe' => 'password'])->assertUnauthorized();
    $this->postJson('/api/v1/app/auth/connexion', ['email' => 'ada@babel.test', 'mot_de_passe' => 'nouveau-mot-de-passe'])->assertOk();
    expect(Hash::check('nouveau-mot-de-passe', $this->membre->fresh()->password))->toBeTrue();
});

it('ferme les AUTRES sessions quand le mot de passe change, et garde celle de l\'appel', function () {
    $autre = ouvrirSession($this->membre);

    app('auth')->forgetGuards();
    $this->withToken($this->jeton)->patchJson('/api/v1/app/moi', ['mot_de_passe' => 'nouveau-mot-de-passe', 'mot_de_passe_actuel' => 'password'])->assertOk();

    expect($this->membre->tokens()->count())->toBe(1);
    app('auth')->forgetGuards();
    $this->withToken($this->jeton)->getJson('/api/v1/app/moi')->assertOk();
    app('auth')->forgetGuards();
    $this->withToken($autre)->getJson('/api/v1/app/moi')->assertUnauthorized();
});

it('répond 409 quand le nouvel email ou le nouveau pseudo appartient à un autre membre', function () {
    User::factory()->create(['pseudo' => 'bob', 'email' => 'bob@babel.test']);

    attendErreurContrat(
        $this->withToken($this->jeton)->patchJson('/api/v1/app/moi', ['email' => 'bob@babel.test', 'mot_de_passe_actuel' => 'password']),
        409, 'ERR_ALREADY_EXISTS', 'email',
    );
    attendErreurContrat(
        $this->withToken($this->jeton)->patchJson('/api/v1/app/moi', ['pseudo' => 'Bob']),
        409, 'ERR_ALREADY_EXISTS', 'pseudo',
    );
});

it('n\'est pas son propre doublon : renvoyer son email ou son pseudo actuel est accepté', function () {
    $this->withToken($this->jeton)->patchJson('/api/v1/app/moi', ['pseudo' => 'ada', 'email' => 'ada@babel.test', 'mot_de_passe_actuel' => 'password'])->assertOk();
});

it('refuse un corps sans aucun champ à modifier (422)', function () {
    attendErreurContrat($this->withToken($this->jeton)->patchJson('/api/v1/app/moi', []), 422, 'ERR_INVALID_PARAMETER', null);
});

it('valide les champs modifiés (422, une erreur par champ)', function () {
    $reponse = $this->withToken($this->jeton)->patchJson('/api/v1/app/moi', [
        'pseudo' => str_repeat('a', 31), 'email' => 'pas-un-email', 'mot_de_passe' => 'court', 'mot_de_passe_actuel' => 'password',
    ]);

    $reponse->assertStatus(422);
    expect(collect($reponse->json('erreurs'))->pluck('champ')->sort()->values()->all())->toBe(['email', 'mot_de_passe', 'pseudo']);
});

it('ne laisse pas un membre se promouvoir administrateur ni toucher à sa suspension', function () {
    $this->withToken($this->jeton)->patchJson('/api/v1/app/moi', ['pseudo' => 'ada2', 'role' => 'admin', 'suspendu_le' => null, 'id' => 999])->assertOk()
        ->assertJsonPath('role', 'membre');

    expect($this->membre->fresh()->role->value)->toBe('membre')->and($this->membre->fresh()->id)->toBe($this->membre->id);
});

// ---- DELETE /moi ------------------------------------------------------------------------------

it('supprime le compte et toutes ses données (204, RGPD), avec le mot de passe dans le corps', function () {
    $id = $this->membre->id;
    MarquePage::factory()->create(['utilisateur_id' => $id]);
    Collection::factory()->create(['utilisateur_id' => $id]);
    Annotation::factory()->create(['utilisateur_id' => $id]);
    LienCourt::factory()->create(['utilisateur_id' => $id]);
    Recherche::factory()->create(['utilisateur_id' => $id]);
    $autre = User::factory()->create();
    MarquePage::factory()->create(['utilisateur_id' => $autre->id]); // les données d'un autre membre ne bougent pas

    $this->withToken($this->jeton)->deleteJson('/api/v1/app/moi', ['mot_de_passe' => 'password'])->assertNoContent();

    expect(User::find($id))->toBeNull();
    foreach ([MarquePage::class, Collection::class, Annotation::class, LienCourt::class, Recherche::class] as $modele) {
        expect($modele::where('utilisateur_id', $id)->count())->toBe(0);
    }
    expect(MarquePage::where('utilisateur_id', $autre->id)->count())->toBe(1);
    expect(User::find($autre->id))->not->toBeNull();
});

it('garde les signalements du membre supprimé, sans auteur', function () {
    $signalement = Signalement::factory()->create(['auteur_id' => $this->membre->id]);

    $this->withToken($this->jeton)->deleteJson('/api/v1/app/moi', ['mot_de_passe' => 'password'])->assertNoContent();

    expect($signalement->fresh())->not->toBeNull()->and($signalement->fresh()->auteur_id)->toBeNull();
});

it('révoque toutes les sessions du compte supprimé', function () {
    $autre = ouvrirSession($this->membre);

    $this->withToken($this->jeton)->deleteJson('/api/v1/app/moi', ['mot_de_passe' => 'password'])->assertNoContent();

    expect(PersonalAccessToken::count())->toBe(0);
    app('auth')->forgetGuards();
    $this->withToken($autre)->getJson('/api/v1/app/moi')->assertUnauthorized();
    $this->postJson('/api/v1/app/auth/connexion', ['email' => 'ada@babel.test', 'mot_de_passe' => 'password'])->assertUnauthorized();
});

it('ne supprime rien avec un mot de passe faux (401) ou absent (422)', function () {
    attendErreurContrat($this->withToken($this->jeton)->deleteJson('/api/v1/app/moi', ['mot_de_passe' => 'faux']), 401, 'ERR_INVALID_CREDENTIALS', null);
    attendErreurContrat($this->withToken($this->jeton)->deleteJson('/api/v1/app/moi', []), 422, 'ERR_INVALID_PARAMETER', 'mot_de_passe');

    expect(User::find($this->membre->id))->not->toBeNull();
});

it('exige un jeton pour supprimer (401)', function () {
    attendErreurContrat($this->deleteJson('/api/v1/app/moi', ['mot_de_passe' => 'password']), 401, 'ERR_UNAUTHENTICATED', null);
});
