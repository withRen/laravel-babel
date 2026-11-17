<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Toutes les routes d'API vivent ici (préfixe /api ajouté automatiquement).
Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');
