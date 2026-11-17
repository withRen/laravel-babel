<?php

/*
 * Configuration propre au projet Babel.
 *
 * Ces valeurs sont secrètes : elles viennent du .env (jamais commité) et ne doivent
 * être lues QUE via config('babel.*'), jamais avec env() ailleurs que dans config/.
 */
return [

    // URL du front Angular (origine autorisée par CORS, cf. config/cors.php).
    'frontend_url' => env('FRONTEND_URL', 'http://localhost:4200'),

    // La clé de la bibliothèque (`config('babel.cle')`, variable BABEL_KEY) est déclarée par le
    // paquet but-mmi-beziers/babel-moteur, fusionné avec ce fichier. La générer : `php artisan babel:cle`.

    // Jeton d'accès au réseau Babel (branchement du 02/12).
    'network_token' => env('BABEL_NETWORK_TOKEN'),

    // Secret servant à signer/vérifier les annonces échangées avec les autres bibliothèques.
    'announce_secret' => env('BABEL_ANNOUNCE_SECRET'),

];
