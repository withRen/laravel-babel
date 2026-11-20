<?php

use App\Exceptions\RenduErreursApi;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
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
        $middleware->trimStrings(except: ['texte', 'passage', 'mot_de_passe', 'mot_de_passe_actuel']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        RenduErreursApi::enregistrer($exceptions);
    })->create();
