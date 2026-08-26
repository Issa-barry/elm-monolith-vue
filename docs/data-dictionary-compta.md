# Data dictionary — Comptabilité

Ce document distingue deux couches, volontairement séparées :

- **`compta_*`** = la comptabilité générale (SYSCOHADA) — **source comptable autoritaire
  unique** du projet. Aucun autre système ne tient de trace financière parallèle : l'ancien
  `journal_tresorerie` (registre de trésorerie opérationnel indépendant) a été entièrement
  supprimé le 2026-08-22 après raccordement de son dernier flux dépendant (cashback). Voir
  [`create_comptabilite_generale_tables.php`](../database/migrations/2026_08_12_000001_create_comptabilite_generale_tables.php)
  et [`create_tresorerie_tables.php`](../database/migrations/2026_08_22_100000_create_tresorerie_tables.php).
- **Données métier sources** (`depenses`, `commissions_ventes`, `commissions_logistiques`,
  `paiement_fiches` et sa famille, `factures_ventes`, `encaissements_ventes`,
  `paie_paiements`, `mouvements_fonds`, `commission_payments`, `cashback_versements`) : ce ne
  sont **pas** des tables de comptabilité générale. Ce sont les événements métier qui, une fois
  validés, *alimentent* la compta générale via les services
  `App\Services\Comptabilite\*ComptabilisationService`. Elles gardent leur nommage historique
  et ne sont pas renommées par ce document — la compta générale les consomme en aval, elle ne
  les remplace pas.

## Comptabilité générale (`compta_*`)

### `compta_comptes`
- **Rôle** : plan comptable (numéros SYSCOHADA), organisé en arbre via `parent_id`.
- **PK** : `id` (ULID).
- **FK** : `organization_id` → `organizations` ; `parent_id` → `compta_comptes` (auto-référence).
- **Organisation/site** : par organisation (`organization_id`), pas de notion de site.
- **Source métier** : aucune — table de paramétrage, provisionnée par
  `PlanComptableBootstrapService` (`php artisan comptabilite:bootstrap`), éditable ensuite par
  un expert-comptable. Les numéros de compte ci-dessous sont un point de départ raisonnable,
  **pas** une vérité gravée dans le marbre — à faire valider/ajuster par un expert-comptable
  SYSCOHADA (Guinée) avant mise en production réelle.
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
- **Rôle** : découpage d'un exercice (ex: mensuel), statut `ouverte`/`cloturee`. Toute
  écriture dont la date tombe dans une période clôturée est bloquée (`PeriodeComptableClotureeException`),
  sauf reprise explicite d'une régularisation déjà comptabilisée (`ignorerVerrouPeriode`).
- **PK** : `id`. **FK** : `organization_id` ; `exercice_comptable_id` → `compta_exercices` ;
  `cloture_by` → `users`.
- **Usage BI** : dimension temporelle fine (reporting mensuel), point d'ancrage des clôtures.
- **À ne pas confondre avec** `paiement_periodes` (quinzaines de paie/commission, module RH —
  système de périodes totalement différent, non lié à `compta_periodes`).

### `compta_tiers`
- **Rôle** : comptes auxiliaires — rattache une entité métier (Proprietaire, Livreur, Site,
  Prestataire, Employe, Client, Fournisseur) à un compte collectif (ex: "Propriétaires à
  payer"). Un tiers comptable n'est jamais un compte à part entière, cf. commentaire dans
  `TiersComptable`.
- **PK** : `id`. **FK** : `organization_id` ; `compte_collectif_id` → `compta_comptes`.
- **Relation polymorphique** : `tiersable_type` / `tiersable_id` → `Proprietaire`, `Livreur`,
  `Site`, `Prestataire`, `Employe`, `Client`, `Fournisseur` selon `type`.
- **Usage BI** : dimension "tiers" pour un grand livre auxiliaire (qui doit combien à qui).

### `compta_mappings`
- **Rôle** : table de données pure — associe (événement, rôle, moyen de paiement) → (compte,
  journal). Aucun numéro de compte n'est codé en dur dans le moteur, tout passe par ici
  (résolu par `CompteMappingResolver`). Isolée par organisation.
- **PK** : `id`. **FK** : `organization_id` ; `compte_comptable_id` → `compta_comptes` ;
  `journal_comptable_id` → `compta_journaux` (nullable).
- **Événements** : catalogue fermé dans `App\Enums\EvenementComptable` — voir la table
  "Événements comptables" ci-dessous.
- **Usage BI** : référentiel de configuration, rarement interrogé directement en BI (sert au
  moteur, pas au reporting).

### `compta_pieces`
- **Rôle** : en-tête d'une pièce comptable (numéro, date, journal, période, événement source,
  statut `validee`/`contrepassee`).
- **PK** : `id`. **FK** : `journal_comptable_id` → `compta_journaux` ;
  `exercice_comptable_id` → `compta_exercices` ; `periode_comptable_id` → `compta_periodes` ;
  `piece_origine_id` → `compta_pieces` (auto-référence, pour les contrepassations) ;
  `created_by` → `users`.
- **Relation polymorphique** : `source_type` / `source_id` — remonte à l'entité métier qui a
  déclenché la pièce (`PaiementFiche`, `PaiementFichePaiement`, `Depense`, `EncaissementVente`,
  `PaiePaiement`, `MouvementFonds`, `SoldeOuvertureTresorerie`, `CommissionPayment`,
  `CashbackVersement`, `FactureVente`...). Contrainte d'idempotence
  `['organization_id','source_type','source_id','type_evenement']` : rejouer le même
  événement métier ne recrée jamais une deuxième pièce — `EcritureComptableService::comptabiliser()`
  retourne la pièce existante au lieu d'en créer une seconde.
- **Usage BI** : table de faits "pièce" — filtrage par période/journal/événement.

### `compta_ecritures`
- **Rôle** : lignes débit/crédit d'une pièce. `debit` et `credit` mutuellement exclusifs
  (contrainte CHECK en base + garde applicative dans `EcritureComptableService`).
- **PK** : `id`. **FK** : `piece_comptable_id` → `compta_pieces` ; `compte_comptable_id` →
  `compta_comptes` ; `tiers_comptable_id` → `compta_tiers` (nullable) ; `site_id` → `sites`
  (nullable).
- **Usage BI** : table de faits principale pour grand livre / balance / bilan — c'est ici que
  vit le "vrai" chiffre comptable, à la ligne. Le **Journal financier** (voir plus bas) et le
  calcul du **disponible de trésorerie** (`TresorerieDisponibiliteService`) lisent exclusivement
  cette table (filtrée aux lignes portant sur un compte de `compta_supports_tresorerie`).

### `compta_piece_sequences`
- **Rôle** : compteur de numérotation séquentielle sans trou, verrouillé en transaction
  (`SELECT ... FOR UPDATE`) par `PieceNumerotationService`. Volontairement séparé de
  `compta_pieces` pour ne jamais dépendre d'un `MAX(numero)+1` sur une table qui grossit.
- **PK composite** : `['organization_id', 'journal_comptable_id', 'exercice_comptable_id']`.
- **FK** : `organization_id`, `journal_comptable_id` → `compta_journaux`,
  `exercice_comptable_id` → `compta_exercices`.
- **Usage BI** : table technique, aucun intérêt en reporting.

### `compta_supports_tresorerie`
- **Rôle** : support de trésorerie configurable par organisation — rattache un site à un compte
  du plan comptable (571000 Caisse, 521000 Banque, 561xxx Mobile Money...). Lève l'ambiguïté
  "où l'argent est réellement détenu" ; aucun opérateur/numéro codé en dur, cf. `CompteTresorerie`.
  Le type (Caisse/Banque/Mobile Money) et le compte comptable doivent être cohérents — déduit du
  `moyen_paiement` de `compta_mappings` (rôle `tresorerie`) via `SupportTresorerieTypeResolver`,
  vérifié à la création et à la modification (`CompteTresorerieController`). Type et compte
  deviennent figés dès qu'un solde d'ouverture existe.
- **PK** : `id`. **FK** : `organization_id` ; `site_id` → `sites` ; `compte_comptable_id` →
  `compta_comptes`.
- **Usage BI** : dimension "support de trésorerie" (caisse/banque/mobile money par site).

### `compta_soldes_ouverture`
- **Rôle** : solde d'ouverture d'un support de trésorerie — au plus un par support (unique),
  brouillon puis validé. La validation seule produit une pièce comptable (débit compte du
  support / crédit `109000` — contrepartie technique), cf. `SoldeOuvertureTresorerieService`.
  Un montant de 0 est validé sans pièce (rien à comptabiliser).
- **PK** : `id`. **FK** : `organization_id` ; `compte_tresorerie_id` → `compta_supports_tresorerie`
  (unique) ; `piece_comptable_id` → `compta_pieces` (nullable) ; `created_by`/`valide_by` → `users`.

## Événements comptables (`App\Enums\EvenementComptable`)

| Événement | Déclencheur | Type | Comptes (rôles) |
|---|---|---|---|
| `vente_facturee` | Facture quitte le statut CREEE | Engagement, shadow (try/catch, ne bloque jamais la vente) | `client` (411) / `produit_vente` (701) |
| `encaissement_vente_recu` | `EncaissementVente` créé | Règlement, **bloquant** | `client` (411) / `tresorerie` |
| `fiche_proprietaire_validee` | `PaiementFiche` (proprietaire) validée | Engagement, shadow | `charge_commission` (622100) / `dette_tiers` (467110) / `avance_tiers_proprietaire` (467130) |
| `fiche_livreur_validee` | `PaiementFiche` (livreur) validée | Engagement, shadow | idem (622200 / 467120 / 467140) |
| `fiche_site_validee` | `PaiementFiche` (site) validée | Engagement, shadow | idem (622300 / 467170 / 467190) |
| `fiche_consultant_validee` | `PaiementFiche` (prestataire) validée | Engagement, shadow | idem (622400 / 467180 / 467200) |
| `paiement_proprietaire` | `PaiementFichePaiement` (proprietaire) créé | Règlement, **bloquant** | `dette_tiers` (467110) / `tresorerie` |
| `paiement_livreur` | idem (livreur) | Règlement, **bloquant** | `dette_tiers` (467120) / `tresorerie` |
| `paiement_site` | idem (site) | Règlement, **bloquant** | `dette_tiers` (467170) / `tresorerie` |
| `paiement_consultant` | idem (prestataire) | Règlement, **bloquant** | `dette_tiers` (467180) / `tresorerie` |
| `depense_interne_validee` | `Depense` (beneficiaire_type=null) validée | Charge + règlement combinés, **bloquant** | `charge`/`charge_defaut` (628800 ou compte du DepenseType) / `tresorerie` |
| `depense_avance_tiers_validee` | `Depense` (vehicule/proprietaire/livreur) validée | Avance récupérable, **bloquant** | `avance_tiers_{type}` / `tresorerie` |
| `paiement_salaire` | `PaiePaiement` créé | Règlement, jambe trésorerie uniquement, **bloquant** | `charge_salaire` (661000) / `tresorerie` |
| `paiement_commission_logistique_direct` | `CommissionPayment` créé | Règlement, jambe trésorerie uniquement, **bloquant** | `charge_commission_{livreur\|proprietaire}` / `tresorerie` |
| `versement_cashback` | `CashbackVersement` créé | Règlement, jambe trésorerie uniquement, **bloquant** | `charge_cashback` (658100) / `tresorerie` |
| `mouvement_fonds_envoye` | `MouvementFonds` envoyé | Transfert interne, **bloquant** | `fonds_transit` (588000) / trésorerie origine (compte direct) |
| `mouvement_fonds_recu` | `MouvementFonds` reçu | Transfert interne, **bloquant** | trésorerie destination (compte direct) / `fonds_transit` (588000) |
| `solde_ouverture_tresorerie` | `SoldeOuvertureTresorerie` validé | Contrepartie technique, **bloquant** | compte du support / `contrepartie_ouverture` (109000) |
| `regularisation_cloture_fiche` | Clôture de période avec fiche non validée | Provision, reprise auto à la validation réelle | mêmes comptes que fiche_proprietaire/livreur_validee + comptes de provision (467150/467160) |

**Shadow vs bloquant** : "shadow" signifie que l'écriture est bien créée dans `compta_ecritures`
mais qu'un échec de comptabilisation (mapping manquant, période clôturée...) est seulement loggé
(`Log::error`) — il ne bloque jamais l'opération métier, réservé aux événements qui ne déplacent
pas de trésorerie réelle (engagement/reconnaissance, ex: `vente_facturee`, `fiche_*_validee`).
"Bloquant" signifie que l'échec de comptabilisation fait échouer toute la transaction métier
(règlement/décaissement/encaissement réel) — c'est la règle depuis la revue Codex du
2026-08-22 pour tout événement qui touche un compte de trésorerie : aucune trésorerie réelle ne
doit jamais bouger sans écriture comptable correspondante.

## Contrepassation (annulation sans suppression destructive)

Règle #29 : une pièce validée n'est **jamais** supprimée ni modifiée en place. Toute annulation
passe par `EcritureComptableService::contrepasser()` (débit/crédit inversés, mêmes comptes/tiers/
montants, nouvelle pièce datée du jour avec `piece_origine_id` vers l'originale ; l'originale
passe au statut `contrepassee`). Déclenché automatiquement :

- **Suppression** de la source métier : `EncaissementVente::deleted()`, `PaiementFichePaiement::deleted()`,
  `PaiePaiement::deleted()`, `DepenseObserver::deleted()`.
- **Dévalidation** d'une dépense déjà validée : `DepenseObserver::updated()` (transition
  VALIDE → autre statut).
- **Annulation d'une facture** déjà comptabilisée : `CommandeVenteService::contrepasserVenteFactureeSiExistante()`.
- **Retour confirmé** d'un mouvement de fonds (jamais une simple contestation) :
  `MouvementFondsService::confirmerRetour()`.
- **Reprise d'une régularisation de clôture** à la validation réelle de la fiche :
  `FicheComptabilisationService::reprendreRegularisationSiExistante()`.

`CommissionPayment` et `CashbackVersement` n'ont pas de route de suppression côté application —
aucune contrepassation automatique n'est nécessaire pour ces deux modèles.

## Source de calcul du disponible de trésorerie

`App\Services\Tresorerie\TresorerieDisponibiliteService::disponiblePourSite()` calcule le solde
réel d'un site **exclusivement** depuis `compta_ecritures`, filtré aux `compte_comptable_id`
appartenant aux `CompteTresorerie` actifs de ce site (`débit − crédit`). Il ne lit jamais
d'autre table — en particulier plus aucun registre parallèle depuis la suppression de
`journal_tresorerie`. Les fonds "en transit" (mouvements de fonds envoyés mais pas encore reçus)
sont ajoutés séparément via `MouvementFonds` (compte 58), avec un rattachement optionnel à une
échéance (`echeance_debut`/`echeance_fin`) pour éviter un double financement.

## Journal financier — vue de lecture

L'écran "Journal financier" (`/backoffice/comptabilite/journal`,
`App\Http\Controllers\Comptabilite\JournalFinancierController`) est une **vue de lecture pure**
sur `compta_ecritures`/`compta_pieces`, restreinte aux lignes portant sur un compte de
`compta_supports_tresorerie` — aucune table parallèle, aucune duplication. "Entrée"/"sortie" est
dérivé du débit/crédit de la ligne (même logique que `TresorerieDisponibiliteService`), jamais
stocké séparément. Filtres : agence (`site_ids[]`, via `DataFilters.vue`), année/mois, journal,
événement, compte, sens, référence. Le drill-down vers les autres lignes de la même pièce est
intégré à chaque ligne (pas de navigation supplémentaire). Isolation stricte par organisation, et
par site pour les utilisateurs non-admin (`SiteScopeService`).

Remplace l'ancien `JournalTresorerieController`/`journal_tresorerie` (registre de trésorerie
opérationnel indépendant, supprimé le 2026-08-22 après avoir raccordé son dernier flux dépendant :
le versement de cashback, désormais comptabilisé via `CashbackComptabilisationService`).

## Données métier sources (en amont de la compta générale)

| Table | Rôle | Alimente `compta_pieces` via | Événement(s) |
|---|---|---|---|
| `depenses` | Dépenses (interne ou imputée à un tiers) | `DepenseComptabilisationService` | `depense_interne_validee`, `depense_avance_tiers_validee` |
| `paiement_fiches` + `paiement_fiche_lignes` + `paiement_fiche_paiements` | Fiches de paiement propriétaires/livreurs/sites/consultants (commissions à régler) | `FicheComptabilisationService` | `fiche_proprietaire_validee`, `fiche_livreur_validee`, `fiche_site_validee`, `fiche_consultant_validee`, `paiement_proprietaire`, `paiement_livreur`, `paiement_site`, `paiement_consultant`, `regularisation_cloture_fiche` |
| `commissions_ventes` / `commissions_logistiques` + tables de parts/ajustements | Calcul des commissions par vehicule/livreur/site/consultant | Indirectement, via les fiches de paiement qui les agrègent | — |
| `factures_ventes` | Facturation client | `VenteComptabilisationService` | `vente_facturee` |
| `encaissements_ventes` | Encaissement client | `VenteComptabilisationService` | `encaissement_vente_recu` |
| `paie_paiements` | Paiement de salaire | `PaieComptabilisationService` (jambe trésorerie uniquement, pas d'engagement préalable) | `paiement_salaire` |
| `mouvements_fonds` | Mouvement de fonds interne agence ↔ siège (remise/financement). Porte `echeance_debut`/`echeance_fin` (nullable) pour rattacher le mouvement à un besoin précis (P1/P2/mois) et éviter un double financement — cf. `FinancementAgenceService`. Workflow : brouillon → envoyé → (contesté ↔) reçu / retourné. Une contestation seule ne contrepasse jamais rien : seul le retour confirmé le fait. | `MouvementFondsComptabilisationService` — 2 pièces mono-site (émission + réception) via le compte 58 "virements internes" | `mouvement_fonds_envoye`, `mouvement_fonds_recu` |
| `commission_payments` | Paiement direct de commission logistique — circuit actif et distinct de `paiement_fiches` (verrouillé contre le double paiement par `PeriodePayabilityChecker::assertPartsNotClaimedByFiche`) | `CommissionPaymentComptabilisationService` (jambe trésorerie uniquement) | `paiement_commission_logistique_direct` |
| `cashback_versements` | Versement de cashback à un client | `CashbackComptabilisationService` (jambe trésorerie uniquement) | `versement_cashback` |

**Note pour la data/BI** : tous les flux qui déplacent réellement de la trésorerie sont
désormais comptabilisés et **bloquants** (jamais en mode shadow) : si la pièce comptable ne peut
pas être créée, l'opération métier est annulée dans son ensemble. Seuls les événements de pure
reconnaissance/engagement (`vente_facturee`, `fiche_*_validee`) restent en mode shadow — ils sont
bien comptabilisés, mais un échec de comptabilisation ne bloque jamais l'opération métier
correspondante puisqu'aucune trésorerie réelle n'est en jeu à ce stade.

**Limite comptable connue** : les dépenses de catégorie EMPLOYE (avance sur salaire) restent hors
périmètre de la comptabilité générale — gérées entièrement par le module Paie existant
(`PaieLigne`/`PaieVariable`), pas par `DepenseComptabilisationService`. `PaiementFiche.beneficiaire_type
= 'salarie'` n'est jamais généré en pratique (la paie suit son propre circuit
`PaieLigne`/`PaiePaiement`, jamais `PaiementFiche`).
