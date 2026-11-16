# EAU LA MAMAN — back-office (`elm-monolithe`)

Application de gestion d'EAU LA MAMAN : ventes et encaissements, logistique et stock, véhicules et
livreurs, trésorerie et comptabilité, commissions, RH et paie, rapports. Elle sert aussi l'API des
applications mobiles et du site.

## Stack

| Couche | Technologie |
|---|---|
| Back-end | PHP 8.4, Laravel 12, Fortify, Sanctum, Spatie Permission, Pennant |
| Front-end | Vue 3, TypeScript, Inertia.js, Vite, Tailwind CSS 4, PrimeVue |
| Base de données | MySQL (SQLite en mémoire pour les tests) |
| Tests | PHPUnit, Vitest, Playwright |
| Connexion | Numéro de téléphone + mot de passe |

Détail : [vue d'ensemble de l'architecture](docs/architecture/vue-ensemble.md).

## Documentation

**Point d'entrée : [docs/README.md](docs/README.md)**, qui recense les guides techniques, les
règles métier par domaine et les décisions (ADR).

| Je veux… | Document |
|---|---|
| Installer le projet sur mon poste | [docs/installation/environnement-local.md](docs/installation/environnement-local.md) |
| Comprendre une variable du `.env` | [docs/installation/configuration.md](docs/installation/configuration.md) |
| Connaître les règles à respecter (interface, serveur) | [docs/developpement/standards.md](docs/developpement/standards.md) |
| Comprendre l'architecture | [docs/architecture/vue-ensemble.md](docs/architecture/vue-ensemble.md) |
| Connaître les branches et les versions | [docs/developpement/workflow-git.md](docs/developpement/workflow-git.md) |
| Comprendre la CI et les déploiements automatiques | [docs/developpement/ci-cd.md](docs/developpement/ci-cd.md) |
| Lancer les tests | [docs/developpement/tests.md](docs/developpement/tests.md) |
| Déployer (Hostinger, premier déploiement, `/install`) | [docs/deploiement/hostinger.md](docs/deploiement/hostinger.md) |
| Utiliser l'organisation de démonstration | [docs/installation/demonstration.md](docs/installation/demonstration.md) |
| Retrouver une décision | [docs/adr/README.md](docs/adr/README.md) |

## Aide-mémoire

Serveur local : `php artisan serve` (port 8000). Le port 8080 est utilisé par les tests E2E.

Après une mise à jour du code en local (`git pull`) :

```bash
composer install
npm install
php artisan migrate
php artisan optimize:clear
npm run build        # ou npm run dev
```

Sur un serveur, le déploiement automatique fait l'équivalent, plus le seeder des rôles
([hostinger.md](docs/deploiement/hostinger.md#ce-que-fait-le-déploiement-automatique)).

En DEV (a ne pas supprimer par IA) — **vide entièrement la base locale** et la remplit avec les
données de développement :

```bash
php artisan migrate:fresh --seed
php artisan optimize:clear
php artisan optimize
```

> ⚠️ Jamais `migrate:fresh` en production (bloqué par le code, même avec `--force`) ni `db:seed`
> sans `--class=RolesAndPermissionsSeeder`. Règles complètes :
> [docs/deploiement/hostinger.md](docs/deploiement/hostinger.md#règles-à-respecter).

## Contribuer

- Flux des branches : `dev → pre-prod → production → main`, contrôlé par la CI.
- La documentation concernée est mise à jour dans la même pull request que le code.
- Aucun mot de passe, jeton ni clé dans le dépôt.
