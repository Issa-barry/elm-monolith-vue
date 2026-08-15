# en DEV (a ne pas supprimer par IA)
php artisan migrate:fresh --seed
php artisan optimize:clear
php artisan optimize 

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

# Configurer .env (DB, APP_ENV=production, APP_INSTALL_TOKEN=<clé secrète>...) puis :
$PHP artisan key:generate
$PHP artisan migrate --force
$PHP artisan db:seed --class=ProductionSeeder --force
ln -s "$PWD/storage/app/public" "$PWD/public/storage" || true

$PHP artisan optimize:clear
$PHP artisan optimize

```






Puis, **depuis un navigateur**, ouvrir `https://ton-domaine/install` et suivre l'assistant
(4 étapes : Entreprise, Super Admin, Catalogue initial, Résumé). C'est la façon recommandée
de terminer la première installation : le pipeline CI/CD (`deploy-hostinger.yml`) ne lance
**jamais** cette étape automatiquement — elle demande une saisie humaine (nom de
l'entreprise, identité et mot de passe du Super Admin) qui n'a pas sa place dans un script
de déploiement.

L'assistant web est protégé par `APP_INSTALL_TOKEN` (variable d'environnement à définir dans
`.env` avant le 1er accès — la clé n'est jamais stockée en base ni loguée, seule sa
vérification est mémorisée en session) et devient automatiquement inaccessible (404) dès que
l'installation est marquée terminée : impossible de la relancer par erreur ensuite.

Pour un déploiement scripté/CI sans navigateur, la même logique reste disponible en CLI :

```bash
$PHP artisan app:install
```

CLI et web partagent exactement le même service (`InstallationService`) — les deux produisent
un résultat identique. Aucun slug n'est demandé : il est généré automatiquement à partir du
nom saisi, modifiable ensuite dans les paramètres de l'entreprise (backoffice). L'installation
est idempotente par nom d'entreprise : si une entreprise du même nom existe déjà (ex: "Eau la
maman", créée par `ProductionSeeder`), elle est réutilisée au lieu d'être recréée — il suffit
donc de saisir le même nom que celui déjà en base pour juste ajouter le super_admin. Dans les
deux cas, le mot de passe est choisi directement par la personne qui installe (saisie masquée
en CLI, champ mot de passe en web) — il n'est jamais généré, affiché en clair, ni conservé.

## Déploiements suivants (uniquement)

```bash
$PHP artisan migrate --force
$PHP artisan optimize:clear
$PHP artisan optimize
```

```bash
php artisan migrate --force
php artisan optimize:clear
php artisan optimize
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
 
## Organisation de démonstration — Fello Demo
 

```bash
php artisan db:seed --class=FelloDemoSeeder
```
 https://demo.felloconsulting.fr/

Comptes de démo (mot de passe `FelloDemo@2025`) :

| Compte | Téléphone | Rôle | Site par défaut |
|---|---|---|---|
| Admin Fello Demo | +224600000101 | admin_entreprise | Boutique Madina |
| Commercial Fello Demo | +224600000102 | commerciale | Boutique Cosa |
 

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

Procédure complète pour repartir à zéro sur formation
Déployer ce commit sur pre-prod (le nouveau code doit être en place avant de pouvoir lancer la commande via SSH).
En SSH sur formation :

php artisan accounts:purge --organization=elm
(tapez SUPPRIMER après avoir vérifié l'URL/l'environnement affichés)
Recréer le premier compte, toujours via SSH :

php artisan app:install
Au prompt "Nom de l'entreprise", tapez exactement Eau la maman (correspondance exacte, insensible à la casse) — ça réutilise l'organisation existante (avec son catalogue/sites/etc. intacts) plutôt que d'en créer une nouvelle, puisqu'elle n'a plus de super_admin après la purge.
Vous êtes de nouveau super_admin, vous invitez ensuite vos comptes de recette normalement via l'app.