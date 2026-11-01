# Primes et avantages — référentiel et affectations aux salariés

Décisions : [ADR 0038](adr/0038-referentiel-primes-et-avantages.md) (référentiel, lot 2, 28/10/2026)
et [ADR 0039](adr/0039-affectations-primes-salaries.md) (affectations datées, lot 3, 29/10/2026).
Conception RH ↔ Comptabilité : [paie.md](paie.md). La transmission mensuelle qui reprend ces
affectations : [transmission-paie.md](transmission-paie.md).

Écrans :
- *Paramètres → RH → Primes et avantages* (`/backoffice/types-remuneration`) : le référentiel des types ;
- *RH → Primes des salariés* (`/backoffice/affectations-remuneration`) : les affectations ;
- fiche salarié, carte *Primes et avantages* (lien *Gérer* vers la liste filtrée sur le salarié).

Le **salaire de base reste porté par le contrat** : il n'est jamais une affectation de prime.

## Un type

| Champ | Règle |
|---|---|
| Libellé | Obligatoire, 100 caractères, unique dans l'organisation (sans tenir compte de la casse) |
| Code | Unique dans l'organisation, types inactifs compris. Proposé depuis le libellé (« Prime de transport » → `PRIME_DE_TRANSPORT`, suffixe `_2`… si pris) ; saisi, il est normalisé (majuscules, chiffres, `_`, 30 caractères) |
| Description | Facultative (conditions d'attribution, observations) |
| Catégorie | **Plus saisie depuis le 31/10/2026 (ADR 0046)** : tout type créé est une **prime**, versée en argent. La catégorie d'un type existant (indemnité, allocation, avantage en nature) est conservée et ne change jamais ; un **avantage en nature reste valorisé** (valeur estimée, jamais versée dans le net à payer), indemnités et allocations sont versées comme une prime |
| Mode de calcul | Montant fixe, ou pourcentage du salaire de base |
| Valeur par défaut | Facultative : montant (> 0) pour un montant fixe, taux (> 0, ≤ 100 %) pour un pourcentage. Proposée à l'attribution, jamais imposée |
| Périodicité | Mensuelle ou ponctuelle (un seul mois) |
| Nature du montant | Fixe (fixé à l'attribution) ou variable (saisi chaque mois) |
| Proratisable | Réduit au prorata des jours de droit dans le mois |
| Cumul autorisé | Un salarié peut recevoir plusieurs affectations simultanées du type |
| Période d'application | Début et fin facultatifs ; fin ≥ début |
| Statut, auteurs, dates | Créé par, modifié par, validé par et le, date de désactivation, dates de création et de modification |

## Formulaire simplifié (31/10/2026, ADR 0046)

Le responsable RH donne le **nom de la prime**, le mode de calcul, la périodicité, la valeur par
défaut et une description facultative. Code, nature du montant, période d'application, prorata et
cumul sont regroupés dans **Options avancées** (repliées, ouvertes d'office si l'une est en
erreur). Aucune catégorie n'est demandée ni acceptée par le serveur. Un type enregistré avant la
simplification affiche sa catégorie d'origine à la modification ; un avantage en nature porte le
repère « Avantage en nature · non versé » dans les listes. Le filtre par catégorie est retiré des
écrans Primes et avantages et Primes des salariés.

## Règles du référentiel

- **PRIME-001** — Un type créé est **« À valider »**. Il n'est attribuable qu'une fois validé.
- **PRIME-002** — Valider, fermer, désactiver et réactiver exigent `rh-types-remuneration.valider`,
  accordée par défaut au **seul super administrateur** et délégable dans *Rôles*. Le contrôle est
  fait par le serveur (`TypeRemunerationService`), pas seulement par l'écran : une requête directe
  sans ce droit est refusée (403). Il n'y a pas de règle « valideur ≠ auteur » : le super
  administrateur peut « Créer et valider » en une étape (deux entrées au journal).
- **PRIME-003** — Cycle : *À valider* → **Actif** → *Fermé aux nouvelles attributions* ou
  *Inactif* → réactivation vers *Actif* (même code, même identifiant). Toute autre transition est
  refusée. Chaque transition se fait sous verrou du type.
- **PRIME-004** — **Attribuable** = statut *Actif* **et** date dans la période d'application. Un
  type à valider, fermé ou inactif n'accepte aucune nouvelle attribution.
- **PRIME-005** — Modification : un utilisateur avec `rh-types-remuneration.update` modifie un
  type **« À valider »**. Un type **déjà validé** ne se modifie qu'avec le droit de validation, et
  reste dans son statut (décision du 28/10/2026). Un code vide conserve le code actuel.
- **PRIME-006** — Suppression (`rh-types-remuneration.delete`) : possible **tant que le type n'a
  jamais été attribué** (décision du 28/10/2026) — une affectation, même annulée, compte ; sinon il
  se désactive. Un type déjà validé exige en plus le droit de validation.
- **PRIME-007** — Un type déjà attribué garde ses caractéristiques de calcul (code, mode de calcul,
  périodicité, nature du montant ; la catégorie, elle, ne se modifie jamais) : il faut créer un
  nouveau type. Libellé, description,
  valeurs par défaut et période restent modifiables.
- **PRIME-008** — *Fermer* maintient les bénéficiaires actuels (leurs affectations continuent, un
  changement de montant reste possible) et refuse toute nouvelle attribution, y compris la
  validation d'une proposition en attente. *Désactiver* exige une **date d'effet** dès qu'une
  affectation validée est en cours ou à venir (ADR 0039) : à cette date, plus rien n'est versé.
  Chaque affectation ouverte s'arrête la veille, une à une, avec le motif « Désactivation du type
  au … » ; une affectation qui n'aurait commencé qu'après, et toute proposition en attente, sont
  annulées. La date ne peut pas tomber dans un mois figé. Sans bénéficiaire, la désactivation prend
  effet le jour même. *Réactiver* ne fait rien reprendre : il faut attribuer à nouveau.
- **PRIME-009** — Organisation : chaque action vérifie explicitement l'organisation du type, y
  compris pour le super administrateur (le `Gate::before` ne la vérifie pas).
- **PRIME-010** — Journal (`AuditLogService`, module `rh_types_remuneration`) : création,
  modification (champs modifiés seulement), validation, changement de statut (avec la date de
  désactivation et le nombre d'affectations arrêtées), suppression.

## Une affectation

| Champ | Règle |
|---|---|
| Salarié | De l'organisation ; l'auteur doit être rattaché à son agence (sauf périmètre toutes agences) |
| Type | Validé et actif à la date de début, dans sa période d'application |
| Début d'effet | Obligatoire ; pour un type ponctuel, le mois de versement (du 1er au dernier jour du mois) |
| Fin d'effet | Facultative, ≥ début. Obligatoire si le type a une fin d'application (au plus tard celle-ci) |
| Montant ou taux | Selon le type : montant (> 0) pour un montant fixe, taux (> 0, ≤ 100 %) pour un pourcentage ; **aucun** pour un élément saisi chaque mois |
| Commentaire | Facultatif |
| Statut | À valider, validée, annulée. L'état affiché d'une affectation validée se déduit des dates : à venir, en cours, terminée |
| Auteurs, dates | Proposée par, validée par et le, fin demandée par, annulée par et le, dates de création et de modification |

## Règles des affectations

- **AFF-001** — Une affectation créée est **« À valider »** et ne produit aucun montant. La valider
  exige `rh-affectations-remuneration.valider` (super administrateur par défaut, délégable). Le
  serveur la refuse sans ce droit, même par requête directe ; « Enregistrer et valider » est
  possible pour qui a le droit.
- **AFF-002** — Seul un type **actif** à la date de début, **dans sa période d'application**,
  s'attribue. Un type à valider, fermé ou inactif est refusé. Les règles sont **revérifiées à la
  validation** : une proposition dont le type a été fermé entre-temps ne se valide plus.
- **AFF-003** — Dates : fin ≥ début ; les dates restent dans la période d'emploi du salarié (date
  d'embauche, date de sortie) ; un salarié sorti sans date de sortie ne reçoit rien.
- **AFF-004** — **Pas de chevauchement** : même salarié, même type, périodes qui se recouvrent →
  refusé, propositions à valider comprises, **sauf si le type autorise le cumul**. Contrôle en
  transaction, sous verrou du salarié, par une lecture verrouillante : deux attributions
  simultanées ne passent pas toutes les deux (vérifié sous MySQL).
- **AFF-005** — Montants : la valeur par défaut du type est **proposée** dans le formulaire, jamais
  reprise d'office ; le montant attribué est toujours celui saisi. Un élément **saisi chaque mois**
  n'a pas de montant permanent : son montant vient des montants mensuels (AFF-010).
- **AFF-006** — Une affectation **validée ne se modifie plus** : on change son montant à partir
  d'une date, ou on y met fin. Une proposition à valider se modifie librement (dates, montant,
  commentaire) ; le salarié et le type ne changent pas (annuler et attribuer à nouveau).
- **AFF-007** — **Changement de montant** (type fixe, mensuel) : une nouvelle affectation « à
  valider » **succède** à l'actuelle à la date d'effet (après son début, au plus tard à sa fin).
  Tant qu'elle n'est pas validée, l'ancien montant s'applique ; à sa validation, l'ancienne
  s'arrête la veille. Les périodes écoulées gardent leur montant. Un seul changement en attente à la
  fois ; un montant identique est refusé. Possible sur un type fermé (bénéficiaire actuel).
- **AFF-008** — **Fin d'effet** (retrait individuel) : demandée avec une date et un motif, appliquée
  à sa validation ; elle ne peut qu'avancer la fin actuelle et ne peut pas précéder le début. Le type
  reste attribuable aux autres. La demande peut être retirée tant qu'elle n'est pas validée.
- **AFF-009** — **Mois figés** : aucun début d'effet, changement de montant, fin d'effet (sauf au
  dernier jour du dernier mois figé), annulation ni montant mensuel ne peut toucher un mois figé. Un
  mois est figé dès qu'une version de sa transmission est validée, ou que la Comptabilité a validé
  sa paie (`CalendrierPaieService`). Le message indique la première date possible.
- **AFF-010** — **Montants mensuels** (nature « saisi chaque mois ») : un montant (ou un taux) par
  mois, sur une affectation validée en vigueur ce mois-là ; « à valider » puis validé. Un montant
  validé ne se corrige ou ne se supprime qu'avec le droit de validation. Un mois sans montant validé
  n'est pas transmis (avertissement dans la transmission) ; un montant à valider bloque la
  transmission du mois. Montant 0 accepté (rien ce mois-là).
- **AFF-011** — **Avantage en nature** : attribué comme les autres, mais toujours **valorisé** —
  transmis à part, jamais dans le total versé.
- **AFF-012** — **Annulation** : une proposition s'annule avec `rh-affectations-remuneration.delete`.
  Une affectation validée ne s'annule qu'avec le droit de validation, si elle n'a **jamais été
  transmise** (sinon : y mettre fin), hors mois figé, et si aucun changement de montant ne lui
  succède. Annuler un changement de montant validé rétablit la fin d'origine de l'affectation
  précédente. L'affectation annulée reste dans l'historique.
- **AFF-013** — **Cloisonnement** : liste, fiche et historique limités aux salariés des agences
  consultables (vision 360° comprise, ADR 0025) ; toute écriture exige le rattachement à l'agence du
  salarié (administrateur et superviseur : toutes). Organisation toujours vérifiée, super
  administrateur compris.
- **AFF-014** — **Journal** (module `rh_affectations_remuneration`) : attribution, modification,
  changement de montant, fin demandée, fin validée (« Fin d'effet »), validation, annulation,
  montants mensuels saisis, corrigés, validés, supprimés, arrêts dus à une désactivation. Menu ⋮ →
  *Historique* de la ligne.
- **AFF-015** — Liste filtrable par agence, statut (à valider, à venir, en cours, terminée,
  annulée), salarié, type et période d'effet (affectations en vigueur au moins un jour
  sur la période).

## Permissions

| Action | Permission | Par défaut |
|---|---|---|
| Référentiel : consulter | `rh-types-remuneration.read` | super_admin, admin_entreprise |
| Référentiel : créer, modifier une proposition, supprimer un type jamais attribué | `rh-types-remuneration.create` / `.update` / `.delete` | super_admin, admin_entreprise |
| Référentiel : valider, fermer, désactiver, réactiver ; modifier ou supprimer un type validé | **`rh-types-remuneration.valider`** | **super_admin seul** |
| Affectations : consulter (montants compris) et historique | `rh-affectations-remuneration.read` | super_admin, admin_entreprise |
| Attribuer, proposer un changement de montant, saisir un montant mensuel | `rh-affectations-remuneration.create` | super_admin, admin_entreprise |
| Modifier une proposition, demander ou retirer une fin d'effet | `rh-affectations-remuneration.update` | super_admin, admin_entreprise |
| Annuler une proposition | `rh-affectations-remuneration.delete` | super_admin, admin_entreprise |
| Valider une affectation, un changement de montant, une fin d'effet, un montant mensuel ; annuler une affectation validée ; corriger un montant validé | **`rh-affectations-remuneration.valider`** | **super_admin seul** |

Rattrapage des rôles existants (ADR 0011), migrations additives et idempotentes :
`2026_10_28_100100_backfill_permissions_types_remuneration` et
`2026_10_29_110100_backfill_permissions_affectations_transmissions_paie`. Les boutons de l'écran
suivent ces permissions et l'agence du salarié (règle 3 des standards UI) ; le serveur revérifie
tout.

## Hors périmètre

- Le salaire de base reste porté par le contrat, sans date d'effet ni affectation (décision du
  29/10/2026 : pas de type système « Salaire de base »).
- Traitement social, fiscal et compte comptable d'un type : lots 5-6.
- Réattribution en masse des anciens bénéficiaires après réactivation, attribution groupée : non
  développées.
- Import et export Excel.

## Fichiers clés

- Référentiel : `app/Models/TypeRemuneration.php`, `app/Services/Rh/TypeRemunerationService.php`,
  `app/Policies/TypeRemunerationPolicy.php`, `app/Http/Requests/TypeRemunerationRequest.php`,
  `app/Http/Controllers/Rh/TypesRemuneration/`, `resources/js/pages/TypesRemuneration/Index.vue`.
- Affectations : `app/Models/AffectationRemuneration.php`, `app/Models/SaisieRemuneration.php`,
  `app/Services/Rh/AffectationRemunerationService.php` (seul point d'écriture),
  `app/Services/Rh/Paie/CalendrierPaieService.php` (mois figés),
  `app/Policies/AffectationRemunerationPolicy.php`, `app/Http/Requests/AffectationRemunerationRequest.php`,
  `app/Http/Controllers/Rh/AffectationRemunerationController.php`,
  `app/Http/Controllers/Rh/SaisieRemunerationController.php`,
  `app/Support/Rh/AffectationRemunerationPresenter.php`,
  `resources/js/pages/AffectationsRemuneration/Index.vue`.
- Énumérations : `StatutTypeRemuneration`, `CategorieTypeRemuneration`, `ModeCalculRemuneration`,
  `PeriodiciteRemuneration`, `NatureMontantRemuneration`, `StatutAffectationRemuneration`,
  `StatutSaisieRemuneration`.
- Tests : `tests/Feature/TypeRemunerationTest.php`, `tests/Feature/AffectationRemunerationTest.php`.
