<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Réseau : s'annoncer à l'annuaire et suivre les pairs en tâche de fond (A9). Nécessite `php artisan schedule:work`
// (ou le cron `schedule:run`) et un worker de queue (`php artisan queue:work`).
Schedule::command('babel:annoncer')
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->when(fn () => filled(config('babel.annuaire_url'))); // pas d'annuaire configuré : rien à annoncer

Schedule::command('babel:controler-pairs')
    ->everyFiveMinutes()
    ->withoutOverlapping();

// Réseau : relever les textes bannis des pairs (B5). Ils arrivent « proposés » : l'administrateur décide (A7).
// La commande refuse de tourner sans BABEL_NETWORK_TOKEN, donc on ne la planifie que si le jeton existe.
Schedule::command('babel:relever-textes-bannis')
    ->everyTenMinutes()
    ->withoutOverlapping()
    ->when(fn () => filled(config('babel.network_token')));
