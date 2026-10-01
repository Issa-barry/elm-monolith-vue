# ADR 0011 — Les permissions des rôles système ne sont plus écrasées au déploiement

- **Date** : 2026-09-28
- **Statut** : accepté
- **Périmètre** : Rôles & Permissions (`/backoffice/roles`), `RolesAndPermissionsSeeder`, pipelines
  de déploiement formation et production

## Contexte

Les pipelines `deploy-hostinger-formation.yml` et `deploy-hostinger-admin.yml` lancent
`db:seed --class=RolesAndPermissionsSeeder --force` à **chaque** déploiement. Ce seeder faisait
`syncPermissions([...])` sur les rôles système `admin_entreprise`, `manager`, `commerciale` et
`comptable` : la matrice codée en dur **remplaçait** entièrement les permissions du rôle.

Or ces rôles sont configurables dans `/backoffice/roles` (seul `super_admin` est verrouillé). Toute
permission cochée à la main disparaissait donc silencieusement au déploiement suivant. Constaté en
formation et en production le 28/09/2026 : les permissions « démarrer / valider le chargement »
données au rôle Commerciale disparaissaient « quelque temps après » l'enregistrement.

## Décision

1. **La matrice par défaut d'un rôle système configurable n'est posée qu'à sa création**
   (`$role->wasRecentlyCreated`). Sur une instance existante, le seeder ne touche plus à ses
   permissions — `/backoffice/roles` fait foi.
2. **`super_admin` reste resynchronisé** sur `Permission::all()` à chaque exécution : il est
   verrouillé et doit recevoir toute nouvelle permission du catalogue.
3. **Accorder une nouvelle permission à des rôles existants passe par une migration de données
   additive et idempotente** (jamais de retrait), sur le modèle de
   `2026_09_13_140136_backfill_ventes_workflow_permissions`. Ajouter une permission à une matrice du
   seeder ne suffit plus : sans migration, seules les installations neuves la reçoivent.
4. **Rattrapage unique** : `2026_09_28_100000_backfill_matrices_roles_systeme` ajoute aux rôles
   système les permissions entrées dans leurs matrices depuis la dernière mise en production (août
   2026), qui comptaient encore sur l'ancienne resynchronisation.

## Conséquences

- Les permissions déjà écrasées avant ce correctif **ne sont pas restaurées** : elles doivent être
  recochées une dernière fois dans `/backoffice/roles` sur formation et production après
  déploiement.
- Sur formation, la migration de rattrapage peut rajouter une permission de la liste qu'un
  administrateur aurait décochée depuis le dernier déploiement (elle ne s'exécute qu'une fois).
- `BackfillMatricesRolesSystemeTest` vérifie que chaque permission rattrapée figure bien dans la
  matrice par défaut du seeder : une instance existante et une instance neuve ne divergent pas.
- Non-régression : `ProductionDeploySeedingTest` (permissions configurées conservées après
  redéploiement, matrice par défaut sur installation neuve, super_admin complet).
