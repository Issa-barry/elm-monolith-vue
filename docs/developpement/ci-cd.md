# Intégration continue et déploiement (GitHub Actions)

> **Statut** : en place · **Dernière vérification** : 15/11/2026, lecture des six fichiers de
> `.github/workflows/` et des règles de protection des branches (rulesets GitHub)
>
> Flux des branches, versions et conventions de commit : [workflow-git.md](workflow-git.md).
> Déroulé détaillé d'un déploiement Hostinger : [hostinger.md](../deploiement/hostinger.md).

## Vue d'ensemble

```
dev ──PR──▶ pre-prod ──PR──▶ production ──PR──▶ main
             │                 │
             │ push            │ push
             ▼                 ▼
   deploy-hostinger-     auto-release ──(succès)──▶ deploy-hostinger-admin
   formation             (tag + release)            (fello.eau-la-maman.com)
   (formation.eau-la-maman.com)
```

Trois familles de workflows, à ne pas confondre :

| Famille | Workflows | Déclencheur |
|---|---|---|
| **Contrôles** | `lint.yml`, `tests.yml`, `branch-flow.yml` | ouverture ou mise à jour d'une **pull request** |
| **Version** | `auto-release.yml` | **push** sur `production` (donc fusion de `pre-prod → production`) |
| **Déploiement** | `deploy-hostinger-formation.yml`, `deploy-hostinger-admin.yml` | push sur `pre-prod` ; fin réussie de `auto-release` |

Un push sur `dev` ou sur `main` ne lance **aucun** workflow.

## Les contrôles par pull request

| Pull request vers | `branch-flow` | `lint` (`quality`) | `tests` : `PHPUnit` | `tests` : E2E |
|---|---|---|---|---|
| `dev` | — | ✅ | — | — |
| `pre-prod` | ✅ | ✅ | ✅ | suite complète (4 shards) |
| `production` | ✅ | ✅ | ✅ | smoke |
| `main` | ✅ | ✅ | ✅ | — |

### Contrôles obligatoires pour fusionner

D'après les rulesets GitHub actifs au 15/11/2026 (toutes exigent une pull request, interdisent la
suppression de la branche et les push forcés, sans approbation obligatoire) :

| Branche protégée | Contrôles exigés |
|---|---|
| `pre-prod` | `quality`, `PHPUnit` |
| `production` | `quality`, `PHPUnit`, `E2E smoke` |
| `main` | `quality`, `PHPUnit` |
| `dev` | aucune protection |

> ⚠️ Constats, sans modification : `enforce-flow` (`branch-flow.yml`) et `E2E full` ne sont **pas**
> exigés. Une pull request vers `pre-prod` peut donc être fusionnée même si la suite E2E complète
> ou le contrôle du flux échoue. À décider : les ajouter aux contrôles exigés de « Protection
> pre-prod » (et `enforce-flow` aux deux autres).

### `branch-flow.yml`

Job `enforce-flow`. Refuse une pull request dont la branche source n'est pas la précédente du flux :
seulement `dev → pre-prod`, `pre-prod → production`, `production → main`.

### `lint.yml`

Job `quality`, sur toutes les pull requests. PHP 8.4, Node 22. Étapes, dans l'ordre :

1. `composer install` (sans scripts) ; copie de `.env.example`, clé, migration d'une base SQLite
   (nécessaire à l'analyse OpenAPI).
2. `php artisan wayfinder:generate --with-form` (les fichiers Wayfinder ne sont pas versionnés).
3. `npm ci`.
4. **Pint** : `vendor/bin/pint --test`.
5. **OpenAPI** : `scramble:analyze` puis `scramble:export` des API `default` et `vitrine` ; échoue si
   `docs/openapi/*.json` diffère de la version commitée. Correction : `composer openapi:export`
   puis commiter les fichiers.
6. **Prettier** : `npm run format:check`.
7. **ESLint** : `npx eslint . --max-warnings 0`.
8. **TypeScript** : `npm run typecheck`.
9. **Vitest** : `npm run test:unit:frontend`.
10. **Standards d'interface** : `node scripts/check-ui-standards.mjs`
    ([standards.md](standards.md#les-contrôles-automatiques)).

### `tests.yml`

Sur les pull requests vers `pre-prod`, `production` et `main`.

**Job `PHPUnit`**

1. Calcule une empreinte du code testable (tout sauf `.github/`, `docs/`, `README*`, `CHANGELOG*`
   et `*.md`). Si elle figure dans la variable de dépôt `PHPUNIT_PASSED_FINGERPRINTS`, PHPUnit est
   **sauté** : ce code a déjà été validé à l'étape précédente du flux.
2. Sinon : `npm ci`, `composer install`, `.env.example`, clé, `npm run build` (le manifeste Vite est
   nécessaire à certaines pages rendues dans les tests).
3. `php artisan test --parallel --processes=4`. En cas d'échec, les tests en échec sont **rejoués en
   série** : seul un échec confirmé en série (ou un plantage sans test identifiable) fait échouer le
   job. Raison : quelques tests ne sont pas isolés en parallèle ([tests.md](tests.md#phpunit)).
4. Enregistre l'empreinte validée (30 dernières conservées), avec le jeton `PHPUNIT_FP_PAT`. Si ce
   jeton expire, seule la déduplication est perdue : l'étape ne bloque pas.

**Job `Detect non-doc changes`** : les E2E ne tournent que si la pull request modifie au moins un
fichier hors `README.md`, `docs/**` et `*.md`.

**Job `E2E full (shard n/4)`** (pull request vers `pre-prod`) : quatre machines en parallèle
(`ubuntu-24.04`, figée car Playwright 1.58 ne prend pas en charge l'image suivante), chacune avec
son MySQL et sa base `elm_monolithe_e2e` ; migration et seeders (`DatabaseSeeder`,
`ElmV2DemoSeeder`), `npm run e2e:build`, puis `npx playwright test --shard=n/4 --workers=1`.
Délai maximal 45 minutes. Le job **`E2E full`** agrège les quatre résultats en un statut unique
(réussi aussi quand les E2E sont sautés).

**Job `E2E smoke`** (pull request vers `production`) : même préparation, puis seulement
`tests/e2e/smoke.spec.ts`. Délai maximal 20 minutes.

Les rapports Playwright sont conservés 7 jours dans les artefacts du run.

## La version : `auto-release.yml`

Sur push de `production` (et lancement manuel). Lit la pull request fusionnée vers `production`
pour choisir le niveau (étiquettes `release:*`, titre `feat…` ou `breaking` : voir
[workflow-git.md](workflow-git.md#versions)), crée le tag annoté suivant, puis une GitHub Release
avec notes générées. Si le commit porte déjà un tag de version, il est réutilisé ; une release
existante n'est pas recréée.

## Les déploiements

| | `deploy-hostinger-formation.yml` | `deploy-hostinger-admin.yml` |
|---|---|---|
| Cible | formation.eau-la-maman.com | fello.eau-la-maman.com |
| Déclencheur | push sur `pre-prod` | fin **réussie** de `auto-release` sur `production` |
| Environnement GitHub (secrets) | `formation_elm` | `production_elm` |
| Exécutions simultanées | une à la fois (`formation-deploy`) | une à la fois (`production-admin-deploy`) |
| Lancement manuel | oui | oui |

Les étapes sont identiques ([hostinger.md](../deploiement/hostinger.md#ce-que-fait-le-déploiement-automatique)).
Les tests ne sont **pas** relancés au déploiement : ils ont tourné sur la pull request.

Particularités de `deploy-hostinger-admin.yml` :

- Déclenché par `workflow_run` : GitHub exécute alors la version du fichier présente sur la branche
  par défaut du dépôt (**`main`**). Une modification de ce workflow ne prend effet qu'une fois
  arrivée sur `main`.
- En lancement manuel, il déploie la branche choisie dans « Run workflow » (par défaut `main`) :
  choisir **`production`**.

## Secrets et variables

Noms seulement ; les valeurs sont dans GitHub (Settings → Secrets and variables, ou dans chaque
environnement).

| Nom | Type | Utilisé par |
|---|---|---|
| `HOSTINGER_ADMIN_HOST`, `_SSH_PORT`, `_SSH_USER`, `_SSH_PRIVATE_KEY`, `_DEPLOY_PATH` | secrets de l'environnement `production_elm` | `deploy-hostinger-admin.yml` |
| `HOSTINGER_FORMATION_HOST`, `_SSH_PORT`, `_SSH_USER`, `_SSH_PRIVATE_KEY`, `_DEPLOY_PATH` | secrets de l'environnement `formation_elm` | `deploy-hostinger-formation.yml` |
| `PHPUNIT_FP_PAT` | secret de dépôt (jeton à droits « Variables: write ») | `tests.yml` |
| `PHPUNIT_PASSED_FINGERPRINTS` | variable de dépôt | `tests.yml` |
| `GITHUB_TOKEN` | fourni par GitHub | `auto-release.yml` (tags, release) |

## Hors GitHub Actions

- **SonarCloud** : analyse les pull requests via l'application GitHub SonarQube Cloud
  (`sonar-project.properties`), pas via un workflow. Son résultat n'est pas exigé pour fusionner.
- **Dependabot** et autres automatisations : aucune configuration dans le dépôt.

## Reproduire un contrôle en local

| Contrôle CI | Commande locale |
|---|---|
| Pint | `vendor/bin/pint --test` |
| OpenAPI | `composer openapi:export`, puis `git diff docs/openapi/` |
| Prettier, ESLint, TypeScript | `npm run format:check`, `npx eslint . --max-warnings 0`, `npm run typecheck` |
| Vitest | `npm run test:unit:frontend` |
| Standards d'interface | `npm run lint:standards` |
| PHPUnit | `php artisan test --parallel --processes=4` |
| E2E | `npm run e2e` ([tests.md](tests.md#playwright-e2e)) |
