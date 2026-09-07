# Validation des dépenses

Qui peut valider une dépense soumise, sur quel périmètre d'agences, et jusqu'à quel montant.
Configuré dans **Paramètres → Validation des dépenses** (`settings/DepenseParametrage.vue`),
une ligne par `(organization_id, role_name)` dans la table `droit_creation_depenses` (nom
historique — cette table porte aussi le droit de création de dépense, hors périmètre de ce doc).

## Règles métier (IDs)

- **DEPVAL-001** — Seul **Super Admin** valide n'importe quelle dépense de l'organisation, sans
  limite de montant ni d'agence (bypass total, ne passe jamais par `droit_creation_depenses`).
  **Admin Entreprise n'a plus aucun bypass automatique depuis le 2026-09-06** (avant cette date,
  seul le montant lui était appliqué — décision produit du 04/09/2026 — mais le RBAC général et
  le périmètre d'agences restaient bypassés via `User::isAdmin()`, ce qui rendait la
  configuration de `droit_creation_depenses` sans effet réel pour ce rôle sur ces deux
  dimensions) : il est désormais soumis à `droit_creation_depenses` sur **toute** la surface de
  `DroitCreationDepenseService`, comme n'importe quel autre rôle.
- **DEPVAL-002** — Pour Admin Entreprise et tout rôle non-Super-Admin, valider une dépense
  soumise requiert cumulativement : permission `depenses.update` **+** une ligne
  `peut_valider = true` pour ce rôle **+** la dépense sur une agence dans le périmètre du rôle
  (`perimetre`/`sites`) **+** le montant de la dépense `<= plafond_validation` du rôle. Créer une
  dépense requiert de façon symétrique `is_actif = true` **+** le même périmètre d'agences (cf.
  DEPVAL-007).
- **DEPVAL-003** — Le plafond est **porté par le rôle**, jamais par l'utilisateur individuellement.
  Deux utilisateurs avec le même rôle ont toujours le même plafond. Ceci vaut aussi pour Admin
  Entreprise : sa ligne `droit_creation_depenses` (`role_name = 'admin_entreprise'`) est une ligne
  ordinaire, configurée par un admin depuis `/settings/depenses` exactement comme celle de
  `manager`/`commerciale`/`comptable` — plus aucun forçage serveur depuis le 2026-09-06.
- **DEPVAL-004** — Un plafond non configuré (`plafond_validation = NULL`) — y compris pour Admin
  Entreprise tant qu'aucune ligne n'existe pour son rôle — est traité comme **0 GNF**
  (deny-by-default), jamais interprété comme « illimité ». Le formulaire de paramétrage rend le
  plafond obligatoire pour tout rôle dont `peut_valider` est actif (Admin Entreprise inclus) pour
  éviter ce cas en pratique ; le backend applique quand même le repli à 0 en garde-fou (appel
  direct API, ligne de données incomplète).
- **DEPVAL-007** — `is_actif` (droit de **créer** une dépense, distinct de `peut_valider`) a été
  ajouté au formulaire de paramétrage le 2026-09-06 — jusque-là cette colonne n'était écrite par
  aucun code applicatif alors que `peutCreer()`/`peutCreerSurSite()` en dépendent depuis toujours ;
  seul le bypass `isAdmin()` la rendait sans conséquence pour Super Admin et Admin Entreprise.
  Elle partage `perimetre`/`sites` avec `peut_valider` (une seule notion de périmètre d'agences
  par rôle) : les deux droits ne sont remis à zéro pour ce périmètre que lorsque `is_actif` **et**
  `peut_valider` sont tous deux désactivés.
- **DEPVAL-005** — Égalité autorisée : `montant == plafond` → validation permise.
  `montant > plafond`, même de 1 GNF → refusée.
- **DEPVAL-006** — Le plafond ne bloque que l'**approbation** (`DepenseController::valider()`).
  Le **rejet** (`DepenseController::rejeter()`) reste possible même au-dessus du plafond du
  rôle : un validateur (Admin Entreprise inclus) doit toujours pouvoir renvoyer une dépense trop
  élevée pour lui, sans escalade obligatoire vers Super Admin.

## Champs

| Champ | Table | Rôle |
|---|---|---|
| `is_actif` | `droit_creation_depenses` | Le rôle peut-il **créer** des dépenses (avant tout critère d'agence). Aucun bypass, y compris pour `admin_entreprise` (cf. DEPVAL-007). |
| `peut_valider` | `droit_creation_depenses` | Le rôle peut-il **valider** des dépenses (avant tout critère de montant/agence). Aucun bypass, y compris pour `admin_entreprise`. |
| `perimetre` | `droit_creation_depenses` | `toutes_agences` \| `son_agence` \| `agences_selectionnees` — partagé par `is_actif` et `peut_valider` (une seule notion de périmètre d'agences par rôle). |
| `sites` | `droit_creation_depenses` | Liste d'IDs de site, uniquement quand `perimetre = agences_selectionnees`. |
| `plafond_validation` | `droit_creation_depenses` | Montant max validable par ce rôle, en GNF — y compris pour `admin_entreprise`. `NULL` = non configuré (traité comme 0, cf. DEPVAL-004). Ajouté par [`add_plafond_validation_to_droit_creation_depenses_table`](../database/migrations/2026_09_04_155136_add_plafond_validation_to_droit_creation_depenses_table.php). |

## Points d'application backend

Toute la logique passe par [`DroitCreationDepenseService`](../app/Services/DroitCreationDepenseService.php) —
jamais dupliquée côté frontend. Depuis le 2026-09-06, **seul `hasRole('super_admin')` bypass quoi
que ce soit** dans ce service — `admin_entreprise` va toujours chercher la vraie ligne
`droit_creation_depenses`, sur chacune des méthodes :

- `peutCreer()` / `peutCreerSurSite()` — droit de création + critère d'agence, basés sur
  `is_actif`.
- `peutValiderSurSite()` — critère d'agence pour la validation, basé sur `perimetre`/`sites`.
- `droitValidationPour()` — bypass (retourne `null` sans requête) **uniquement** pour
  `hasRole('super_admin')`. Pour tout autre rôle, va chercher la vraie ligne
  `droit_creation_depenses` (`peut_valider = true`).
- `peutValiderMontant()` — bypass **uniquement** pour `hasRole('super_admin')`. Sinon `false`
  sans droit, sinon `montant <= (plafond_validation ?? 0)`.
- `sitesAutorises()` — mêmes règles que `peutCreerSurSite()`, pour lister les sites autorisés.

Ces critères sont combinés à deux endroits :

1. [`DepenseController::valider()`](../app/Http/Controllers/DepenseController.php) — contrôle
   réel au moment de l'action, retourne `back()->withErrors(['montant' => ...])` avec un message
   explicite si le montant dépasse le plafond. C'est le seul point qui fait foi : un appel direct
   à `PATCH /depenses/{depense}/valider` sans passer par l'UI est bloqué de la même façon, y
   compris pour un compte Admin Entreprise sans plafond configuré.
2. `DepenseController::index()` (prop `can_valider` par ligne) — pilote uniquement l'affichage
   du bouton « Valider » dans la liste, sans valeur de sécurité.

Le critère de plafond n'a volontairement **pas** été ajouté dans `DepensePolicy::valider()` :
cette ability (toujours bypassée par `isAdmin()`, donc par Admin Entreprise) est aussi utilisée
pour autoriser `rejeter()` (cf. DEPVAL-006). L'y ajouter aurait bloqué le rejet des dépenses
au-dessus du plafond, ce qui n'est pas le comportement voulu — d'où un contrôle de montant séparé,
uniquement dans `valider()`. Cette policy reste hors du périmètre de la correction du 2026-09-06 :
seul `DroitCreationDepenseService` portait des bypass `isAdmin()` inutilement larges.

## Paramétrage (UI)

`settings/DepenseParametrage.vue` affiche deux colonnes indépendantes, « Peut créer » et « Peut
valider », suivies de « Plafond de validation » (liée uniquement à « Peut valider ») et « De
quelles agences ? » (le périmètre, partagé par les deux droits — visible dès que l'un des deux
est actif). **Seul Super Admin** affiche « Sans limite »/case verrouillée sur toutes les
colonnes : accès réellement illimité, sans ligne `droit_creation_depenses`. **Admin Entreprise**
est depuis le 2026-09-06 un rôle configurable comme les autres — plus aucune case verrouillée ni
message « accès automatique » : une organisation choisit explicitement s'il peut créer, valider,
avec quel plafond et sur quel périmètre, exactement comme pour `manager`/`commerciale`/
`comptable`. Désactiver un droit (création ou validation) sur un rôle efface son plafond
(validation) ; le périmètre d'agences n'est réinitialisé que lorsque création **et** validation
sont tous deux désactivés pour ce rôle.

## Provisioning par défaut d'admin_entreprise

Le retrait des bypass ci-dessus (2026-09-06) aurait, sans mesure de continuité, laissé toute
organisation sans droit configuré pour `admin_entreprise` — deny-by-default sur la création
**et** la validation. Deux mécanismes garantissent la continuité :

- **Nouvelle organisation** : [`InstallationService::install()`](../app/Services/InstallationService.php)
  crée désormais une ligne `droit_creation_depenses` par défaut pour `admin_entreprise`
  (`is_actif=true`, `peut_valider=true`, `perimetre=toutes_agences`, plafond `NULL` — à
  configurer explicitement, cf. DEPVAL-004) au moment du socle de l'organisation.
- **Organisation existante** : migration de données
  [`2026_09_06_214708_backfill_droit_creation_depense_admin_entreprise`](../database/migrations/2026_09_06_214708_backfill_droit_creation_depense_admin_entreprise.php) —
  complète `is_actif=true` sur la ligne `admin_entreprise` existante sans toucher à ses
  `peut_valider`/`perimetre`/`plafond_validation` déjà configurés, ou crée une ligne de
  continuité (mêmes valeurs par défaut que ci-dessus) si l'organisation n'en avait aucune.
