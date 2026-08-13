# Data dictionary — Comptabilité

Ce document distingue deux couches, volontairement séparées :

- **`compta_*`** = la comptabilité générale (SYSCOHADA). Seul module du projet à porter un
  préfixe de domaine — c'est un choix délibéré : ces 9 tables doivent être repérables d'un
  coup d'œil dans une liste de tables (HeidiSQL, grep, un outil BI) sans connaître par cœur
  les conventions de nommage du reste du schéma. Voir [`create_comptabilite_generale_tables.php`](../database/migrations/2026_08_12_000001_create_comptabilite_generale_tables.php).
- **Données métier sources** (`depenses`, `commissions_ventes`, `commissions_logistiques`,
  `paiement_fiches` et sa famille, `factures_ventes`, `encaissements_ventes`,
  `journal_tresorerie`) : ce ne sont **pas** des tables de comptabilité générale. Ce sont les
  événements métier qui, une fois validés, *alimentent* la compta générale via les services
  `App\Services\Comptabilite\*ComptabilisationService`. Elles gardent leur nommage historique
  et ne sont pas renommées par ce document — la compta générale les consomme en aval, elle ne
  les remplace pas.

`journal_tresorerie` mérite une mention à part : c'est un flux de trésorerie **opérationnel**
(entrées/sorties de caisse au fil de l'eau), pas une comptabilité d'engagement — il ne doit
jamais être confondu avec `compta_pieces`/`compta_ecritures`, qui appliquent la partie double.

## Comptabilité générale (`compta_*`)

### `compta_comptes`
- **Rôle** : plan comptable (numéros SYSCOHADA), organisé en arbre via `parent_id`.
- **PK** : `id` (ULID).
- **FK** : `organization_id` → `organizations` ; `parent_id` → `compta_comptes` (auto-référence).
- **Organisation/site** : par organisation (`organization_id`), pas de notion de site.
- **Source métier** : aucune — table de paramétrage, provisionnée par
  `PlanComptableBootstrapService` (`php artisan comptabilite:bootstrap`), éditable ensuite par
  un expert-comptable.
- **Usage BI** : dimension "compte" pour tout grand livre / balance / bilan.

### `compta_journaux`
- **Rôle** : journaux comptables (VE, AC, CA, BQ, MM, OD...).
- **PK** : `id`. **FK** : `organization_id`.
- **Source métier** : paramétrage, seedé par `PlanComptableBootstrapService`.
- **Usage BI** : dimension "journal" (filtrer un grand livre par journal).

### `compta_exercices`
- **Rôle** : exercices comptables (bornes annuelles), statut `ouvert`/`cloture`.
- **PK** : `id`. **FK** : `organization_id` ; `cloture_by` → `users`.
- **Usage BI** : dimension temporelle de haut niveau (reporting annuel).

### `compta_periodes`
- **Rôle** : découpage d'un exercice (ex: mensuel), statut `ouverte`/`cloturee`.
- **PK** : `id`. **FK** : `organization_id` ; `exercice_comptable_id` → `compta_exercices` ;
  `cloture_by` → `users`.
- **Usage BI** : dimension temporelle fine (reporting mensuel), point d'ancrage des clôtures.
- **À ne pas confondre avec** `paiement_periodes` (quinzaines de paie/commission, module RH —
  système de périodes totalement différent, non lié à `compta_periodes`).

### `compta_tiers`
- **Rôle** : comptes auxiliaires — rattache une entité métier (Proprietaire, Livreur, Employe,
  Client, Fournisseur) à un compte collectif (ex: "Propriétaires à payer"). Un tiers comptable
  n'est jamais un compte à part entière, cf. commentaire dans `TiersComptable`.
- **PK** : `id`. **FK** : `organization_id` ; `compte_collectif_id` → `compta_comptes`.
- **Relation polymorphique** : `tiersable_type` / `tiersable_id` → `Proprietaire`, `Livreur`,
  `Employe`, `Client`, `Fournisseur` selon `type`.
- **Usage BI** : dimension "tiers" pour un grand livre auxiliaire (qui doit combien à qui).

### `compta_mappings`
- **Rôle** : table de données pure — associe (événement, rôle, moyen de paiement) → (compte,
  journal). Aucun numéro de compte n'est codé en dur dans le moteur, tout passe par ici
  (résolu par `CompteMappingResolver`).
- **PK** : `id`. **FK** : `organization_id` ; `compte_comptable_id` → `compta_comptes` ;
  `journal_comptable_id` → `compta_journaux` (nullable).
- **Événements** : catalogue fermé dans `App\Enums\EvenementComptable` (fiche_proprietaire_validee,
  fiche_livreur_validee, paiement_proprietaire, paiement_livreur, depense_interne_validee,
  depense_avance_tiers_validee, regularisation_cloture_fiche).
- **Usage BI** : référentiel de configuration, rarement interrogé directement en BI (sert au
  moteur, pas au reporting).

### `compta_pieces`
- **Rôle** : en-tête d'une pièce comptable (numéro, date, journal, période, événement source).
- **PK** : `id`. **FK** : `journal_comptable_id` → `compta_journaux` ;
  `exercice_comptable_id` → `compta_exercices` ; `periode_comptable_id` → `compta_periodes` ;
  `piece_origine_id` → `compta_pieces` (auto-référence, pour les contrepassations) ;
  `created_by` → `users`.
- **Relation polymorphique** : `source_type` / `source_id` — remonte à l'entité métier qui a
  déclenché la pièce (`PaiementFiche`, `Depense`, ...). Contrainte d'idempotence
  `['organization_id','source_type','source_id','type_evenement']` : rejouer le même
  événement métier ne recrée jamais une deuxième pièce.
- **Événements qui l'alimentent** : `FicheComptabilisationService` (validation/paiement de
  fiche, régularisation de clôture) et `DepenseComptabilisationService` (dépense interne ou
  imputée à un tiers validée).
- **Usage BI** : table de faits "pièce" — filtrage par période/journal/événement.

### `compta_ecritures`
- **Rôle** : lignes débit/crédit d'une pièce. `debit` et `credit` mutuellement exclusifs
  (contrainte CHECK en base + garde applicative dans `EcritureComptableService`).
- **PK** : `id`. **FK** : `piece_comptable_id` → `compta_pieces` ; `compte_comptable_id` →
  `compta_comptes` ; `tiers_comptable_id` → `compta_tiers` (nullable) ; `site_id` → `sites`
  (nullable).
- **Usage BI** : table de faits principale pour grand livre / balance / bilan — c'est ici que
  vit le "vrai" chiffre comptable, à la ligne.

### `compta_piece_sequences`
- **Rôle** : compteur de numérotation séquentielle sans trou, verrouillé en transaction
  (`SELECT ... FOR UPDATE`) par `PieceNumerotationService`. Volontairement séparé de
  `compta_pieces` pour ne jamais dépendre d'un `MAX(numero)+1` sur une table qui grossit.
- **PK composite** : `['organization_id', 'journal_comptable_id', 'exercice_comptable_id']`.
- **FK** : `organization_id`, `journal_comptable_id` → `compta_journaux`,
  `exercice_comptable_id` → `compta_exercices`.
- **Usage BI** : table technique, aucun intérêt en reporting.

## Données métier sources (en amont de la compta générale)

| Table | Rôle | Alimente `compta_pieces` via | Événement(s) |
|---|---|---|---|
| `depenses` | Dépenses (interne ou imputée à un tiers) | `DepenseComptabilisationService` | `depense_interne_validee`, `depense_avance_tiers_validee` |
| `paiement_fiches` + `paiement_fiche_lignes` + `paiement_fiche_paiements` | Fiches de paiement propriétaires/livreurs (commissions à régler) | `FicheComptabilisationService` | `fiche_proprietaire_validee`, `fiche_livreur_validee`, `paiement_proprietaire`, `paiement_livreur`, `regularisation_cloture_fiche` |
| `commissions_ventes` / `commissions_logistiques` + tables de parts/ajustements | Calcul des commissions par vehicule/livreur | Indirectement, via les fiches de paiement qui les agrègent | — |
| `factures_ventes` / `encaissements_ventes` | Facturation et encaissement client | Non branché à ce jour | — |
| `journal_tresorerie` | Flux de trésorerie opérationnel (caisse/banque au fil de l'eau) | Non branché — reste un journal métier parallèle, pas une sous-table de `compta_journaux` | — |

**Note pour la data/BI** : à ce stade, seuls les modules Dépenses et Fiches de paiement sont
réellement branchés sur la comptabilité générale. Commissions (calcul brut), Ventes/Factures et
Trésorerie opérationnelle restent des sources métier autonomes, non encore comptabilisées en
partie double — ne pas supposer qu'un total dans `compta_ecritures` couvre l'intégralité de
l'activité tant que ces branchements n'existent pas.
