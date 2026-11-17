<?php

use Illuminate\Support\Facades\Route;

// Pas de Blade pour le front : l'interface publique est l'application Angular.
// Côté Laravel, seul le panneau d'administration Filament (/admin) est servi en HTML.
Route::redirect('/', '/admin');
