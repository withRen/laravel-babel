# Moteur

Le moteur est le paquet Composer `but-mmi-beziers/babel-moteur` (installé dans `vendor/`) : Laravel le découvre seul.
Dans un contrôleur, on demande `Babel\Moteur\Moteur` en paramètre (injection de dépendances), jamais `new Moteur()`.

Mise en route sur chaque poste : `php artisan babel:cle` puis `php artisan babel:verifier` (doit finir par « Tout est prêt »).
La clé (`BABEL_KEY`) ne se committe jamais et ne se change jamais une fois des adresses partagées.
