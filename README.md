# Eau maman — Monolithe


# magic 
composer2 update
php artisan migrate:fresh --seed
php artisan optimize:clear
php artisan optimize
 


# Sur ton PC
npm run build
git add public/build
git commit -m "build: production"
git push  
 
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
 
# Sur le serveur


cd ~/domains/eau-la-maman.fr/public_html
composer2 install --no-dev --prefer-dist --optimize-autoloader
php artisan migrate --force
php artisan optimize:clear
php artisan optimize

php artisan storage:link 


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
E2E_BASE_URL=http://127.0.0.1:8000
E2E_PHONE=+33758855039
E2E_PASSWORD=Staff@2025
E2E_EMAIL=superadmin@admin.com
```

 
# code coverage : 
php -d pcov.enabled=1 vendor/bin/phpunit --coverage-text

# magic 
composer2 update
php artisan migrate:fresh --seed
php artisan optimize:clear
php artisan optimize

brache feature

php artisan serve --port=8080