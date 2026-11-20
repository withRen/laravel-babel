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

use App\Http\Controllers\Api\AnnotationMembreController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DonneesController;
use App\Http\Controllers\Api\HistoriqueController;
use App\Http\Controllers\Api\MaCollectionController;
use App\Http\Controllers\Api\MarquePageController;
use App\Http\Controllers\Api\MoiController;
use App\Http\Controllers\Api\VoteController;
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

        // Marque-pages (M2, M3, M13).
        Route::get('marque-pages', [MarquePageController::class, 'index']);
        Route::post('marque-pages', [MarquePageController::class, 'store']);
        Route::patch('marque-pages/{id}', [MarquePageController::class, 'update']);
        Route::delete('marque-pages/{id}', [MarquePageController::class, 'destroy']);

        // Collections du membre (M4, M5, M13) : publiques et privées, avec leurs pages ordonnées.
        Route::get('collections', [MaCollectionController::class, 'index']);
        Route::post('collections', [MaCollectionController::class, 'store']);
        Route::get('collections/{id}', [MaCollectionController::class, 'show']);
        Route::patch('collections/{id}', [MaCollectionController::class, 'update']);
        Route::delete('collections/{id}', [MaCollectionController::class, 'destroy']);
        Route::post('collections/{id}/pages', [MaCollectionController::class, 'ajouterPage']);
        Route::put('collections/{id}/pages', [MaCollectionController::class, 'ordonnerPages']);
        Route::delete('collections/{id}/pages/{position}', [MaCollectionController::class, 'retirerPage']);

        // Annotations (M6).
        Route::get('annotations', [AnnotationMembreController::class, 'index']);
        Route::post('annotations', [AnnotationMembreController::class, 'store']);
        Route::patch('annotations/{id}', [AnnotationMembreController::class, 'update']);
        Route::delete('annotations/{id}', [AnnotationMembreController::class, 'destroy']);

        // Historique des recherches (M8) et votes (M7).
        Route::get('recherches', [HistoriqueController::class, 'index']);
        Route::delete('recherches', [HistoriqueController::class, 'viderTout']);
        Route::delete('recherches/{id}', [HistoriqueController::class, 'destroy']);
        Route::get('votes', [VoteController::class, 'index']);

        // Export et import de compte (M11, M12).
        Route::get('export', [DonneesController::class, 'exporter']);
        Route::post('import', [DonneesController::class, 'importer']);
        Route::get('import', [DonneesController::class, 'etatImport']);
    });

    // Voter pour ou contre une page (M7) : la page est dans le chemin, pas sous /moi.
    Route::put('pages/{adresse}/vote', [VoteController::class, 'voter']);
    Route::delete('pages/{adresse}/vote', [VoteController::class, 'retirer']);
});
