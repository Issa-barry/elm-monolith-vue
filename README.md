# Eau la maman — Monolithe

Application de gestion interne — Laravel 12 + Inertia.js + Vue 3 + Vite.

---

## Stack technique

| Couche | Technologie |
|---|---|
| Backend | Laravel 12, Fortify, Spatie Permission |
| Frontend | Vue 3, Inertia.js, Vite, Tailwind CSS, PrimeVue |
| Auth | Connexion par numéro de téléphone + mot de passe |
| Tests | PHPUnit (Feature tests) |

---

## Installation locale

```bash
# 1. Cloner le dépôt
git clone <url-du-repo> elm-monolithe
cd elm-monolithe

# 2. Dépendances PHP
composer install

# 3. Dépendances JS
npm install

# 4. Configuration
cp .env.example .env
php artisan key:generate

# 5. Base de données
php artisan migrate --seed

# 6. Lancer en développement (deux terminaux)
php artisan serve
npm run dev
```

---

 
---

## Tests

```bash
# Tous les tests
php artisan test

# Fichier spécifique
php artisan test tests/Feature/Auth/RegistrationTest.php
```

---

## Déploiement

### Première mise en production

```bash
# 1. Dépendances PHP (sans packages dev)
composer install --optimize-autoloader --no-dev

# 2. Build JS pour la production
npm install
npm run build

# 3. Configuration
cp .env.example .env
# → Éditer .env (voir section Variables ci-dessous)
php artisan key:generate

# 4. Base de données
php artisan migrate --seed

# 5. Optimisations Laravel
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan storage:link
```

### Mise en production

Tu build sur ton PC, tu pousse tout y compris public/build/ :


# Sur ton PC
npm run build
git add public/build
git commit -m "build: production"
git push

### Mises à jour suivantes

```bash
# 1. Récupérer le code
git pull origin main

# 2. Dépendances
composer install --optimize-autoloader --no-dev
npm install
npm run build

# 3. Migrations (sans --seed pour ne pas écraser les données)
php artisan migrate --force

# 4. Vider et recacher
php artisan optimize:clear
php artisan optimize
```

### Variables `.env` production

```env
APP_NAME="Eau la maman"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://tondomaine.com
APP_LOCALE=fr
APP_FALLBACK_LOCALE=fr

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=elm_prod
DB_USERNAME=
DB_PASSWORD=
```

### Hostinger / cPanel

Le build Vite se fait **en local** (pas besoin de Node.js sur le serveur).
`public/build/` est inclus dans git.

**Sur ton PC avant de pousser :**
```bash
npm run build
git add public/build
git commit -m "build: production"
git push
```

**Sur le serveur (SSH ou terminal cPanel) :**
```bash
cd ~/domains/eau-la-maman.fr/public_html

git pull origin main
composer2 install --optimize-autoloader --no-dev
php artisan migrate --force
php artisan optimize:clear
php artisan optimize
```
