<?php

use App\Models\User;

function sessionDe(User $membre): string
{
    return test()->postJson('/api/v1/app/auth/connexion', ['email' => $membre->email, 'mot_de_passe' => 'password'])->json('jeton');
}

it('révoque le jeton utilisé (204) : il ne sert plus à rien', function () {
    $membre = User::factory()->create();
    $jeton = sessionDe($membre);

    $this->withToken($jeton)->postJson('/api/v1/app/auth/deconnexion')->assertNoContent();

    expect($membre->tokens()->count())->toBe(0);
    app('auth')->forgetGuards(); // le garde garde le membre en mémoire d'une requête à l'autre dans un même test
    attendErreurContrat($this->withToken($jeton)->getJson('/api/v1/app/moi'), 401, 'ERR_UNAUTHENTICATED', null);
});

it('ne ferme que la session de cet appel : l\'autre appareil reste connecté', function () {
    $membre = User::factory()->create();
    $telephone = sessionDe($membre);
    $ordinateur = sessionDe($membre);

    $this->withToken($telephone)->postJson('/api/v1/app/auth/deconnexion')->assertNoContent();

    expect($membre->tokens()->count())->toBe(1);
    app('auth')->forgetGuards();
    $this->withToken($ordinateur)->getJson('/api/v1/app/moi')->assertOk();
});

it('répond 401 sans jeton', function () {
    attendErreurContrat($this->postJson('/api/v1/app/auth/deconnexion'), 401, 'ERR_UNAUTHENTICATED', null);
});

it('répond 401 avec un jeton inventé', function () {
    attendErreurContrat($this->withToken('1|nimportequoi')->postJson('/api/v1/app/auth/deconnexion'), 401, 'ERR_UNAUTHENTICATED', null);
});

it('laisse un membre suspendu fermer sa session', function () {
    $membre = User::factory()->create();
    $jeton = sessionDe($membre);
    $membre->forceFill(['suspendu_le' => now()])->save();

    $this->withToken($jeton)->postJson('/api/v1/app/auth/deconnexion')->assertNoContent();
    expect($membre->tokens()->count())->toBe(0);
});
