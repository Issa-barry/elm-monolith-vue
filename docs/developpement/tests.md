# Tests

> **Statut** : en place · **Dernière vérification** : 15/11/2026
>
> Commandes reprises de `package.json`, `composer.json`, `phpunit.xml` et `playwright.config.ts`.
> PHPUnit (par fichier) et Vitest ont été exécutés le 15/11/2026 ; les commandes E2E n'ont pas été
> rejouées pour ce document. Ce que la CI lance, et quand : [ci-cd.md](ci-cd.md).

| Suite | Outil | Où | Lancée en CI |
|---|---|---|---|
| Back-end (Feature, Unit) | PHPUnit | `tests/Feature`, `tests/Unit` | pull requests vers `pre-prod`, `production`, `main` |
| Front-end (composants, pages) | Vitest | `resources/js/**/__tests__/*.spec.ts` | toutes les pull requests (`lint.yml`) |
| Parcours complets | Playwright | `tests/e2e/*.spec.ts` | complets vers `pre-prod`, smoke vers `production` |

Contrôles qualité associés : [workflow-git.md](workflow-git.md#contrôles-lancés-sur-chaque-pull-request).

## PHPUnit

Les tests tournent sur **SQLite en mémoire** (`.env.testing`, `phpunit.xml`). Un garde-fou dans
`bootstrap/app.php` arrête le lancement si la configuration pointe vers une autre base : un cache de
configuration périmé a déjà vidé une base de développement.

```bash
# Tous les tests (vide d'abord le cache de configuration)
npm run test

# Un fichier
php artisan test tests/Feature/Rapports/RapportAgencesTest.php

# Un test précis
php artisan test tests/Feature/Rapports/RapportAgencesTest.php --filter=test_le_total_encaisse

# Comme la CI : en parallèle
php artisan test --parallel --processes=4

# Comme un shard de la CI (ici le 2e sur 4)
php scripts/ci/phpunit-shard.php 2 4 phpunit.shard.xml
php artisan test --parallel --processes=4 --configuration=phpunit.shard.xml
```

- `phpunit.xml` force `APP_DEBUG=false` et `INERTIA_SSR_ENABLED=false` : la page d'exception de
  debug et les tentatives de rendu SSR coûtaient plusieurs secondes par test concerné. Un test qui
  vérifie le message d'un `abort()` le lit en JSON (`postJson`, `getJson`…), pas dans la page
  d'erreur HTML.

- `composer test` fait la même chose que `npm run test`.
- La suite complète compte plusieurs milliers de tests et prend de longues minutes : en local,
  lancer d'abord les fichiers concernés.
- Si un test échoue sur une erreur de base de données inattendue, lancer `php artisan config:clear`.
- **Parallèle et isolation** : quelques tests (ex. `DepenseParametrageTest`) échouent seulement
  quand ils partagent un processus avec d'autres fichiers (état global non restauré). La CI rejoue
  donc en série les échecs du run parallèle avant de conclure ([ci-cd.md](ci-cd.md#testsyml)). En
  local, rejouer un échec isolé avec `--filter` avant de le considérer comme réel.
- Couverture de code (nécessite l'extension PHP `pcov`) :
  `php -d pcov.enabled=1 vendor/bin/phpunit --coverage-text`

### Tests de concurrence (MySQL)

Cinq fichiers `*ConcurrenceTest.php` (stock, factures fournisseur, compte Mobile Money, paiements
de fiche, solde des supports de trésorerie) prouvent le comportement avec plusieurs connexions MySQL réelles. Ils utilisent les connexions `mysql_testing` et
`mysql_testing_2` (`config/database.php`) sur une base dédiée, **`elm_monolithe_concurrency_test`**
par défaut (variables `DB_TESTING_*`, [configuration.md](../installation/configuration.md#base-de-données)),
jamais la base de développement.

Sans MySQL accessible ou sans cette base migrée, ils sont **ignorés** (`skipped`), pas en échec :
c'est le cas en CI. Pour les lancer en local, créer la base puis la migrer, par exemple avec
`php artisan migrate --database=mysql_testing` (commande non rejouée pour ce document).

Si cette base contient des restes d'anciens tests qui bloquent une migration, en utiliser une neuve
plutôt que de la vider : `DB_TESTING_CONCURRENCY_DATABASE=<nouvelle base>` (créée vide), puis
`migrate --database=mysql_testing` et le test, avec la même variable. Pour la remettre en état :
sauvegarde complète (`mysqldump`), vérification de la sauvegarde par restauration dans une base
temporaire, puis recréation de cette seule base et `migrate --database=mysql_testing`. Fait le
20/11/2026 : `FactureFournisseurConcurrenceTest` ne nettoie pas ses données, et ses commandes d'achat
orphelines bloquaient une migration.

Les tests de paiement préparent l'organisation comme en production avant les requêtes
simultanées : compteurs de numérotation des journaux (`AmorceNumerotationComptable`) et modules
enregistrés (`Feature::for($org)->loadAll()`). Sans cela, ils mesureraient le tout premier accès
d'une organisation neuve, et non le paiement.

`PaiementFicheConcurrenceTest` (ADR 0069) passe par le noyau HTTP, comme un navigateur : chaque
requête tourne dans un processus (`tests/Support/concurrence_paiement_fiche.php`) qui la retient
1,5 s juste avant d'écrire ou de supprimer le paiement. Sans verrou, l'autre requête agirait sur un
état périmé. `SoldeSupportConcurrenceTest` prouve de la même façon la garde de solde commune à
toutes les sorties d'argent (`garantirSoldeSuffisant()`), sans passer par un écran.

Deux limites d'attente distinctes, avec deux messages :

- **démarrage** (180 s) : chaque processus amorce tout le framework avant de se dire prêt. Un
  dépassement signale une machine trop chargée, pas un défaut : relancer ;
- **réponse** (90 s après le départ commun) : au-delà du délai d'attente de verrou de MySQL (50 s).
  Un verrou bloqué ou un interblocage remonte avant, comme une erreur de la requête (1205, 1213). Un
  dépassement signale donc un blocage réel.

`CONCURRENCE_TRACE=1` affiche, pour chaque scénario de `PaiementFicheConcurrenceTest`, la durée de
démarrage des processus et la durée de chaque requête.

## Vitest

```bash
# Toute la suite
npm run test:unit:frontend

# Un dossier ou un fichier
npx vitest run resources/js/pages/Rapports/
```

## Playwright (E2E)

Prérequis :

- un MySQL local sur `127.0.0.1:3306`, utilisateur `root` sans mot de passe (configuration Laragon
  par défaut) : le script crée la base `elm_monolithe_e2e` ;
- le fichier `.env.e2e` (versionné) ;
- le port **8080** libre : Playwright démarre lui-même `php artisan serve --port=8080 --env=e2e`
  (adresse modifiable avec la variable `E2E_BASE_URL`).

```bash
# Une seule fois : installer le navigateur
npm run e2e:install

# Toute la suite : remet la base e2e à zéro, recompile le front, lance Playwright
npm run e2e

# Mode visible ou interactif
npm run e2e:headed
npm run e2e:ui

# Rapport HTML de la dernière exécution
npm run e2e:report
```

`npm run e2e` recompile le front avec `APP_ENV=e2e` : les actions générées par Wayfinder
(`resources/js/actions/**`) contiennent l'adresse de `APP_URL`, figée à la compilation. Sans cette
recompilation, le front appelle le port de `.env` (ex. `:8000`) au lieu du serveur E2E (`:8080`),
et la connexion échoue.

Pour lancer un seul fichier sans passer par `npm run e2e`, préparer d'abord la base et le front :

```bash
npm run e2e:db:reset
npm run e2e:build
npx playwright test tests/e2e/smoke.spec.ts
```

> ⚠️ `npm run e2e:db:reset` lance `migrate:fresh --seed --env=e2e` : il **vide la base
> `elm_monolithe_e2e`**. Ne jamais le lancer avec une configuration qui pointerait vers une autre
> base.

En CI, deux scénarios existent : la **suite complète** (pull request vers `pre-prod`, répartie sur
4 machines avec `--shard`) et le **smoke** `tests/e2e/smoke.spec.ts` (pull request vers
`production`). Les tests d'un même job tournent avec `--workers=1` : ils partagent le cookie de
session préparé par `tests/e2e/global-setup.ts`. En local, garder aussi `--workers=1` si des tests
échouent de façon aléatoire.

Les comptes utilisés par les scénarios viennent du `DatabaseSeeder` et de `ElmV2DemoSeeder`
(organisation « Eau La Maman V2 Demo »), tous deux rejoués par `npm run e2e:db:reset`.

## Après une modification

Le tableau complet des vérifications avant livraison est dans
[standards.md](standards.md#b6-vérifications-avant-livraison). En résumé :

- Interface : `npm run lint:standards`, puis les tests Vitest de la page.
- Règle métier : les tests PHPUnit du domaine, et un test qui empêche le bug de revenir.
- Parcours utilisateur : le scénario Playwright concerné.
