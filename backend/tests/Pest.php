<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

use App\Models\Bibliotheque;

/** La ligne de notre bibliothèque, avec l'identifiant de l'exemple du contrat. */
function bibliothequeLocale(array $attributs = []): Bibliotheque
{
    return Bibliotheque::factory()->locale()->create($attributs + [
        'identifiant' => 'exemple',
        'nom' => "La bibliothèque d'exemple",
        'description' => 'Une bibliothèque de démonstration.',
    ]);
}

/** L'en-tête qui présente le jeton réseau de test (voir phpunit.xml). */
function jetonReseau(string $jeton = 'jeton-reseau-de-test'): array
{
    return ['Authorization' => 'Bearer '.$jeton];
}

/** Vérifie la forme exacte d'une erreur du contrat : une liste « erreurs » avec code, champ, message. */
function attendErreurContrat($reponse, int $statut, string $code, ?string $champ): void
{
    $reponse->assertStatus($statut);
    expect(array_keys($reponse->json()))->toBe(['erreurs']);
    expect($reponse->json('erreurs.0.code'))->toBe($code);
    expect($reponse->json('erreurs.0.champ'))->toBe($champ);
    expect($reponse->json('erreurs.0.message'))->toBeString()->not->toBeEmpty();
}
