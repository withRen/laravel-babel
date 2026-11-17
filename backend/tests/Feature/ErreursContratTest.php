<?php

use App\Exceptions\ErreurApi;
use Babel\Moteur\Erreur\CleInvalide;
use Babel\Moteur\Erreur\CodeErreur;
use Babel\Moteur\Erreur\Erreur;
use Babel\Moteur\Erreur\ErreurMoteur;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
 * Ces tests vérifient la FORME des erreurs du contrat (docs/openapi.yaml, « La forme des
 * erreurs ») : {"erreurs": [{"code", "champ", "message"}]} et le bon statut HTTP.
 * Les routes métier n'existent pas encore : on branche des routes d'essai sous /api/v1/essai
 * avec le même groupe middleware « api » que les vraies routes.
 */
beforeEach(function () {
    Route::middleware('api')->prefix('api/v1/essai')->group(function () {
        Route::get('moteur-hors-limites', fn () => throw new ErreurMoteur([
            new Erreur(CodeErreur::HorsLimites, 'mur'),
            new Erreur(CodeErreur::HorsLimites, 'page'),
        ]));
        Route::get('moteur-forme-et-limites', fn () => throw new ErreurMoteur([
            new Erreur(CodeErreur::AdresseMalEcrite, 'hexagone'),
            new Erreur(CodeErreur::HorsLimites, 'mur'),
        ]));
        Route::get('moteur-recherche-vide', fn () => throw new ErreurMoteur([
            new Erreur(CodeErreur::RechercheVide, 'texte'),
        ]));
        Route::get('cle-invalide', fn () => throw CleInvalide::absente());
        Route::post('validation', fn (Request $r) => $r->validate([
            'email' => ['required', 'email'],
            'mot_de_passe' => ['required', 'min:8'],
            'adresses' => ['array', 'max:2'],
            'adresses.*' => ['string'],
        ]));
        Route::post('echo', fn (Request $r) => $r->only(['texte', 'mot_de_passe', 'pseudo']));
        Route::get('membre', fn () => 'ok')->middleware('auth:sanctum');
        Route::get('modele-absent', fn () => throw new ModelNotFoundException);
        Route::get('doublon', fn () => throw ErreurApi::une(409, 'ERR_ALREADY_EXISTS', 'Cet email est déjà pris.', 'email'));
        Route::get('reseau', fn () => 'ok')->middleware('throttle:reseau');
    });
});

/** Vérifie la forme exacte d'une erreur : trois clés, dans l'ordre du contrat. */
function attendErreur($reponse, int $statut, string $code, ?string $champ): void
{
    $reponse->assertStatus($statut);
    $corps = $reponse->json();
    expect(array_keys($corps))->toBe(['erreurs']);
    expect($corps['erreurs'][0])->toHaveKeys(['code', 'champ', 'message']);
    expect($corps['erreurs'][0]['code'])->toBe($code);
    expect($corps['erreurs'][0]['champ'])->toBe($champ);
    expect($corps['erreurs'][0]['message'])->toBeString()->not->toBeEmpty();
}

it('répond 404 quand le moteur ne renvoie que des ERR_OUT_OF_BOUNDS', function () {
    $r = $this->getJson('/api/v1/essai/moteur-hors-limites');

    attendErreur($r, 404, 'ERR_OUT_OF_BOUNDS', 'mur');
    expect($r->json('erreurs'))->toHaveCount(2);
});

it('répond 422 dès qu\'une erreur de forme se mêle aux ERR_OUT_OF_BOUNDS', function () {
    $r = $this->getJson('/api/v1/essai/moteur-forme-et-limites');

    attendErreur($r, 422, 'ERR_INVALID_ADDRESS_SYNTAX', 'hexagone');
    expect($r->json('erreurs.1.code'))->toBe('ERR_OUT_OF_BOUNDS');
});

it('répond 422 pour une recherche refusée par le moteur', function () {
    attendErreur($this->getJson('/api/v1/essai/moteur-recherche-vide'), 422, 'ERR_EMPTY_QUERY', 'texte');
});

it('laisse la CleInvalide du moteur en 500 : c\'est une erreur d\'installation', function () {
    $this->getJson('/api/v1/essai/cle-invalide')->assertStatus(500);
});

it('traduit la validation en ERR_INVALID_PARAMETER, une erreur par champ', function () {
    $r = $this->postJson('/api/v1/essai/validation', [
        'email' => 'pas-un-email',
        'mot_de_passe' => 'court',
        'adresses' => ['a', 'b', 'c'],
    ]);

    $r->assertStatus(422);
    expect(array_keys($r->json()))->toBe(['erreurs']);
    expect(collect($r->json('erreurs'))->pluck('code')->unique()->all())->toBe(['ERR_INVALID_PARAMETER']);
    expect(collect($r->json('erreurs'))->pluck('champ')->sort()->values()->all())
        ->toBe(['adresses', 'email', 'mot_de_passe']);
});

it('refuse sans jeton de session avec 401 ERR_UNAUTHENTICATED', function () {
    attendErreur($this->getJson('/api/v1/essai/membre'), 401, 'ERR_UNAUTHENTICATED', null);
});

it('répond 404 ERR_NOT_FOUND pour une route inconnue, même sans Accept: application/json', function () {
    attendErreur($this->get('/api/v1/app/inconnue'), 404, 'ERR_NOT_FOUND', null);
});

it('répond 404 ERR_NOT_FOUND pour un modèle introuvable', function () {
    attendErreur($this->getJson('/api/v1/essai/modele-absent'), 404, 'ERR_NOT_FOUND', null);
});

it('sait lancer une erreur du contrat depuis un contrôleur (ErreurApi)', function () {
    attendErreur($this->getJson('/api/v1/essai/doublon'), 409, 'ERR_ALREADY_EXISTS', 'email');
});

it('répond 429 ERR_TOO_MANY_REQUESTS avec Retry-After au-delà de 60 requêtes par minute', function () {
    foreach (range(1, 60) as $i) {
        $this->getJson('/api/v1/essai/reseau')->assertOk();
    }

    $r = $this->getJson('/api/v1/essai/reseau');

    attendErreur($r, 429, 'ERR_TOO_MANY_REQUESTS', null);
    expect((int) $r->headers->get('Retry-After'))->toBeGreaterThan(0);
});

it('ne retire pas les espaces des champs texte et mot_de_passe (TrimStrings)', function () {
    $r = $this->postJson('/api/v1/essai/echo', [
        'texte' => ' abc ',
        'mot_de_passe' => ' secret ',
        'pseudo' => ' Rahim ',
    ]);

    expect($r->json('texte'))->toBe(' abc ');
    expect($r->json('mot_de_passe'))->toBe(' secret ');
    expect($r->json('pseudo'))->toBe('Rahim'); // les autres champs restent nettoyés
});

it('accepte un POST sans cookie CSRF : le contrat utilise des jetons Bearer, pas des cookies', function () {
    $this->postJson('/api/v1/essai/echo', ['pseudo' => 'x'])->assertOk();
});

it('autorise l\'application Angular (CORS) et expose Retry-After', function () {
    $r = $this->withHeaders([
        'Origin' => 'http://localhost:4200',
        'Access-Control-Request-Method' => 'GET',
    ])->options('/api/v1/essai/reseau');

    expect($r->headers->get('Access-Control-Allow-Origin'))->toBe('http://localhost:4200');

    $r = $this->withHeader('Origin', 'http://localhost:4200')->getJson('/api/v1/essai/reseau');
    expect($r->headers->get('Access-Control-Expose-Headers'))->toContain('Retry-After');
});

it('ne touche pas au rendu HTML hors de l\'API (back-office Filament)', function () {
    $r = $this->get('/page-qui-nexiste-pas');

    $r->assertNotFound();
    expect($r->headers->get('Content-Type'))->not->toContain('json');
});
