# Collection Bruno

La collection se génère à partir du contrat : `docs/openapi.yaml` fait foi.

## Importer le contrat (une fois, puis à chaque nouvelle version du contrat)

1. Ouvrir Bruno, puis **Collections → Import collection → OpenAPI**.
2. Choisir `docs/openapi.yaml`, puis un dossier d'enregistrement (par exemple ce dossier `bruno/`).
3. Bruno crée toutes les requêtes d'un coup (55 : 8 de la porte réseau, 47 de la porte application), avec leurs exemples.
4. Dans l'environnement **local**, `baseUrl` vaut `http://localhost:8000` : les requêtes visent `{{baseUrl}}/api/v1/…`.

## S'authentifier

Le contrat utilise des jetons Sanctum, pas des cookies : il n'y a plus de requête « CSRF cookie ».
Appeler `POST /app/auth/connexion`, copier le jeton de la réponse, puis le mettre dans l'onglet
**Auth → Bearer Token** de la collection.
