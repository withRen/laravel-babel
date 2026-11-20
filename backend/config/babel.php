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

    // --- Temps réel (recherche réseau, Reverb) ---

    // Ce que le NAVIGATEUR doit savoir pour se connecter au serveur Reverb (GET /app/infos, champ temps_reel).
    // Derrière ngrok, l'hôte vu du navigateur n'est pas celui que le serveur utilise pour publier : on peut donc
    // les séparer. Par défaut ce sont les valeurs REVERB_* de Laravel. Rien de secret ici (la clé Reverb est publique).
    'temps_reel' => [
        'hote' => env('BABEL_TEMPS_REEL_HOTE') ?: env('REVERB_HOST'),
        'port' => (int) (env('BABEL_TEMPS_REEL_PORT') ?: env('REVERB_PORT', 443)),
        'tls' => (env('BABEL_TEMPS_REEL_SCHEME') ?: env('REVERB_SCHEME', 'https')) === 'https',
    ],

    // --- Recherche réseau (V15) ---

    // Combien de temps l'état d'une recherche réseau reste lisible (GET /app/recherches-reseau/{id}) : au moins 10 minutes.
    'recherche_reseau_minutes' => 15,

    // --- Proxies de confiance (quota par pair, A10) ---

    // Adresses des proxies dont on croit l'en-tête X-Forwarded-For. Derrière ngrok (agent sur la même machine), le
    // proxy se connecte depuis la boucle locale. « * » (tout croire) n'est sûr que si le serveur n'est joignable QUE
    // par le proxy : sinon n'importe qui peut écrire X-Forwarded-For et esquiver le quota. Liste séparée par des virgules.
    'trusted_proxies' => array_values(array_filter(array_map('trim', explode(',', (string) env('BABEL_TRUSTED_PROXIES', '127.0.0.1,::1'))))),

];
