# Validation des dépenses

Qui peut valider une dépense soumise, sur quel périmètre d'agences, et jusqu'à quel montant.
Configuré dans **Paramètres → Validation des dépenses** (`settings/DepenseParametrage.vue`),
une ligne par `(organization_id, role_name)` dans la table `droit_creation_depenses` (nom
historique — cette table porte aussi le droit de création de dépense, hors périmètre de ce doc).

## Règles métier (IDs)

- **DEPVAL-001** — Seul **Super Admin** valide n'importe quelle dépense de l'organisation, sans
  limite de montant ni d'agence (bypass total, ne passe jamais par `droit_creation_depenses`
  pour le montant). **Admin Entreprise garde son accès automatique pour le RBAC et le périmètre
  d'agences** (`User::isAdmin()` reste vrai pour les deux rôles sur ces deux dimensions,
  inchangé), **mais est soumis au plafond de montant comme n'importe quel autre rôle** — décision
  produit du 04/09/2026, en correction d'une première implémentation qui l'exemptait à tort.
- **DEPVAL-002** — Pour Admin Entreprise et tout rôle non-Super-Admin, valider une dépense
  soumise requiert cumulativement : permission `depenses.update` **+** (pour les rôles non-admin)
  une ligne `peut_valider = true` pour ce rôle **+** la dépense sur une agence dans le périmètre
  du rôle (`perimetre`/`sites`, bypass automatique pour Admin Entreprise) **+** le montant de la
  dépense `<= plafond_validation` du rôle.
- **DEPVAL-003** — Le plafond est **porté par le rôle**, jamais par l'utilisateur individuellement.
  Deux utilisateurs avec le même rôle ont toujours le même plafond. Ceci vaut aussi pour Admin
  Entreprise : sa ligne `droit_creation_depenses` (`role_name = 'admin_entreprise'`) porte un
  plafond obligatoire, sa case `peut_valider` étant forcée à `true` côté serveur
  (`DepenseParametrageController::updateDroits()`) indépendamment de ce qu'envoie le frontend —
  son accès reste automatique, seul le montant est désormais réellement contrôlé.
- **DEPVAL-004** — Un plafond non configuré (`plafond_validation = NULL`) — y compris pour Admin
  Entreprise tant qu'aucune ligne n'existe pour son rôle — est traité comme **0 GNF**
  (deny-by-default), jamais interprété comme « illimité ». Le formulaire de paramétrage rend le
  plafond obligatoire pour tout rôle actif (Admin Entreprise inclus) pour éviter ce cas en
  pratique ; le backend applique quand même le repli à 0 en garde-fou (appel direct API, ligne de
  données incomplète).
- **DEPVAL-005** — Égalité autorisée : `montant == plafond` → validation permise.
  `montant > plafond`, même de 1 GNF → refusée.
- **DEPVAL-006** — Le plafond ne bloque que l'**approbation** (`DepenseController::valider()`).
  Le **rejet** (`DepenseController::rejeter()`) reste possible même au-dessus du plafond du
  rôle : un validateur (Admin Entreprise inclus) doit toujours pouvoir renvoyer une dépense trop
  élevée pour lui, sans escalade obligatoire vers Super Admin.

## Champs

| Champ | Table | Rôle |
|---|---|---|
| `peut_valider` | `droit_creation_depenses` | Le rôle peut-il valider des dépenses (avant tout critère de montant/agence). Toujours `true` pour la ligne `admin_entreprise` (forcé côté serveur). |
| `perimetre` | `droit_creation_depenses` | `toutes_agences` \| `son_agence` \| `agences_selectionnees`. Sans effet réel pour Admin Entreprise (bypass `isAdmin()` dans `peutValiderSurSite()`), conservé à `toutes_agences` par cohérence. |
| `sites` | `droit_creation_depenses` | Liste d'IDs de site, uniquement quand `perimetre = agences_selectionnees`. |
| `plafond_validation` | `droit_creation_depenses` | Montant max validable par ce rôle, en GNF — y compris pour `admin_entreprise`. `NULL` = non configuré (traité comme 0, cf. DEPVAL-004). Ajouté par [`add_plafond_validation_to_droit_creation_depenses_table`](../database/migrations/2026_09_04_155136_add_plafond_validation_to_droit_creation_depenses_table.php). |

## Points d'application backend

Toute la logique passe par [`DroitCreationDepenseService`](../app/Services/DroitCreationDepenseService.php) —
jamais dupliquée côté frontend :

- `peutValiderSurSite()` — critère d'agence, **toujours** gouverné par `User::isAdmin()`
  (Super Admin et Admin Entreprise bypassent tous les deux, inchangé par cette correction).
- `droitValidationPour()` — bypass (retourne `null` sans requête) **uniquement** pour
  `hasRole('super_admin')`. Pour Admin Entreprise et les autres rôles, va chercher la vraie ligne
  `droit_creation_depenses` (`peut_valider = true`) — c'est ce qui permet au plafond d'Admin
  Entreprise d'être réellement lu.
- `peutValiderMontant()` — bypass **uniquement** pour `hasRole('super_admin')`. Sinon `false`
  sans droit, sinon `montant <= (plafond_validation ?? 0)`.

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
uniquement dans `valider()`.

## Paramétrage (UI)

`settings/DepenseParametrage.vue` affiche une colonne « Plafond de validation » entre
« Peut valider » et « De quelles agences ? ». **Seul Super Admin** affiche « Sans limite »
(texte, pas un montant, case verrouillée). **Admin Entreprise** garde ses cases « Peut valider »
et « De quelles agences ? » verrouillées (accès automatique inchangé) mais affiche désormais un
input GNF éditable pour le plafond, comme un rôle actif normal — avec le sous-titre « Accès
automatique, plafond de validation applicable » pour ne pas laisser croire à un accès illimité.
Les autres rôles affichent l'input uniquement quand `peut_valider` est coché, désactivé (`—`)
sinon. Désactiver `peut_valider` (rôles non-admin) efface immédiatement le plafond et le
périmètre d'agences saisis pour ce rôle.
