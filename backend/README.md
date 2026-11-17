# Backend : API Laravel de Babel

Laravel 13 · Sanctum (SPA) · Filament 5 (`/admin`) · Reverb · Pest · SQLite.

```bash
composer setup            # dépendances, .env, clé d'app, base SQLite, migrations
php artisan serve         # API sur http://localhost:8000
php artisan reverb:start  # WebSocket sur le port 8080
./vendor/bin/pest         # tests
```

Les secrets (`BABEL_KEY`, `BABEL_NETWORK_TOKEN`, `BABEL_ANNOUNCE_SECRET`) se remplissent dans `.env`
et se lisent uniquement via `config('babel.*')` (la clé du moteur : `config('babel.cle')`).
