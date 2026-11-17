<?php

use App\Http\Controllers\Api\CollectionController;
use App\Http\Controllers\Api\IdentiteController;
use App\Http\Controllers\Api\LectureController;
use App\Http\Controllers\Api\PageController;
use App\Http\Controllers\Api\PagePopulaireController;
use App\Http\Controllers\Api\RechercheController;
use App\Http\Controllers\Api\TexteBanniController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Porte réseau : /api/v1
|--------------------------------------------------------------------------
| Ce que les autres bibliothèques appellent chez nous (8 routes, identiques partout).
| Ouverte en lecture : aucune route ne demande de compte. Seule GET /textes-bannis exige le jeton réseau.
|
| Le préfixe /api/v1 est ajouté par bootstrap/app.php. Pas de ->where() sur {adresse} :
| une adresse refusée doit recevoir une erreur du contrat, pas la 404 HTML de Laravel.
| Limite de débit : throttle:reseau (60 requêtes/minute/IP, voir AppServiceProvider).
*/
Route::middleware('throttle:reseau')->group(function (): void {
    Route::get('identite', IdentiteController::class);                  // B6, A9
    Route::get('pages/{adresse}', PageController::class);               // B1
    Route::post('lectures', LectureController::class);                  // B1
    Route::post('recherches', RechercheController::class);              // B2
    Route::get('collections', [CollectionController::class, 'index']);  // B3
    Route::get('collections/{id}', [CollectionController::class, 'show']); // B3
    Route::get('pages-populaires', PagePopulaireController::class);     // B4
    Route::get('textes-bannis', TexteBanniController::class)            // A6, B5
        ->middleware('jeton.reseau');
});
