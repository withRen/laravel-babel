# La Bibliothèque de Babel en réseau (groupe Babel 3)

Projet BUT MMI 3 (R5.06 back, R5.05 front).

| Dossier | Contenu |
|---|---|
| `backend/` | API Laravel 13 (Sanctum SPA, Filament 5 sur `/admin`, Reverb, Pest, SQLite) |
| `frontend/` | Angular 22 + Tailwind CSS (composants autonomes, SSR) |
| `bruno/` | Collection Bruno pour tester l'API (environnement `local`) |
| `docs/` | Cahier des charges et conception |

## Prérequis (macOS, Homebrew)

```bash
brew install php composer node
php -m | grep -i gmp   # doit afficher « gmp » (sinon : voir ci-dessous)
```
Si GMP manque : `brew reinstall php` (il est inclus) puis vérifier à nouveau.

## Lancer en local

```bash
# Terminal 1 : API
cd backend && composer setup && php artisan serve        # http://localhost:8000
# Terminal 2 : WebSocket
cd backend && php artisan reverb:start                   # port 8080
# Terminal 3 : front
cd frontend && npm install && npx ng serve               # http://localhost:4200
```
Panneau d'admin : http://localhost:8000/admin (créer un compte avec `php artisan make:filament-user`).

## Secrets

Les clés du moteur, le jeton réseau et le secret d'annonce se mettent dans `backend/.env` (non versionné), jamais dans le dépôt.
