# Backend : API Laravel de Babel

Laravel 13 · Sanctum (jetons Bearer) · Filament 5 (`/admin`) · Reverb · Pest · SQLite.

```bash
composer setup            # dépendances, .env, clé d'app, base SQLite, migrations
php artisan serve         # API sur http://localhost:8000
php artisan reverb:start  # WebSocket sur le port 8080
./vendor/bin/pest         # tests
```

Les secrets (`BABEL_KEY`, `BABEL_NETWORK_TOKEN`, `BABEL_ANNOUNCE_SECRET`) se remplissent dans `.env`
et se lisent uniquement via `config('babel.*')` (la clé du moteur : `config('babel.cle')`).

## Back-office (`/admin`)

Réservé aux administrateurs actifs (Gate `administrer`). Le premier administrateur se crée hors interface
(`User::factory()->admin()` en local, ou `php artisan tinker` pour changer le `role` d'un compte) ; les suivants se promeuvent depuis la liste des comptes.

| Écran | Stories |
| --- | --- |
| Comptes | A1, A2, A3 |
| Signalements | A4 |
| Textes bannis | A5, A7 |
| État du réseau | A9 |
| Réglages (nom, description, inscriptions, quotas) | A8, A10 |
| Tableau de bord (statistiques) | A11 |

Les quotas de l'API (`quota_api_publique`, `quota_lecture`, `quota_publication`) sont dans la table `reglages` ; `Reglage::quota('…')` les lit (cache 30 s).
La modération des textes bannis relit les pages en tâche de fond : `php artisan babel:verifier-bannis` (planifiée toutes les heures) et un worker de queue.
