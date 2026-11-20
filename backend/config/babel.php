<?php

/*
 * Configuration propre au projet Babel.
 *
 * Ces valeurs sont secrètes : elles viennent du .env (jamais commité) et ne doivent
 * être lues QUE via config('babel.*'), jamais avec env() ailleurs que dans config/.
 */
return [

    // URL du front Angular (origine autorisée par CORS, cf. config/cors.php).
    // Identité de notre bibliothèque (ligne `est_locale` de la table bibliotheques).
    // L'identifiant est remis par la plateforme et ne change jamais.
    'identifiant' => env('BABEL_IDENTIFIANT', 'babel-3'),

    'nom' => env('BABEL_NOM', 'Babel 3'),

    'frontend_url' => env('FRONTEND_URL', 'http://localhost:4200'),

    // La clé de la bibliothèque (`config('babel.cle')`, variable BABEL_KEY) est déclarée par le
    // paquet but-mmi-beziers/babel-moteur, fusionné avec ce fichier. La générer : `php artisan babel:cle`.

    // Jeton d'accès au réseau Babel (branchement du 02/12).
    'network_token' => env('BABEL_NETWORK_TOKEN'),

    // Secret d'annonce remis par la plateforme : il prouve notre identifiant auprès de l'annuaire,
    // et ne sert jamais entre bibliothèques.
    'announce_secret' => env('BABEL_ANNOUNCE_SECRET'),

    // --- Plateforme du réseau (annuaire) ---

    // Adresse de base de l'annuaire. Vide tant que la plateforme ne l'a pas donnée : l'annonce est alors
    // refusée avec un message clair (elle n'invente aucune adresse).
    'annuaire_url' => env('BABEL_ANNUAIRE_URL'),

    // Délai (secondes) accordé à l'annuaire. Ce n'est pas le délai des pairs, qui est fixé par le contrat.
    'annuaire_delai' => 10,

    // L'URL du moment de NOTRE serveur, celle qu'on annonce (jamais retenue comme identité). Par défaut APP_URL.
    'url_publique' => env('BABEL_PUBLIC_URL'),

    // --- Contrôle régulier des pairs (A9) ---

    // Un pair a 2 secondes pour répondre : le même chiffre partout, il fait partie du contrat.
    'pair_delai' => 2,

    // Au troisième échec de suite, un pair passe hors ligne.
    'pair_echecs_max' => 3,

];
