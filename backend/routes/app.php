<?php

/*
|--------------------------------------------------------------------------
| Porte application : /api/v1/app
|--------------------------------------------------------------------------
| Ce que l'application Angular appelle (47 routes). Le préfixe /api/v1/app et le groupe
| middleware « api » sont ajoutés par bootstrap/app.php.
|
| Authentification : jeton Sanctum dans « Authorization: Bearer … » (middleware auth:sanctum
| pour les routes « membre »). Pas de ->where() sur {adresse}. Déclarer /hasard AVANT les
| routes /pages/{adresse}.
*/

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\MoiController;
use Illuminate\Support\Facades\Route;

// Les autres routes métier arrivent avec les branches lecture-annotee, etc.

// Comptes (V19, M1) : sans jeton. Throttlées : ce sont les deux portes qu'un pirate martèlerait.
Route::prefix('auth')->group(function (): void {
    Route::post('inscription', [AuthController::class, 'inscrire'])
        ->middleware(['throttle:inscription', 'inscriptions.ouvertes']);
    Route::post('connexion', [AuthController::class, 'connecter'])
        ->middleware('throttle:connexion');
    // Pas de « compte.actif » ici : un membre suspendu doit pouvoir fermer sa session.
    Route::post('deconnexion', [AuthController::class, 'deconnecter'])
        ->middleware('auth:sanctum');
});

// Routes « membre » : jeton valide (401 sinon) ET compte non suspendu (403 sinon).
// Les routes /moi/… des autres branches (marque-pages, collections…) rejoignent ce groupe.
Route::middleware(['auth:sanctum', 'compte.actif'])->group(function (): void {
    Route::prefix('moi')->group(function (): void {
        Route::get('/', [MoiController::class, 'show']);       // M1
        Route::patch('/', [MoiController::class, 'update']);   // M9
        Route::delete('/', [MoiController::class, 'destroy']); // M10
    });
});
