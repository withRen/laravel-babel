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

// M12 : relance la conversion des adresses importées d'une autre bibliothèque tant qu'il en reste.
Schedule::command('babel:convertir-import')->everyTenMinutes()->withoutOverlapping();
