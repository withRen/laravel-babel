<?php

use App\Models\ActiviteJour;
use App\Models\Recherche;
use App\Models\User;
use Babel\Moteur\Moteur;

require_once __DIR__.'/Appel.php';

/* POST /app/recherches (V4, V5, V6, M8). */

beforeEach(fn () => bibliothequeLocale());

it('cherche un texte : normalisé, mode exact, adresse d\'une page qui le contient (exemple du contrat)', function () {
    $r = appel('POST', '/recherches', ['texte' => "Où est passé l'été ?", 'mode' => 'exact']);

    $r->assertOk();
    expect(array_keys($r->json()))->toBe(['bibliotheque', 'texte_normalise', 'mode', 'adresse']);
    expect($r->json('bibliotheque'))->toBe('exemple');
    expect($r->json('texte_normalise'))->toBe('ou est passe l ete');
    expect($r->json('mode'))->toBe('exact');
    // L'adresse trouvée désigne une page qui contient bien le texte.
    expect(app(Moteur::class)->lire($r->json('adresse')))->toContain('ou est passe l ete');
});

it('donne la même adresse que la porte réseau (même traitement)', function () {
    $corps = ['texte' => 'bonjour le monde', 'mode' => 'bruit'];

    expect(appel('POST', '/recherches', $corps)->json('adresse'))->toBe($this->postJson('/api/v1/recherches', $corps)->json('adresse'));
});

it('enregistre la recherche d\'un membre dans son historique (texte normalisé, portée locale)', function () {
    $ada = User::factory()->create();

    appel('POST', '/recherches', ['texte' => 'Où est passé l\'été ?', 'mode' => 'exact'], $ada)->assertOk();

    $recherche = Recherche::query()->where('utilisateur_id', $ada->id)->sole();
    expect($recherche->texte)->toBe('ou est passe l ete')->and($recherche->mode->value)->toBe('exact')->and($recherche->portee->value)->toBe('locale');
});

it('ne garde rien d\'un visiteur qu\'un compteur du jour (règle 12)', function () {
    appel('POST', '/recherches', ['texte' => 'un texte', 'mode' => 'exact'])->assertOk();
    appel('POST', '/recherches', ['texte' => 'un autre', 'mode' => 'bruit'])->assertOk();

    expect(Recherche::query()->count())->toBe(0);
    expect(ActiviteJour::query()->sole()->recherches)->toBe(2);
});

it('ne compte pas une recherche refusée', function () {
    appel('POST', '/recherches', ['texte' => '?!', 'mode' => 'exact'])->assertStatus(422);

    expect(ActiviteJour::query()->count())->toBe(0)->and(Recherche::query()->count())->toBe(0);
});

it('retrouve l\'adresse d\'une page entière en mode « page » sans l\'inscrire à l\'historique (le mode n\'existe pas dans l\'interface)', function () {
    $ada = User::factory()->create();
    $texte = app(Moteur::class)->lire(PAGE_EXEMPLE);

    $r = appel('POST', '/recherches', ['texte' => $texte, 'mode' => 'page'], $ada);

    $r->assertOk();
    expect($r->json('adresse'))->toBe(PAGE_EXEMPLE)->and($r->json('mode'))->toBe('page')->and($r->json('texte_normalise'))->toHaveLength(3200);
    expect(Recherche::query()->count())->toBe(0);
});

it('refuse avec UNE erreur du contrat, dans l\'ordre : mode, texte vide, longueur', function (array $corps, string $code, string $champ) {
    attendErreurContrat(appel('POST', '/recherches', $corps), 422, $code, $champ);
})->with([
    'mode inconnu (la casse compte)' => [['texte' => 'abc', 'mode' => 'Exact'], 'ERR_UNKNOWN_MODE', 'mode'],
    'mode absent' => [['texte' => 'abc'], 'ERR_UNKNOWN_MODE', 'mode'],
    'vide après normalisation' => [['texte' => '?!', 'mode' => 'exact'], 'ERR_EMPTY_QUERY', 'texte'],
    'texte absent' => [['mode' => 'exact'], 'ERR_EMPTY_QUERY', 'texte'],
    'trop long' => [['texte' => str_repeat('a', 3201), 'mode' => 'exact'], 'ERR_TEXT_TOO_LONG', 'texte'],
]);

it('lit un jeton de membre invalide comme un visiteur', function () {
    appel('POST', '/recherches', ['texte' => 'abc', 'mode' => 'exact'], entetes: ['Authorization' => 'Bearer faux'])->assertOk();

    expect(Recherche::query()->count())->toBe(0);
});
