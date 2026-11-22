# Déploiement sur Hostinger

> **Statut** : en place (hébergement actuel) · **Dernière vérification** : 22/11/2026, d'après les
> workflows `deploy-hostinger-admin.yml` et `deploy-hostinger-formation.yml`, et hPanel (cron,
> configuration PHP) sur les deux sites
>
> Remplace `DEPLOY-HOSTINGER-CICD.md` et les sections « Déploiement » de l'ancien README. Un
> passage sur VPS est envisagé : tant qu'il n'est pas fait, cette procédure reste la référence.

## Les environnements

| Environnement | Adresse | Branche | Workflow | Environnement GitHub |
|---|---|---|---|---|
| Production (back-office) | fello.eau-la-maman.com | `production` | `deploy-hostinger-admin.yml`, lancé après `auto-release` | `production_elm` |
| Recette / formation | formation.eau-la-maman.com | `pre-prod` | `deploy-hostinger-formation.yml`, lancé à chaque push | `formation_elm` |

Chaque environnement est un site Hostinger indépendant (accès SSH et base de données séparés). Le
flux des branches est décrit dans [workflow-git.md](../developpement/workflow-git.md).

## Ce que fait le déploiement automatique

**Sur GitHub Actions** (PHP 8.4, Node 22) :

1. Écrit la dernière version taguée dans `VERSION` et la date dans `RELEASED_AT`.
2. `composer install --no-dev --optimize-autoloader`, puis `npm ci` et `npm run build`. Le
   déploiement échoue si `public/build/manifest.json` est absent.
3. Met le site en maintenance (`php artisan down`, page `errors.503`).
4. Envoie les fichiers par `rsync --delete`. Ne sont **jamais** envoyés : `.git/`, `.github/`,
   `node_modules/`, `vendor/`, `tests/`, `playwright-report/`, `test-results/`, `storage/`, `.env`.
   Sont **préservés** sur le serveur : `.well-known/`, `.user.ini`, `.htpasswd`.

**Sur le serveur**, avec le premier binaire PHP 8.4 (ou plus récent) trouvé :

```bash
mkdir -p storage/framework/views storage/framework/cache storage/framework/sessions \
         storage/framework/testing storage/logs storage/fonts
chmod -R 775 storage bootstrap/cache
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
php artisan migrate --force
php artisan db:seed --class=RolesAndPermissionsSeeder --force
php artisan optimize:clear
php artisan optimize
```

Puis il crée le lien `public/storage → storage/app/public`. Si l'hébergeur refuse le lien
symbolique, il copie les fichiers à la place. Enfin `php artisan up` (trois tentatives) : le site
ne reste jamais bloqué en maintenance.

Le fichier `.htaccess` à la racine renvoie toutes les requêtes vers `public/` : les autres dossiers
envoyés ne sont pas accessibles depuis le web.

### Lancer un déploiement à la main

GitHub → **Actions** → `deploy-hostinger-admin` ou `deploy-hostinger-formation` → **Run workflow**.

### Secrets GitHub

Définis dans chaque environnement GitHub (`production_elm`, `formation_elm`), avec le préfixe
`HOSTINGER_ADMIN_` ou `HOSTINGER_FORMATION_` :

| Suffixe | Contenu |
|---|---|
| `HOST` | Adresse SSH du serveur |
| `SSH_PORT` | Port SSH |
| `SSH_USER` | Utilisateur SSH |
| `SSH_PRIVATE_KEY` | Clé privée SSH (format OpenSSH) |
| `DEPLOY_PATH` | Dossier de l'application sur le serveur |

## Premier déploiement d'un site (une seule fois)

> À revérifier lors du prochain premier déploiement : procédure reprise de l'ancien README.

Le déploiement ne fait que de l'**infrastructure** : migrations et données globales (rôles et
permissions). Il ne crée jamais d'organisation, de site ni d'utilisateur métier.

1. Créer le fichier `.env` sur le serveur à partir de `.env.production.example` (production) ou
   `.env.preprod.example` (formation). Définir `APP_INSTALL_TOKEN` : la clé qui protège
   l'assistant `/install`. Elle est facultative en mode `on_premise`, mais à définir sur un serveur
   accessible depuis Internet.
2. Vérifier que `storage/` et `bootstrap/cache/` sont accessibles en écriture.
3. En SSH, dans le dossier de l'application :

   ```bash
   php artisan key:generate
   php artisan migrate --force
   php artisan db:seed --class=RolesAndPermissionsSeeder --force
   ln -s "$PWD/storage/app/public" "$PWD/public/storage" || true
   php artisan optimize:clear
   php artisan optimize
   ```

   Si la commande `php` n'est pas en 8.4, utiliser le binaire complet (par exemple
   `/opt/alt/php84/usr/bin/php`).
4. Ouvrir `https://<domaine>/install` dans un navigateur et suivre l'assistant (Entreprise, Super
   Admin, Résumé). C'est lui qui crée l'organisation ; le déploiement automatique ne lance jamais
   cette étape.

**Après l'installation** :

- En mode `on_premise`, `/install` se ferme : la page redirige vers la connexion, les autres
  actions de l'assistant répondent 404.
- Aucun site n'est créé par l'assistant : le premier site se configure à la première connexion
  (onboarding), les suivants depuis l'application.
- Le catalogue de départ (catégories, options, types de véhicule) est créé automatiquement ; les
  produits restent à créer.
- Le mot de passe du Super Admin est saisi par la personne qui installe : il n'est jamais généré,
  affiché ni conservé en clair.

**Sans navigateur**, `php artisan app:install` fait la même chose en ligne de commande (même
service `InstallationService`). Si une organisation du même nom existe déjà, elle est réutilisée
au lieu d'être recréée.

## Tâches planifiées et files d'attente

Les tâches planifiées (dont le contrôle horaire des encaissements, voir
[controle-encaissements.md](../controle-encaissements.md)) exigent une tâche cron par site, chaque
minute. hPanel → **Avancé** → **Tâches Cron**, type **PHP** :

| Site | Fréquence | Commande |
|---|---|---|
| fello (production) | `* * * * *` | `domains/fello.eau-la-maman.com/public_html/artisan schedule:run` |
| formation | `* * * * *` | `domains/formation.eau-la-maman.com/public_html/artisan schedule:run` |

État constaté le 22/11/2026 : ces deux lignes existent et répondent « No scheduled commands are
ready to run » entre deux tâches, ce qui est le fonctionnement normal.

Pièges constatés le 22/11/2026 :

- **La liste des tâches cron est commune au compte Hostinger** : fello et formation affichent les
  mêmes lignes. Ne jamais supprimer une ligne depuis un site en pensant qu'elle ne concerne que
  lui.
- **En type PHP, pas de redirection** : hPanel passe la commande à `artisan` sans shell, donc
  `>> fichier 2>&1` arrive comme argument et la commande échoue (« No arguments expected for
  "schedule:run" command, got ">>" »). C'est ce qui empêchait le planificateur de production de
  tourner avant le 22/11/2026. Pour rediriger la sortie, utiliser le type **Personnalisé** avec le
  binaire PHP complet.
- hPanel n'alerte pas en cas d'échec : seul **Afficher le résultat** montre la sortie, et
  uniquement celle de la dernière exécution. L'écran Contrôle des encaissements signale
  l'absence de contrôle planifié depuis 2 h 30.

L'hébergement mutualisé n'offre **pas de worker permanent** pour les files d'attente. **À vérifier
dans hPanel** : l'existence d'une tâche cron pour la file d'attente (aucune n'est en place au
22/11/2026).

Pour cette raison, l'import flotte (Paramètres → Import en masse) est traité pendant la requête,
limité à 500 lignes (`ImportFlotteParser::MAX_LIGNES`). Un import échoué peut être relancé depuis
sa fiche sans double création : il s'exécute dans une transaction.

## Version PHP du serveur

Les deux sites sont en **PHP 8.4** depuis le 22/11/2026 (hPanel → **Avancé** → **Configuration
PHP**), comme la CI et le déploiement. Le déploiement utilise `/opt/alt/php84/usr/bin/php`.

Changer de version PHP dans hPanel a deux effets à corriger aussitôt :

1. **Les options PHP reviennent aux valeurs par défaut** (OPcache, `memory_limit`,
   `max_execution_time`, `max_input_vars`…). Les noter avant le changement et les ressaisir. Les
   extensions, elles, sont conservées.
2. **`proc_open` est désactivée par défaut en 8.4** (`disable_functions`). Le déploiement en a
   besoin (`composer install` lance `@php artisan package:discover`, qui échoue avec « The Process
   class relies on proc_open »), ainsi que le planificateur (`->command()` passe par Symfony
   Process). La faire retirer de `disable_functions` par le support Hostinger, site par site, en
   gardant les autres restrictions. Fait pour formation et fello le 22/11/2026.

Vérifier ensuite : un déploiement relancé à la main passe, `/up` répond 200, et l'écran Contrôle
des encaissements affiche un contrôle planifié dans l'heure.

La traduction automatique de Chrome casse les listes déroulantes de hPanel : la désactiver avant
de modifier la configuration.

## Règles à respecter

- **Jamais** `migrate:fresh` en production : bloqué par le code
  (`DB::prohibitDestructiveCommands` dans `AppServiceProvider`), même avec `--force`. Utiliser
  `migrate --force`.
- **Jamais** `db:seed` sans `--class=RolesAndPermissionsSeeder` : le seeder par défaut crée des
  données fictives.
- **Ne jamais renommer, découper ni supprimer une migration déjà déployée** : sur une base qui a
  déjà joué l'ancien nom, le nouveau est rejoué et échoue (`Duplicate column name`). Ajouter une
  nouvelle migration. Si un renommage est inévitable, protéger `up()` et `down()` avec
  `Schema::hasColumn` / `Schema::hasTable` (cas de `add_reference_paiement_to_encaissements_ventes_table`,
  déploiement formation du 20/09/2026).
- `rsync --delete` supprime sur le serveur tout fichier supprimé du dépôt, sauf les exclusions
  ci-dessus.
- Les fichiers front sont compilés par le déploiement : `public/build` n'est pas versionné et ne
  doit pas être commité.

## Remettre la formation à zéro (comptes seulement)

> Procédure reprise de l'ancien README, à vérifier avant usage. **Destructive** : efface tous les
> comptes utilisateurs de l'organisation.

1. Déployer sur `pre-prod` le code qui contient la commande voulue.
2. En SSH sur le serveur de formation :

   ```bash
   php artisan accounts:purge --organization=elm
   ```

   La commande affiche l'instance visée ; vérifier l'adresse et l'environnement, puis taper
   `SUPPRIMER`. Elle efface les comptes, invitations, rattachements aux sites et rôles, sans
   toucher au reste.
3. Recréer le premier compte : `php artisan app:install`, puis saisir exactement le nom de
   l'organisation existante (« Eau la maman »). L'organisation, son catalogue et ses sites sont
   réutilisés.
4. Inviter ensuite les comptes de recette depuis l'application.

## Voir aussi

- Accès de démonstration : [demonstration.md](../installation/demonstration.md)
- Vue d'ensemble : [vue-ensemble.md](../architecture/vue-ensemble.md)
