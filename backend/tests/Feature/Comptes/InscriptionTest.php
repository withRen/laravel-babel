<?php

use App\Enums\Role;
use App\Models\Reglage;
use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;

/** Le corps d'une inscription valide, que chaque test peut surcharger. */
function inscription(array $surcharge = []): array
{
    return $surcharge + ['pseudo' => 'ada', 'email' => 'ada@babel.test', 'mot_de_passe' => 'un-bon-mot-de-passe'];
}

it('crée le compte et ouvre la session (201, schéma Session)', function () {
    $reponse = $this->postJson('/api/v1/app/auth/inscription', inscription());

    $reponse->assertCreated();
    expect(array_keys($reponse->json()))->toBe(['jeton', 'type', 'utilisateur']);
    expect($reponse->json('type'))->toBe('Bearer');
    expect($reponse->json('jeton'))->toBeString()->not->toBeEmpty();
    expect(array_keys($reponse->json('utilisateur')))->toBe(['id', 'pseudo', 'email', 'role', 'cree_le']);
    expect($reponse->json('utilisateur.id'))->toBeString();      // identifiants en chaîne
    expect($reponse->json('utilisateur.pseudo'))->toBe('ada');
    expect($reponse->json('utilisateur.role'))->toBe('membre');
    expect(strtotime($reponse->json('utilisateur.cree_le')))->not->toBeFalse();
});

it('donne un jeton qui fonctionne tout de suite', function () {
    $jeton = $this->postJson('/api/v1/app/auth/inscription', inscription())->json('jeton');

    $this->withToken($jeton)->getJson('/api/v1/app/moi')->assertOk()->assertJsonPath('pseudo', 'ada');
});

it('ne stocke jamais le mot de passe en clair et ne le renvoie jamais', function () {
    $reponse = $this->postJson('/api/v1/app/auth/inscription', inscription());

    $membre = User::firstWhere('email', 'ada@babel.test');
    expect($membre->password)->not->toBe('un-bon-mot-de-passe')->and(password_verify('un-bon-mot-de-passe', $membre->password))->toBeTrue();
    expect($reponse->getContent())->not->toContain('un-bon-mot-de-passe')->not->toContain($membre->password);
});

it('note la date de dernière connexion (la session est ouverte)', function () {
    $this->postJson('/api/v1/app/auth/inscription', inscription())->assertCreated();

    expect(User::firstWhere('email', 'ada@babel.test')->derniere_connexion_le)->not->toBeNull();
});

it('écrit l\'email en minuscules : Ada@Babel.test et ada@babel.test sont le même compte', function () {
    $this->postJson('/api/v1/app/auth/inscription', inscription(['email' => 'Ada@Babel.TEST']))
        ->assertCreated()->assertJsonPath('utilisateur.email', 'ada@babel.test');

    attendErreurContrat(
        $this->postJson('/api/v1/app/auth/inscription', inscription(['pseudo' => 'autre', 'email' => 'ada@BABEL.test'])),
        409, 'ERR_ALREADY_EXISTS', 'email',
    );
});

it('ne laisse jamais choisir son rôle à l\'inscription (pas d\'élévation de privilège)', function () {
    $this->postJson('/api/v1/app/auth/inscription', inscription(['role' => 'admin']))
        ->assertCreated()->assertJsonPath('utilisateur.role', 'membre');

    expect(User::firstWhere('email', 'ada@babel.test')->role)->toBe(Role::Membre);
});

it('renvoie toutes les erreurs de validation d\'un coup, une par champ (422)', function () {
    $reponse = $this->postJson('/api/v1/app/auth/inscription', ['pseudo' => str_repeat('a', 31), 'email' => 'pas-un-email', 'mot_de_passe' => 'court']);

    $reponse->assertStatus(422);
    expect($reponse->json('erreurs'))->toHaveCount(3);
    expect(collect($reponse->json('erreurs'))->pluck('code')->unique()->all())->toBe(['ERR_INVALID_PARAMETER']);
    expect(collect($reponse->json('erreurs'))->pluck('champ')->sort()->values()->all())->toBe(['email', 'mot_de_passe', 'pseudo']);
    expect(User::count())->toBe(0);
});

it('refuse un corps vide : les trois champs sont obligatoires', function () {
    $reponse = $this->postJson('/api/v1/app/auth/inscription', []);

    $reponse->assertStatus(422);
    expect(collect($reponse->json('erreurs'))->pluck('champ')->sort()->values()->all())->toBe(['email', 'mot_de_passe', 'pseudo']);
});

it('accepte exactement 8 caractères de mot de passe et un pseudo de 30', function () {
    $this->postJson('/api/v1/app/auth/inscription', inscription(['pseudo' => str_repeat('a', 30), 'mot_de_passe' => '12345678']))
        ->assertCreated();
});

it('ne retire pas les espaces d\'un mot de passe', function () {
    $this->postJson('/api/v1/app/auth/inscription', inscription(['mot_de_passe' => '  huit sp  ']))->assertCreated();

    $this->postJson('/api/v1/app/auth/connexion', ['email' => 'ada@babel.test', 'mot_de_passe' => '  huit sp  '])->assertOk();
    $this->postJson('/api/v1/app/auth/connexion', ['email' => 'ada@babel.test', 'mot_de_passe' => 'huit sp'])->assertUnauthorized();
});

it('répond 409 sur le champ email quand l\'email est déjà pris', function () {
    User::factory()->create(['email' => 'ada@babel.test']);

    attendErreurContrat(
        $this->postJson('/api/v1/app/auth/inscription', inscription()),
        409, 'ERR_ALREADY_EXISTS', 'email',
    );
});

it('répond 409 sur le champ pseudo quand le pseudo est déjà pris, sans tenir compte de la casse', function () {
    User::factory()->create(['pseudo' => 'Ada']);

    attendErreurContrat(
        $this->postJson('/api/v1/app/auth/inscription', inscription(['pseudo' => 'ADA'])),
        409, 'ERR_ALREADY_EXISTS', 'pseudo',
    );
});

it('refuse avec 403 ERR_REGISTRATION_CLOSED quand l\'administrateur a fermé les inscriptions', function () {
    Reglage::updateOrCreate(['cle' => 'inscriptions_ouvertes'], ['valeur' => '0']);

    attendErreurContrat($this->postJson('/api/v1/app/auth/inscription', inscription()), 403, 'ERR_REGISTRATION_CLOSED', null);
    expect(User::count())->toBe(0);

    // Fermé, c'est fermé : même un corps invalide reçoit 403, pas 422.
    attendErreurContrat($this->postJson('/api/v1/app/auth/inscription', []), 403, 'ERR_REGISTRATION_CLOSED', null);
});

it('laisse la connexion ouverte quand les inscriptions sont fermées', function () {
    User::factory()->create(['email' => 'ada@babel.test']);
    Reglage::updateOrCreate(['cle' => 'inscriptions_ouvertes'], ['valeur' => '0']);

    $this->postJson('/api/v1/app/auth/connexion', ['email' => 'ada@babel.test', 'mot_de_passe' => 'password'])->assertOk();
});

it('accepte les inscriptions quand le réglage les ouvre, ou quand il n\'existe pas encore', function () {
    $this->postJson('/api/v1/app/auth/inscription', inscription())->assertCreated();

    Reglage::updateOrCreate(['cle' => 'inscriptions_ouvertes'], ['valeur' => '1']);
    $this->postJson('/api/v1/app/auth/inscription', inscription(['pseudo' => 'bob', 'email' => 'bob@babel.test']))->assertCreated();
});

it('limite le nombre d\'inscriptions par minute (429 du contrat avec Retry-After)', function () {
    RateLimiter::clear('inscription');

    for ($i = 0; $i < 10; $i++) {
        $this->postJson('/api/v1/app/auth/inscription', [])->assertStatus(422);
    }

    $reponse = $this->postJson('/api/v1/app/auth/inscription', inscription());
    attendErreurContrat($reponse, 429, 'ERR_TOO_MANY_REQUESTS', null);
    expect($reponse->headers->get('Retry-After'))->not->toBeNull();
});
