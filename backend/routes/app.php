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
use App\Http\Controllers\Api\HasardController;
use App\Http\Controllers\Api\HistoriqueController;
use App\Http\Controllers\Api\BibliothequeController;
use App\Http\Controllers\Api\InfosController;
use App\Http\Controllers\Api\LienCourtController;
use App\Http\Controllers\Api\MaCollectionController;
use App\Http\Controllers\Api\MarquePageController;
use App\Http\Controllers\Api\MoiController;
use App\Http\Controllers\Api\PageAppController;
use App\Http\Controllers\Api\RechercheAppController;
use App\Http\Controllers\Api\RechercheReseauController;
use App\Http\Controllers\Api\SignalementController;
use App\Http\Controllers\Api\VoteController;
use Illuminate\Support\Facades\Route;

// Les autres routes métier arrivent avec les branches lecture-annotee, etc.

// Routes « visiteur ou membre » (V) : ouvertes à tous ; si l'application envoie un jeton valide, le membre est
// reconnu (vote, marque-page, annotations privées), sinon (ou jeton faux, ou compte suspendu) c'est un visiteur.
Route::middleware('auth.optionnelle')->group(function (): void {
    // Lecture : /hasard AVANT /pages/{adresse}, comme le demande l'en-tête de ce fichier.
    Route::middleware('throttle:lecture')->group(function (): void {
        Route::get('hasard', HasardController::class);                       // V9
        Route::get('pages/{adresse}', PageAppController::class);             // V1, V15, V16
        Route::post('recherches', RechercheAppController::class);            // V4, V5, V6
        Route::get('liens-courts/{slug}', [LienCourtController::class, 'show']); // V12
    });

    // Publier (lien court, signalement) : quota plus serré, un visiteur peut le faire sans compte.
    Route::middleware('throttle:publication')->group(function (): void {
        Route::post('liens-courts', [LienCourtController::class, 'store']);  // V11
        Route::post('signalements', [SignalementController::class, 'store']); // V17
    });
});

// Bibliothèque et réseau (V15, V16, V20, V21, M13) : ouvertes aux visiteurs ; un membre reconnu à son jeton est
// seulement noté (historique de ses recherches). Un jeton faux est traité comme un visiteur.
Route::middleware('auth.optionnelle')->group(function (): void {
    Route::get('infos', InfosController::class);                                              // V19, V20, A8
    Route::get('bibliotheques', [BibliothequeController::class, 'index']);                    // V15, V21, M13
    Route::get('bibliotheques/{identifiant}/collections', [BibliothequeController::class, 'collections']);       // V13, V21
    Route::get('bibliotheques/{identifiant}/collections/{id}', [BibliothequeController::class, 'collection']);   // V13, V21
    Route::get('bibliotheques/{identifiant}/pages-populaires', [BibliothequeController::class, 'pagesPopulaires']); // V13, V21
    Route::post('recherches-reseau', [RechercheReseauController::class, 'lancer']);           // V15
    Route::get('recherches-reseau/{identifiant}', [RechercheReseauController::class, 'etat']); // V15
});

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
        Route::get('liens-courts', [LienCourtController::class, 'index']);            // V11 (les miens)
        Route::delete('liens-courts/{slug}', [LienCourtController::class, 'destroy']); // V11
    });

    // Voter pour ou contre une page (M7) : la page est dans le chemin, pas sous /moi.
    Route::put('pages/{adresse}/vote', [VoteController::class, 'voter']);
    Route::delete('pages/{adresse}/vote', [VoteController::class, 'retirer']);
});
