<?php

use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function () {
    $this->membre = User::factory()->create(['pseudo' => 'ada', 'email' => 'ada@babel.test']); // mot de passe : « password »
});

function connexion(string $email = 'ada@babel.test', string $mot = 'password'): array
{
    return ['email' => $email, 'mot_de_passe' => $mot];
}

it('ouvre une session (200, schéma Session)', function () {
    $reponse = $this->postJson('/api/v1/app/auth/connexion', connexion());

    $reponse->assertOk();
    expect(array_keys($reponse->json()))->toBe(['jeton', 'type', 'utilisateur']);
    expect($reponse->json('type'))->toBe('Bearer');
    expect($reponse->json('utilisateur.id'))->toBe((string) $this->membre->id);
    expect($reponse->json('utilisateur.email'))->toBe('ada@babel.test');
    expect($reponse->getContent())->not->toContain('password')->not->toContain('suspendu_le');
});

it('donne un jeton valable sur les routes membre', function () {
    $jeton = $this->postJson('/api/v1/app/auth/connexion', connexion())->json('jeton');

    $this->withToken($jeton)->getJson('/api/v1/app/moi')->assertOk()->assertJsonPath('pseudo', 'ada');
});

it('crée un jeton par connexion : plusieurs appareils, plusieurs sessions', function () {
    $a = $this->postJson('/api/v1/app/auth/connexion', connexion())->json('jeton');
    $b = $this->postJson('/api/v1/app/auth/connexion', connexion())->json('jeton');

    expect($a)->not->toBe($b);
    expect($this->membre->tokens()->count())->toBe(2);
});

it('met à jour la date de dernière connexion', function () {
    expect($this->membre->derniere_connexion_le)->toBeNull();

    $this->travelTo(now()->addDay());
    $this->postJson('/api/v1/app/auth/connexion', connexion())->assertOk();

    expect($this->membre->fresh()->derniere_connexion_le->isSameDay(now()))->toBeTrue();
});

it('ne tient pas compte de la casse de l\'email', function () {
    $this->postJson('/api/v1/app/auth/connexion', connexion('ADA@Babel.test'))->assertOk();
});

it('donne la même réponse vague pour un email inconnu et un mot de passe faux (401)', function () {
    $inconnu = $this->postJson('/api/v1/app/auth/connexion', connexion('personne@babel.test'));
    $faux = $this->postJson('/api/v1/app/auth/connexion', connexion('ada@babel.test', 'pas-le-bon'));

    attendErreurContrat($inconnu, 401, 'ERR_INVALID_CREDENTIALS', null);
    attendErreurContrat($faux, 401, 'ERR_INVALID_CREDENTIALS', null);
    expect($inconnu->json())->toBe($faux->json()); // identiques au caractère près : rien à deviner
    expect($this->membre->tokens()->count())->toBe(0);
    expect($this->membre->fresh()->derniere_connexion_le)->toBeNull();
});

it('refuse un compte suspendu (403) quand le mot de passe est bon', function () {
    $this->membre->forceFill(['suspendu_le' => now()])->save();

    attendErreurContrat($this->postJson('/api/v1/app/auth/connexion', connexion()), 403, 'ERR_ACCOUNT_SUSPENDED', null);
    expect($this->membre->tokens()->count())->toBe(0);
});

it('ne révèle pas qu\'un compte est suspendu à qui n\'a pas le mot de passe', function () {
    $this->membre->forceFill(['suspendu_le' => now()])->save();

    attendErreurContrat($this->postJson('/api/v1/app/auth/connexion', connexion('ada@babel.test', 'faux')), 401, 'ERR_INVALID_CREDENTIALS', null);
});

it('réclame l\'email et le mot de passe (422)', function () {
    $reponse = $this->postJson('/api/v1/app/auth/connexion', []);

    $reponse->assertStatus(422);
    expect(collect($reponse->json('erreurs'))->pluck('champ')->sort()->values()->all())->toBe(['email', 'mot_de_passe']);
    expect(collect($reponse->json('erreurs'))->pluck('code')->unique()->all())->toBe(['ERR_INVALID_PARAMETER']);
});

it('bloque après 5 essais par minute pour un même email (429 du contrat)', function () {
    RateLimiter::clear('connexion');

    for ($i = 0; $i < 5; $i++) {
        $this->postJson('/api/v1/app/auth/connexion', connexion('ada@babel.test', "faux-$i"))->assertUnauthorized();
    }

    // Le 6e essai est refusé, même avec le BON mot de passe : sinon la limite ne servirait à rien.
    $reponse = $this->postJson('/api/v1/app/auth/connexion', connexion());
    attendErreurContrat($reponse, 429, 'ERR_TOO_MANY_REQUESTS', null);
    expect((int) $reponse->headers->get('Retry-After'))->toBeGreaterThan(0);

    // Un autre email, lui, n'est pas pénalisé.
    $this->postJson('/api/v1/app/auth/connexion', connexion('autre@babel.test', 'x'))->assertUnauthorized();
});
