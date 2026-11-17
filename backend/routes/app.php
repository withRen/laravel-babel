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

// Les routes métier arrivent avec les branches auth-comptes, lecture-annotee, etc.
