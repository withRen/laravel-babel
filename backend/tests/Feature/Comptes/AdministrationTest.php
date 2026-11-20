<?php

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

// Une seule définition de « administrateur » : la Gate « administrer ». Filament et les routes la réutilisent.

it('laisse passer un administrateur actif, et seulement lui', function () {
    expect(Gate::forUser(User::factory()->admin()->create())->allows('administrer'))->toBeTrue();
    expect(Gate::forUser(User::factory()->create())->allows('administrer'))->toBeFalse();
});

it('retire les droits d\'un administrateur suspendu', function () {
    expect(Gate::forUser(User::factory()->admin()->suspendu()->create())->allows('administrer'))->toBeFalse();
});

it('ouvre le back-office Filament selon la même règle que la Gate', function () {
    $panneau = Filament::getPanel('admin');

    foreach ([
        [User::factory()->admin()->create(), true],
        [User::factory()->create(), false],
        [User::factory()->admin()->suspendu()->create(), false],
    ] as [$utilisateur, $attendu]) {
        expect($utilisateur->canAccessPanel($panneau))->toBe($attendu)
            ->and($utilisateur->canAccessPanel($panneau))->toBe(Gate::forUser($utilisateur)->allows('administrer'));
    }
});

it('refuse l\'entrée du back-office à un membre qui se connecte sur /admin', function () {
    $this->actingAs(User::factory()->create())->get('/admin')->assertForbidden();
});

it('laisse entrer un administrateur dans le back-office', function () {
    $this->actingAs(User::factory()->admin()->create())->get('/admin')->assertSuccessful();
});

it('protège une route par can:administrer (404 du contrat pour un membre, 401 sans jeton)', function () {
    Route::middleware(['api', 'auth:sanctum', 'can:administrer'])->get('/api/v1/app/_test/admin', fn () => response()->json(['ok' => true]));

    $membre = User::factory()->create();
    $admin = User::factory()->admin()->create();
    $jeton = fn (User $u) => $this->postJson('/api/v1/app/auth/connexion', ['email' => $u->email, 'mot_de_passe' => 'password'])->json('jeton');

    attendErreurContrat($this->getJson('/api/v1/app/_test/admin'), 401, 'ERR_UNAUTHENTICATED', null);

    app('auth')->forgetGuards();
    attendErreurContrat($this->withToken($jeton($membre))->getJson('/api/v1/app/_test/admin'), 404, 'ERR_NOT_FOUND', null);

    app('auth')->forgetGuards();
    $this->withToken($jeton($admin))->getJson('/api/v1/app/_test/admin')->assertOk();
});

// ---- Authentification optionnelle -------------------------------------------------------------

it('reconnaît le membre sur une route ouverte quand un jeton valide est envoyé, et le visiteur sinon', function () {
    Route::middleware(['api', 'auth.optionnelle'])->get('/api/v1/app/_test/ouverte', fn (Request $r) => response()->json(['pseudo' => $r->user()?->pseudo]));

    $membre = User::factory()->create(['pseudo' => 'ada']);
    $jeton = $this->postJson('/api/v1/app/auth/connexion', ['email' => $membre->email, 'mot_de_passe' => 'password'])->json('jeton');

    app('auth')->forgetGuards();
    $this->getJson('/api/v1/app/_test/ouverte')->assertOk()->assertJsonPath('pseudo', null);        // visiteur

    app('auth')->forgetGuards();
    $this->withToken($jeton)->getJson('/api/v1/app/_test/ouverte')->assertOk()->assertJsonPath('pseudo', 'ada'); // membre

    app('auth')->forgetGuards();
    $this->withToken('1|jeton-perime')->getJson('/api/v1/app/_test/ouverte')->assertOk()->assertJsonPath('pseudo', null); // jeton faux = visiteur, pas d'erreur
});

it('traite un membre suspendu comme un visiteur sur une route ouverte', function () {
    Route::middleware(['api', 'auth.optionnelle'])->get('/api/v1/app/_test/ouverte', fn (Request $r) => response()->json(['pseudo' => $r->user()?->pseudo]));

    $membre = User::factory()->create();
    $jeton = $this->postJson('/api/v1/app/auth/connexion', ['email' => $membre->email, 'mot_de_passe' => 'password'])->json('jeton');
    $membre->forceFill(['suspendu_le' => now()])->save();

    app('auth')->forgetGuards();
    $this->withToken($jeton)->getJson('/api/v1/app/_test/ouverte')->assertOk()->assertJsonPath('pseudo', null);
});
