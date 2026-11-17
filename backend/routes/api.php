<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Porte réseau : /api/v1
|--------------------------------------------------------------------------
| Ce que les autres bibliothèques appellent chez nous (8 routes, identiques partout) :
| GET /identite, GET /pages/{adresse}, POST /lectures, POST /recherches, GET /collections,
| GET /collections/{id}, GET /pages-populaires, GET /textes-bannis (jeton réseau).
|
| Le préfixe /api/v1 est ajouté par bootstrap/app.php. Pas de ->where() sur {adresse} :
| une adresse refusée doit recevoir une erreur du contrat, pas la 404 HTML de Laravel.
| Limite de débit : throttle:reseau (60 requêtes/minute/IP, voir AppServiceProvider).
*/
Route::middleware('throttle:reseau')->group(function (): void {
    // Les routes métier arrivent avec la branche feature/api-reseau.
});
