# Moteur

Le moteur est le paquet Composer `but-mmi-beziers/babel-moteur` (installé dans `vendor/`) : Laravel le découvre seul.
Dans un contrôleur, on demande `Babel\Moteur\Moteur` en paramètre (injection de dépendances), jamais `new Moteur()`.

Mise en route sur chaque poste : `php artisan babel:cle` puis `php artisan babel:verifier` (doit finir par « Tout est prêt »).
La clé (`BABEL_KEY`) ne se committe jamais et ne se change jamais une fois des adresses partagées.

# Règles communes écrites sur le serveur

Trois fonctions du livrable 2 sont des règles communes que le serveur écrit lui-même (le moteur ne les fournit pas).
Comme le moteur, elles s'injectent (`Normaliseur`, `Valideur`, `TirageAuHasard` en paramètre), jamais `new`.

| Fonction | Classe | Rôle |
|---|---|---|
| F3 `normaliser` | `Normaliseur` | texte quelconque → 29 symboles (`normaliser($texte, etapesDePage: true)` pour le mode `page`) |
| F5 `valider` | `Valideur` | adresse → `ResultatValidation` (niveau `page` ou `livre`, ou toutes les erreurs) ; ne lève jamais |
| F7 `auHasard` | `TirageAuHasard` | adresse de page uniforme sur toute la bibliothèque (GMP + `random_bytes`) |

`Bibliotheque` porte les dimensions (N = 29^3200 pages, dernier hexagone) et la conversion adresse ↔ numéro de page ; toutes ses constantes viennent de `Babel\Moteur\Moteur`.
