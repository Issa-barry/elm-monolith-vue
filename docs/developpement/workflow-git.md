# Workflow Git, intégration continue et versions

> **Statut** : en place · **Dernière vérification** : 15/11/2026 (lecture de `.github/workflows/`)

## Les branches

| Branche | Rôle | Déploiement automatique |
|---|---|---|
| `dev` | Branche de travail : toutes les évolutions y arrivent | Aucun |
| `pre-prod` | Recette | **formation.eau-la-maman.com** à chaque push (`deploy-hostinger-formation.yml`) |
| `production` | Production | **fello.eau-la-maman.com** : à chaque push, `auto-release.yml` crée la version, puis `deploy-hostinger-admin.yml` déploie |
| `main` | Référence finale, alignée sur la production | Aucun |

Les branches de travail ponctuelles (`fix/…`, `perf/…`, `contacts-…`) partent de `dev` et y
reviennent.

## Le flux obligatoire

```
dev  →  pre-prod  →  production  →  main
```

Le workflow `branch-flow.yml` refuse toute pull request qui ne respecte pas ce flux :

| Pull request vers | Seule branche source acceptée |
|---|---|
| `pre-prod` | `dev` |
| `production` | `pre-prod` |
| `main` | `production` |

## Contrôles lancés sur chaque pull request

| Pull request vers | `lint.yml` | `tests.yml` : PHPUnit | `tests.yml` : E2E Playwright |
|---|---|---|---|
| `dev` | ✅ | — | — |
| `pre-prod` | ✅ | ✅ | **Suite complète** (4 shards) |
| `production` | ✅ | ✅ | **Smoke** |
| `main` | ✅ | ✅ | — |

- `lint.yml` : Pint, cohérence de la documentation OpenAPI, Prettier, ESLint, vérification
  TypeScript, tests Vitest et contrôle des standards d'interface (`npm run lint:standards`).
- PHPUnit tourne en parallèle. Un code déjà validé à une étape précédente du flux n'est pas
  retesté : son empreinte est mémorisée dans la variable de dépôt `PHPUNIT_PASSED_FINGERPRINTS`.
- Les E2E ne tournent que si la pull request modifie autre chose que de la documentation.
- Contrôles **exigés** pour fusionner : `quality` et `PHPUnit` partout, plus `E2E smoke` vers
  `production`. `enforce-flow` et `E2E full` ne sont pas exigés.

Détail de chaque workflow : [ci-cd.md](ci-cd.md). Lancer les mêmes contrôles en local :
[tests.md](tests.md).

## Versions

Les versions suivent la forme `MAJEUR.MINEUR.CORRECTIF`, sans préfixe `v` (dernière au 15/11/2026 :
`1.3.188`).

À chaque push sur `production`, `auto-release.yml` calcule la version suivante à partir de la pull
request fusionnée (en pratique `pre-prod → production`), crée le tag et publie une GitHub Release :

| Version | Déclencheur sur la pull request |
|---|---|
| Majeure | étiquette `release:major` (ou `semver:major`, `major`), ou titre contenant `breaking` |
| Mineure | étiquette `release:minor` (ou `semver:minor`, `minor`), ou **titre** commençant par `feat:`, `feat(…)`, `feature:`, `feature(…)` |
| Correctif | étiquette `release:patch` (ou `semver:patch`, `patch`, `hotfix`), et par défaut |

En pratique, toutes les versions depuis `1.3.0` sont des correctifs : les pull requests vers
`production` ne portent ni étiquette ni titre `feat…`.

Le déploiement de production écrit ensuite cette version dans `VERSION` et la date dans
`RELEASED_AT`. Détails techniques : [.github/RELEASE_AUTOMATION.md](../../.github/RELEASE_AUTOMATION.md)
(ce fichier décrit encore un déclenchement sur `main` : le déclencheur réel est `production`).

## Messages de commit

**Constat (15/11/2026)** : une partie des commits suit la forme `type(portée): description`
(`feat`, `fix`, `test`, `ci`, `style`…), d'autres non (`mj`, `ml`, `maj`…).

**Règle à appliquer** :

- message en français, sous la forme `type(portée): description` ;
- types : `feat` (fonctionnalité), `fix` (correction), `refactor`, `perf`, `test`, `docs`, `ci`,
  `style`, `chore` ;
- exemple : `fix(rapports): total encaissé = somme des moyens de paiement`.

Le calcul de version lit le **titre de la pull request**, pas les commits : c'est surtout le titre
de la pull request vers `production` qui doit respecter cette forme.

## Avant de fusionner

- Les contrôles de la pull request sont verts.
- La documentation concernée est à jour **dans la même pull request** (case à cocher du modèle de
  pull request) ; une décision structurante a son ADR ([index](../adr/README.md)).
- Aucun secret (mot de passe, jeton, clé) dans le code ni dans la documentation.

Déploiement et premier déploiement d'un site : [hostinger.md](../deploiement/hostinger.md).
