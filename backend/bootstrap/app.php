<?php

use App\Exceptions\RenduErreursApi;
use App\Http\Middleware\AuthentificationOptionnelle;
use App\Http\Middleware\CompteActif;
use App\Http\Middleware\InscriptionsOuvertes;
use App\Http\Middleware\JetonReseau;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        // Porte réseau : routes/api.php, servie sous /api/v1 (identique dans les 10 bibliothèques).
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
        // Porte application : routes/app.php, servie sous /api/v1/app (appelée par Angular).
        then: function (): void {
            Route::middleware('api')
                ->prefix('api/v1/app')
                ->group(base_path('routes/app.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Le contrat impose des jetons d'API Sanctum (createToken, en-tête Authorization: Bearer),
        // pas le mode SPA à cookies : on n'appelle donc PAS $middleware->statefulApi().

        // TrimStrings retirerait les espaces en bord de ces champs : ça casserait le mode « page »
        // (3 200 caractères exactement), le passage d'un lien court et les mots de passe.
        // « adresses.* » : une adresse ne se corrige pas, pas même d'un espace (POST /lectures).
        $middleware->trimStrings(except: ['texte', 'passage', 'mot_de_passe', 'mot_de_passe_actuel', 'adresses.*']);

        // POST /lectures : une adresse vide « "" » doit rester une chaîne vide (une adresse refusée), pas devenir
        // null, qui serait indiscernable d'un élément absent.
        $middleware->convertEmptyStringsToNull(except: [fn (Request $request) => $request->is('api/v1/lectures')]);

        // Jamais de redirection vers une route « login » (elle n'existe pas : le back-office a sa propre
        // connexion) : un jeton manquant donne la 401 JSON du contrat, avec ou sans « Accept: application/json ».
        $middleware->redirectGuestsTo(fn () => null);

        $middleware->alias([
            'jeton.reseau' => JetonReseau::class,
            // Routes « membre » : auth:sanctum (401 sans jeton valide) puis compte.actif (403 si suspendu).
            'inscriptions.ouvertes' => InscriptionsOuvertes::class,
            'compte.actif' => CompteActif::class,
            // Routes ouvertes aux visiteurs qui tiennent compte du membre quand un jeton valide est envoyé.
            'auth.optionnelle' => AuthentificationOptionnelle::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        RenduErreursApi::enregistrer($exceptions);
    })->create();
