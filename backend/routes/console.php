<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// M12 : relance la conversion des adresses importées d'une autre bibliothèque tant qu'il en reste.
Schedule::command('babel:convertir-import')->everyTenMinutes()->withoutOverlapping();
