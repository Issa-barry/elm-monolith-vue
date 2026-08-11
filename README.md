# Déploiement

## ⚠️ Ne jamais faire en production

`migrate:fresh --seed` (et toute variante `migrate:fresh`) — bloqué en dur côté code
(`DB::prohibitDestructiveCommands`, cf. `AppServiceProvider`) même avec `--force`, mais à
ne jamais taper par réflexe : ça vide toute la base. Utiliser `migrate --force` seul.

`db:seed` sans `--class=ProductionSeeder` — le seeder par défaut (`DatabaseSeeder`) crée
des clients/livreurs/véhicules/produits fictifs, pas fait pour la production.

## 1er déploiement (base vide, une seule fois)

```bash
cd ~/domains/xxx.com/public_html
export PHP=/opt/alt/php84/usr/bin/php   # si besoin de forcer la version PHP
composer2 install --no-dev --prefer-dist --optimize-autoloader

# Configurer .env (DB, APP_ENV=production...) puis :
$PHP artisan key:generate

# Schéma — migrate:fresh acceptable ici uniquement (base vide)
$PHP artisan migrate --force

# Données de référence (rôles, permissions, sites, catalogue de base) — jamais db:seed seul
$PHP artisan db:seed --class=ProductionSeeder --force

# Organisation + premier compte super_admin — mot de passe saisi en masqué,
# jamais affiché en clair, à redéfinir obligatoirement à la première connexion
$PHP artisan app:install

# Lien symbolique storage (une seule fois)
ln -s "$PWD/storage/app/public" "$PWD/public/storage" || true

$PHP artisan optimize:clear
$PHP artisan optimize
```

`app:install` est idempotent par organisation : si le slug donné existe déjà (ex: "elm",
créée par `ProductionSeeder`), il la réutilise au lieu d'en recréer une — il suffit donc de
répondre avec le même nom/slug que celui déjà en base pour juste ajouter le super_admin.

## Déploiements suivants (uniquement)

```bash
$PHP artisan migrate --force
$PHP artisan optimize:clear
$PHP artisan optimize
```

C'est exactement ce que fait `deploy-hostinger.yml` automatiquement sur push vers `main` —
avec le pipeline CI/CD configuré, ces commandes n'ont normalement plus besoin d'être tapées
à la main.

## Sur ton PC (si build manuel, hors CI/CD)

```bash
npm run build
git add public/build
git commit -m "build: production"
git push
```

## CI/CD Hostinger (GitHub Actions)

Flux de branches:
- `dev` -> `pre-prod` -> `main`

CI (qualite + tests) sur Pull Request vers `pre-prod` et `main`:
- `.github/workflows/lint.yml`
- `.github/workflows/tests.yml`
  - `ci`: PHPUnit
  - `e2e`:
    - PR `dev -> pre-prod`: suite Playwright complete
    - PR `pre-prod -> main`: smoke test Playwright

Controle du flux de branches:
- `.github/workflows/branch-flow.yml`
- Autorise uniquement: `dev -> pre-prod` puis `pre-prod -> main`
 
CD (deploiement production) sur `main`:
- `.github/workflows/deploy-hostinger.yml`

Guide complet:
- `DEPLOY-HOSTINGER-CICD.md`

## Organisation de démonstration — Fello Demo

Jeu de données de démo pour une boutique/POS (vêtements), totalement indépendant
de l'organisation `elm` (Eau la maman) — vitrine commerciale pour présenter
l'appli à d'autres clients (cible : `https://demo.felloconsulting.fr/`).

```bash
php artisan db:seed --class=FelloDemoSeeder
```

- Ne fait **pas** partie de `DatabaseSeeder` (n'est jamais lancé par
  `migrate:fresh --seed`) — à exécuter à part, sur une base déjà migrée
  (avec ou sans `elm`).
- **Idempotent** : peut être relancé sans créer de doublons (organisation,
  sites, comptes, catalogue, clients en `updateOrCreate`/`firstOrCreate` ;
  l'historique de ventes est purgé — uniquement les données `fello-demo` —
  puis régénéré à chaque exécution).
- **Isolation garantie** : toutes les données créées portent
  `organization_id = fello-demo`, jamais `elm` — vérifié par
  `tests/Feature/FelloDemoSeederTest.php`.

Crée : organisation `fello-demo`, 2 sites (Boutique Madina, Boutique Cosa),
2 comptes staff, 21 produits (6 catégories — indiquées dans `description`,
le projet n'ayant pas de modèle Categorie), stock différencié par boutique,
5 clients, et ~40-65 ventes PDV historiques (40 derniers jours) avec
factures et encaissements, générées via le vrai `PdvCheckoutService` (pas
d'insertion brute).

Comptes de démo (mot de passe `FelloDemo@2025`) :

| Compte | Téléphone | Rôle | Site par défaut |
|---|---|---|---|
| Admin Fello Demo | +224600000101 | admin_entreprise | Boutique Madina |
| Commercial Fello Demo | +224600000102 | commerciale | Boutique Cosa |

Détail des sous-seeders : `database/seeders/Organizations/FelloDemo/`.
Limites connues : pas de transferts de stock entre boutiques (module
`TransfertLogistique` pas assez couvert par les tests pour ce scénario, et
exige un véhicule interne peu naturel pour une démo boutique).

## Stack technique

| Couche | Technologie |
|---|---|
| Backend | Laravel 12, Fortify, Spatie Permission |
| Frontend | Vue 3, Inertia.js, Vite, Tailwind CSS, PrimeVue |
| Auth | Connexion par numéro de téléphone + mot de passe |
| Tests | PHPUnit (Feature tests) |

## Tests

```bash
# Tous les tests
php artisan test

# Fichier spécifique
php artisan test tests/Feature/Auth/RegistrationTest.php
```

### Tests E2E (Playwright)

```bash
# 1) Première installation du navigateur E2E (une seule fois)
npm run e2e:install

# 2) Lancer toute la suite E2E
npm run e2e

# 3) Mode interactif / debug
npm run e2e:headed
npm run e2e:ui

# 4) Voir le rapport HTML après exécution
npm run e2e:report
```

Scénarios E2E disponibles :

```bash
# Smoke
npx playwright test tests/e2e/smoke.spec.ts

# Produits
npx playwright test tests/e2e/produit-flow.spec.ts

# Livreurs
npx playwright test tests/e2e/livreur-flow.spec.ts

# Propriétaires
npx playwright test tests/e2e/proprietaire-flow.spec.ts

# Véhicules
npx playwright test tests/e2e/vehicule-flow.spec.ts
```

Variables d'environnement E2E utiles (optionnelles) :

```bash
```

### Divers

```bash
# Code coverage
php -d pcov.enabled=1 vendor/bin/phpunit --coverage-text

# Serveur de dev local
php artisan serve --port=8080
```