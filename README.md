# La Bibliothèque de Babel en réseau — Groupe Babel 3

Projet fil rouge **R5.06 (Back)** et **R5.05 (Front)** — BUT MMI 3, parcours Développement web et dispositifs interactifs, IUT de Béziers (Université de Montpellier).

Inspiré de la nouvelle de Jorge Luis Borges et de *libraryofbabel.info*, le projet met en réseau **10 bibliothèques** développées indépendamment qui communiquent via une **API publique commune** (branchement réseau prévu le **2 décembre**).

---

## Structure du dépôt

| Dossier | Rôle | Stack |
|---|---|---|
| `backend/` | API REST, moteur de pages, back-office et WebSocket | Laravel 13, PHP 8.3+ (`gmp`), Sanctum 4, Filament 5 (`/admin`), Reverb, Pest 4, SQLite |
| `frontend/` | Application web publique (composants autonomes, SSR) | Angular 22, TypeScript, Tailwind CSS, Signals, RxJS, Echo |
| `bruno/` | Collection de requêtes API pour les tests manuels | Bruno (environnement `local`) |
| `docs/` | Documentation du projet | Markdown |

---

## État d'avancement du projet

Le développement suit une progression incrémentale alignée sur le cahier des charges final (15 novembre 2026) et le calendrier du cours.

### 1. Socle technique et environnement — Terminé
- Initialisation du monorepo (`backend/`, `frontend/`, `bruno/`, `docs/`).
- Mise en place de **Laravel 13** avec **Sanctum 4** (mode SPA + jetons API), **Filament 5** sur `/admin` et **Laravel Reverb** pour le temps réel.
- Mise en place d'**Angular 22** avec Tailwind CSS et configuration SSR.
- Création de la collection **Bruno** (`bruno/environments/local.bru`, `csrf-cookie.bru`, `utilisateur-courant.bru`).

### 2. Intégration du moteur Babel — Terminé
- Installation du paquet Composer `but-mmi-beziers/babel-moteur` (`^1.0`).
- Configuration dédiée dans `backend/config/babel.php` (`frontend_url`, `network_token`, `announce_secret`, `cle` via le paquet moteur).
- Génération de la clé locale via `php artisan babel:cle` et validation complète via `php artisan babel:verifier` (extension `GMP 6.3.0 OK`, `48/48` vecteurs de référence OK, aller-retour `lire <-> chercher OK`).

### 3. Modèle de données et API REST — À venir
- Conception des migrations et modèles Eloquent à partir des 14 tables du cahier des charges (aucun texte de page stocké en base, unicité par `(bibliotheque_id, empreinte)`).
- Implémentation des règles serveur communes : `normaliser`, `valider` (codes `ERR_*`), `auHasard` (tirage uniforme sans graine).
- Exposition des endpoints REST dans `routes/api.php` avec `FormRequest` et `API Resources` conformes au contrat OpenAPI commun.
- Gestion des comptes locaux, marque-pages, collections, annotations, liens courts, signalements et modération dans Filament.

### 4. Tests automatisés et intégration continue — À venir (20 nov.)
- Mise en place de la suite de tests **Pest** (tests unitaires et fonctionnels, dont vérification statistique du tirage `auHasard`).
- Pipeline d'intégration continue (GitHub Actions).

### 5. Réseau et temps réel — À venir (2 déc.)
- Annonce auprès de l'annuaire et découverte des pairs.
- Recherche fédérée en parallèle (timeout 2 s par pair, gestion hors ligne au 3e échec) avec diffusion des résultats au fil de l'eau via **Reverb**.
- Échange et modération des textes bannis (route protégée par le jeton réseau), export/import de compte.

### 6. Interface Angular — À venir (déc. – jan.)
- Lecture de pages, recherche (`exact` / `bruit`), normalisation affichée, localisation/surlignage (`localiser`) et navigation (`voisine`) côté client.
- Authentification SPA par cookie HttpOnly + CSRF (`provideAppInitializer`, intercepteur fonctionnel, guards).
- Espace membre, liens courts, recherche réseau en temps réel et rendu SSR.

---

## Prérequis (macOS / Homebrew)

```bash
brew install php composer node
php -m | grep -i gmp   # doit afficher « gmp »
```

Si l'extension `gmp` est absente : `brew reinstall php`, puis vérifier à nouveau.

---

## Installation et lancement en local

### 1. Installer le backend

```bash
cd backend
composer setup
```

`composer setup` installe les dépendances Composer, crée `.env` depuis `.env.example`, génère `APP_KEY`, crée la base SQLite et exécute les migrations.

### 2. Générer et vérifier la clé du moteur (une seule fois par poste)

```bash
cd backend
php artisan babel:cle
php artisan babel:verifier
```

La commande `babel:verifier` doit se terminer par : `INFO Tout est prêt : le moteur est utilisable.`

### 3. Lancer les serveurs

```bash
# Terminal 1 : API Laravel (http://localhost:8000)
cd backend && php artisan serve

# Terminal 2 : WebSocket Reverb (port 8080)
cd backend && php artisan reverb:start

# Terminal 3 : Front Angular (http://localhost:4200)
cd frontend && npm install && npx ng serve
```

### 4. Back-office Filament

Accessible sur `http://localhost:8000/admin` (la racine `/` redirige vers `/admin`). Créer un compte administrateur :

```bash
cd backend && php artisan make:filament-user
```

---

## Gestion des secrets

Les secrets ne doivent **jamais** être commités, journalisés ni exposés côté front ou en base de données :
- `BABEL_KEY` : clé privée du moteur (seule son empreinte SHA-256 est publique).
- `BABEL_NETWORK_TOKEN` : jeton partagé pour la route des textes bannis entre bibliothèques.
- `BABEL_ANNOUNCE_SECRET` : secret d'authentification auprès de l'annuaire réseau.

Toutes ces variables sont définies uniquement dans `backend/.env` (ignoré par Git) et lues via `config('babel.*')`.
