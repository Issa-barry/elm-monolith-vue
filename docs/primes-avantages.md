# Primes et avantages — référentiel des types

Décision : [ADR 0038](adr/0038-referentiel-primes-et-avantages.md). Lot 2 de la conception RH ↔
Comptabilité ([paie.md](paie.md)), livré le 28/10/2026. Ce lot crée **le référentiel seul** :
l'attribution d'une prime à un salarié, avec ses dates d'effet, arrive au lot 3.

Écran : *Paramètres → RH → Primes et avantages* (`/backoffice/types-remuneration`).

## Un type

| Champ | Règle |
|---|---|
| Libellé | Obligatoire, 100 caractères, unique dans l'organisation (sans tenir compte de la casse) |
| Code | Unique dans l'organisation, types inactifs compris. Proposé depuis le libellé (« Prime de transport » → `PRIME_DE_TRANSPORT`, suffixe `_2`… si pris) ; saisi, il est normalisé (majuscules, chiffres, `_`, 30 caractères) |
| Description | Facultative (conditions d'attribution, observations) |
| Catégorie | Prime, indemnité, allocation, avantage en nature. Un **avantage en nature est valorisé** (valeur estimée, jamais versée dans le net à payer) ; les autres catégories sont versées |
| Mode de calcul | Montant fixe, ou pourcentage du salaire de base |
| Valeur par défaut | Facultative : montant (> 0) pour un montant fixe, taux (> 0, ≤ 100 %) pour un pourcentage. Une seule des deux est conservée, selon le mode |
| Périodicité | Mensuelle ou ponctuelle |
| Nature du montant | Fixe (fixé à l'attribution) ou variable (saisi chaque mois) |
| Proratisable, cumul autorisé | Cases à cocher, utilisées par le calcul à partir du lot 3 |
| Période d'application | Début et fin facultatifs ; fin ≥ début |
| Statut, auteurs, dates | Créé par, modifié par, validé par et le, dates de création et de modification |

## Règles métier

- **PRIME-001** — Un type créé est **« À valider »**. Il n'est attribuable qu'une fois validé.
- **PRIME-002** — Valider, fermer, désactiver et réactiver exigent `rh-types-remuneration.valider`,
  accordée par défaut au **seul super administrateur** et délégable dans *Rôles*. Le contrôle est
  fait par le serveur (`TypeRemunerationService`), pas seulement par l'écran : une requête directe
  sans ce droit est refusée (403). Il n'y a pas de règle « valideur ≠ auteur » : le super
  administrateur peut « Créer et valider » en une étape (deux entrées au journal).
- **PRIME-003** — Cycle : *À valider* → **Actif** → *Fermé aux nouvelles attributions* ou
  *Inactif* → réactivation vers *Actif* (même code, même identifiant). Toute autre transition est
  refusée (ex. fermer un type à valider, valider un type actif). Chaque transition se fait sous
  verrou du type : deux validations simultanées ne produisent qu'une validation.
- **PRIME-004** — **Attribuable** = statut *Actif* **et** date dans la période d'application
  (`TypeRemuneration::scopeAttribuables()` / `estAttribuable()`, utilisés par le lot 3). Un type à
  valider, fermé ou inactif ne l'est jamais.
- **PRIME-005** — Modification : un utilisateur avec `rh-types-remuneration.update` modifie un
  type **« À valider »**. Un type **déjà validé** (actif, fermé ou inactif) ne se modifie qu'avec
  le droit de validation, et reste dans son statut (décision du 28/10/2026). Un code vide conserve
  le code actuel.
- **PRIME-006** — Suppression (`rh-types-remuneration.delete`) : possible **tant que le type n'a
  jamais été attribué** (décision du 28/10/2026) ; sinon il se désactive. Un type déjà validé exige
  en plus le droit de validation, comme sa modification. Jusqu'au lot 3, aucun type n'est attribué.
- **PRIME-007** — Un type déjà attribué garde ses caractéristiques de calcul (code, catégorie, mode
  de calcul, périodicité, nature du montant) : il faut créer un nouveau type. Libellé, description,
  valeurs par défaut et période restent modifiables (contrôle effectif dès le lot 3).
- **PRIME-008** — Fermer et désactiver n'ont, à ce stade, aucun effet sur des salariés (aucune
  attribution n'existe). Au lot 3 : *Fermé* maintient les bénéficiaires actuels ; *Inactif* met fin
  aux attributions à une date choisie, au plus tôt le 1er mois de paie non validé (conception D.3).
- **PRIME-009** — Organisation : chaque action vérifie explicitement l'organisation du type, y
  compris pour le super administrateur (le `Gate::before` ne la vérifie pas). Le même code peut
  exister dans deux organisations.
- **PRIME-010** — Journal (`AuditLogService`, module `rh_types_remuneration`) : création,
  modification (champs modifiés seulement), validation, changement de statut, suppression.
  Consultable par le menu ⋮ → *Historique* de la ligne.

## Permissions

| Action | Permission | Par défaut |
|---|---|---|
| Consulter la liste et l'historique | `rh-types-remuneration.read` | super_admin, admin_entreprise |
| Créer | `rh-types-remuneration.create` | super_admin, admin_entreprise |
| Modifier une proposition à valider | `rh-types-remuneration.update` | super_admin, admin_entreprise |
| Supprimer une proposition jamais attribuée | `rh-types-remuneration.delete` | super_admin, admin_entreprise |
| Valider, fermer, désactiver, réactiver ; modifier ou supprimer un type validé | **`rh-types-remuneration.valider`** | **super_admin seul** |

Rattrapage des rôles existants : migration `2026_10_28_100100_backfill_permissions_types_remuneration`
(ADR 0011), additive et idempotente. Les boutons de l'écran suivent ces permissions (règle 3 des
standards UI) ; le serveur revérifie tout.

## Hors de ce lot

- Attribution aux salariés, dates d'effet, désactivation globale datée : lot 3.
- Type système « Salaire de base » : lot 3, avec la reprise du salaire des contrats (le salaire de
  base reste porté par le contrat, sans date d'effet).
- Traitement social, fiscal et compte comptable d'un type : renseignés par la Comptabilité, au lot
  où la paie lira le référentiel (lots 5-6).
- Import et export Excel du référentiel.

## Fichiers clés

- `app/Models/TypeRemuneration.php`, `app/Services/Rh/TypeRemunerationService.php`,
  `app/Policies/TypeRemunerationPolicy.php`, `app/Http/Requests/TypeRemunerationRequest.php`.
- Énumérations : `StatutTypeRemuneration`, `CategorieTypeRemuneration`, `ModeCalculRemuneration`,
  `PeriodiciteRemuneration`, `NatureMontantRemuneration`.
- `app/Http/Controllers/Rh/TypesRemuneration/` — contrôleurs mono-action.
- `resources/js/pages/TypesRemuneration/Index.vue`.
- Tests : `tests/Feature/TypeRemunerationTest.php`.
