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
- **`valide_le`** (timestamp nullable) et **`valide_par_id`** (`users`, `nullOnDelete`), depuis le
  2026-09-19 : **cycle de vie du support** (ADR [0002](adr/0002-cycle-de-vie-support-tresorerie.md)).
  Un support est créé en **brouillon** (`valide_le` NULL, `actif` = false), inutilisable partout,
  puis **validé** par un utilisateur habilité (`tresorerie.valider_supports`) : il devient **actif**
  (`valide_le` + `valide_par_id` renseignés, `actif` = true) et peut ensuite être désactivé puis
  réactivé. Le statut affiché (Brouillon / Actif / Inactif) est **dérivé** de `valide_le` et
  `actif`, jamais stocké ; `actif` reste l'unique verrou d'usage lu partout. Un support jamais
  validé ne peut pas devenir actif (garde du modèle). Tous les supports antérieurs au workflow ont
  été **repris comme validés à leur date de création** (`valide_par_id` NULL), avec leur état
  actif/inactif inchangé.
- **`agent_id`** (nullable, depuis le 2026-09-19) : responsable d'une **caisse dédiée à un agent**.
  `NULL` = support de l'agence (tous les supports historiques) ; renseigné = caisse dédiée. La
  « nature » est **dérivée** de cette colonne, jamais stockée. Voir la section « Caisses dédiées à
  un agent » ci-dessous.
- **PK** : `id`. **FK** : `organization_id` ; `site_id` → `sites` ; `agent_id` → `users`
  (`nullOnDelete`) ; `compte_comptable_id` → `compta_comptes`.
- **Usage BI** : dimension "support de trésorerie" (caisse/banque/mobile money par site) et, pour
  les caisses dédiées, "argent détenu par agent".

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
| `encaissement_vente_recu` | `EncaissementVente` créé | Règlement, **bloquant** | `client` (411) / `tresorerie` — ou, pour des espèces encaissées par un agent qui a une caisse dédiée, son sous-compte imposé (option `journal_role`, cf. `encaissements.md`) |
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

`TresorerieDisponibiliteService::situationParSupport()` (ajouté le 2026-09-13) calcule la même
chose à la granularité du **support** plutôt qu'agrégée par site — même requête `débit − crédit`
sur `compta_ecritures`, jamais une seconde logique de calcul. Attention : `compta_ecritures` ne
porte pas de `compte_tresorerie_id` (seulement `compte_comptable_id` + `site_id`) — si deux
supports d'un même site partagent le même compte comptable (cas rare, non empêché à la création),
leur solde renvoyé est identique (reflet exact du grand livre, pas un bug de ce calcul).

## Situation de trésorerie — vue de lecture

L'écran "Situation de trésorerie" (`/backoffice/comptabilite/tresorerie/situation`,
`App\Http\Controllers\Comptabilite\SituationTresorerieController`) répond à « combien y a-t-il
actuellement dans chaque caisse/banque/mobile money de chaque agence ? » — un manque identifié
lors de la revue produit du 2026-09-13 (les écrans existants couvrent la configuration des
supports, l'historique des mouvements et les besoins de financement, mais pas le solde courant).
Vue de lecture pure sur `situationParSupport()` (donc sur `compta_ecritures`), agrégée par site et
par type de support sur l'écran liste, détaillée par support sur l'écran par agence (`show`).
Ne duplique jamais le détail entrées/sorties, déjà couvert par le Journal financier (lien direct
depuis l'écran détail). Isolation par organisation, et par site pour les non-admin
(`SiteScopeService`), même convention que les autres écrans du module.

**Situation = où est l'argent** : elle inclut les caisses dédiées aux agents (chacune avec son
solde propre, distinct de la caisse de l'agence du même site). Ce n'est **pas** le « disponible »
du Financement — cf. ci-dessous.

## Cycle de vie d'un support de trésorerie (Brouillon → Actif → Inactif)

Décision du 2026-09-19 (ADR [0002](adr/0002-cycle-de-vie-support-tresorerie.md)). **Tout** support —
caisse, banque, Mobile Money, caisse dédiée à un agent — suit le même cycle :

| Statut | Signification | Utilisable ? |
|---|---|---|
| **Brouillon** | Créé (écran Supports ou `CaisseAgentService::creer()`), jamais validé. | **Non**, nulle part. |
| **Actif** | Validé par un utilisateur habilité, en service. | Oui. |
| **Inactif** | Validé, puis désactivé. Se réactive (règles de désactivation inchangées). | Non. |

- **Validation** (`SupportTresorerieValidationService::valider()`, route
  `POST comptabilite/tresorerie/supports/{support}/valider`) : brouillon → actif, avec la trace
  `valide_par_id` / `valide_le`. Permission dédiée **`tresorerie.valider_supports`**, distincte de
  `tresorerie.gerer_soldes_ouverture` (qui crée et modifie) : l'organisation peut confier la
  validation à un autre profil. **Portée** : même organisation, agence de l'utilisateur (les admins
  ont autorité sur toutes). Il n'y a **pas** de règle « créateur ≠ validateur » : la séparation se
  fait par l'attribution des permissions. Un support déjà validé ne se revalide pas (le
  `Gate::before` du super admin neutralise la policy : l'état est revérifié par le service et par
  l'indicateur `peut_valider` de l'écran).
- **Caisse dédiée** : à la validation, le service revérifie les conditions de la création — agent
  actif et rattaché à l'agence, **une seule caisse dédiée active par (agent, site)**. Créer un
  brouillon reste refusé tant qu'une caisse active existe pour le même (agent, site).
- **Un brouillon ne s'active jamais par une simple modification** : `update` avec `actif = true`
  est refusé (erreur sur `actif`), et le modèle lève une `LogicException` en dernier recours. Sa
  modification (libellé, type, compte) reste possible.
- **Un brouillon est inutilisable** : hors Situation, hors disponible et position du Financement,
  hors listes des mouvements de fonds ; refusé côté serveur par `MouvementFondsService` (origine,
  destination, réception, versement) ; pas de solde d'ouverture avant validation
  (`SoldeOuvertureTresorerieService::enregistrer()`) ; ne reçoit aucun encaissement
  (`CaisseAgentResolver`, qui date la mise en service à `valide_le`, pas à la création).
- **Suppression d'un utilisateur** responsable d'une caisse dédiée en **brouillon** ou active :
  refusée (sinon la FK `nullOnDelete` la transformerait en support d'agence).
- **Reprise de l'existant** : la migration marque tout support existant comme validé à sa date de
  création. Aucun changement d'usage ni d'état pour l'existant. Un support créé directement actif
  par du code (hors écran, ex. tests) est réputé validé — les deux seuls points de création
  applicatifs créent explicitement en brouillon.
- **Permission `tresorerie.valider_supports`** (nouvelle, refusée par défaut — aucun backfill des
  rôles existants ; présente dans les préréglages `admin_entreprise` et `comptable` du seeder, les
  deux rôles qui gèrent les supports). Le super admin la possède implicitement.
- **Solde d'ouverture** : acte distinct, sans dépendance avec la validation du support. Il n'est
  proposé qu'à partir d'un support validé.

**Écran** : une seule colonne **Statut** (`StatusDot` : Brouillon, Actif, Inactif) ; bouton
« Valider » (avec confirmation) sur un brouillon, pour qui a la permission dans le périmètre ; le
solde d'ouverture n'est plus un second statut dans la liste — il n'y apparaît qu'en **alerte
ambre** quand une action est requise sur un support actif d'agence (« à saisir », ou « à valider ·
montant · non compté dans le solde »), et son détail (montant, état) est en lecture seule dans
« Modifier le support », avec la trace « Validé le … par … ».

## Caisses dédiées à un agent (Trésorerie > Supports)

Décision du 2026-09-19 (ADR [0001](adr/0001-caisse-dediee-agent-sous-compte.md)), phase 1
livrée : modèle + écran Supports. Une caisse dédiée est un support de type **Caisse** rattaché à
un agent (`agent_id`) et à un site, destinée à recevoir les encaissements en espèces de cet agent.

**Règles garanties côté serveur** (`CaisseAgentService`, jamais seulement par l'interface) :

- **Un sous-compte comptable propre par caisse** (571001, 571002... sous le compte racine 571000),
  créé automatiquement à la création de la caisse, numéroté par organisation et jamais réattribué.
  C'est ce qui rend son solde distinguable de celui de la caisse de l'agence : `compta_ecritures`
  ne porte que compte + site, pas de support.
- L'agent doit appartenir à l'organisation, être actif et **rattaché au site** (`user_sites`).
  Aucune contrainte de rôle : un agent est un utilisateur, pas un rôle.
- Une caisse dédiée est créée en **brouillon** et validée comme tout support (cf. « Cycle de vie
  d'un support de trésorerie » ci-dessus) ; seule une caisse **active** est utilisable.
- **Une seule caisse dédiée active par (agent, site)** — nécessaire à la phase 2 (routage
  automatique des encaissements en espèces). Un agent rattaché à deux sites peut avoir une caisse
  par site. Un agent dont la caisse est désactivée peut en recevoir une nouvelle.
- Type (toujours Caisse), compte et agent sont **figés** après création : seuls le libellé et
  l'activation se modifient.
- Une caisse qui détient de l'argent (solde ≠ 0 au grand livre) **ne peut pas être désactivée** ;
  la réactivation est refusée si une autre caisse active existe pour le même (agent, site).
- **Pas de solde d'ouverture** : la caisse démarre à 0, et l'enregistrement d'un solde d'ouverture
  est refusé. L'argent y arrive par les encaissements en espèces de l'agent (phase 2) ou par un
  transfert depuis la caisse de l'agence (phase 3).
- La suppression d'un utilisateur qui est responsable d'une caisse dédiée **active ou en brouillon** est refusée
  (`DestroyUserController`) : sinon la FK `nullOnDelete` la transformerait silencieusement en
  support d'agence et son argent entrerait dans le disponible. Une caisse déjà désactivée (donc
  vide) ne bloque pas.

**Effets sur le reste de la trésorerie** :

| Vue | Caisses dédiées |
|---|---|
| **Situation** (`situationParSupport()`) | **Incluses** — c'est « où est l'argent ». |
| **Disponible du Financement** (`disponiblePourSite()`) | **Exclues** — l'argent d'un agent n'est utilisable par l'agence qu'une fois versé et réceptionné. Il ne réduit donc pas le « à financer par le siège ». |
| **Position fiable** (`FinancementAgenceService::positionFiable()`) | **Exclues** — une caisse dédiée sans solde d'ouverture ne rend jamais le site « non fiable ». |
| **Mouvements de fonds entre agences** | **Interdites** en origine comme en destination (`MouvementFondsService`, garde serveur ; les listes de l'écran les excluent aussi). Leur solde passera par un versement vers la caisse de l'agence (phase 3). |
| **Journal financier** | Incluses, filtrables par compte (chaque caisse a son sous-compte). |

**Écran** (`/backoffice/comptabilite/tresorerie/supports`) : liste Agence / Caisse / Compte (numéro
du compte comptable du support, colonne dédiée) / Nature / Responsable / Solde / Statut / Actions,
solde calculé depuis le grand livre (y compris pour un support désactivé),
filtres serveur (agence `site_ids[]`, statut, type, nature, agent) via `DataFilters` en
`trigger-only`, création dans un dialogue « Créer une caisse » avec choix de la nature dans une
liste déroulante (« Caisse de l'agence » ou « Caisse dédiée à un agent »). Depuis la phase 3,
**lecture ouverte à `tresorerie.read`**, limitée aux agences de l'utilisateur (admins : toutes) ;
création, modification et soldes d'ouverture restent sous `tresorerie.gerer_soldes_ouverture` (les
données de gestion — utilisateurs, comptes comptables — ne sont même pas envoyées à un simple
lecteur).

**Phase 2 (livrée le 2026-09-19) — routage des encaissements** : un encaissement en espèces
enregistré par un agent qui a une caisse dédiée active sur le site de la facture débite le
sous-compte de SA caisse au lieu de 571000 (`CaisseAgentResolver`, conditions et exceptions dans
[encaissements.md](encaissements.md), section « Comptabilisation : caisse dédiée de l'agent »).
L'argent y est visible dans la Situation, jamais dans le disponible du Financement.

**Phase 3 (livrée le 2026-09-19) — versement d'une caisse dédiée vers la caisse de l'agence** :
parcours Supports → caisse agent → « Verser à l'agence » → Envoyer, puis Mouvements → « Confirmer
réception ». C'est un `MouvementFonds` de **nature `interne_caisses`** (même table, même référence
`MVT-AAAA-NNNNN`, mêmes statuts et mêmes écritures via le compte de transit 588000 que les
mouvements entre agences).

- **Envoyé** (`MouvementFondsService::verserCaisseAgent()`, création + envoi en une opération, sans
  brouillon) : débit 588000 / crédit sous-compte de la caisse de l'agent — la caisse de l'agent
  baisse tout de suite, celle de l'agence n'augmente pas. **Reçu** : débit caisse de l'agence /
  crédit 588000, confirmé par un **autre utilisateur** ; le transit est alors soldé et l'argent
  redevient disponible pour l'agence. Aucun produit, charge ni encaissement client.
- **Contrôles serveur, sous verrou sur la caisse source** : source = caisse dédiée active ;
  destination = caisse (type Caisse) **d'agence** active, du **même site** et de la même
  organisation (jamais une banque, un compte Mobile Money ni la caisse d'un agent) ; montant > 0 et
  **au plus égal au solde de la caisse au grand livre** (deux versements successifs relisent le
  solde déjà diminué). La caisse de destination est fixée à l'envoi : la réception la confirme, elle
  ne la remplace pas, et exige qu'elle soit toujours active.
- **Séparation envoi/réception** : celui qui a envoyé ne confirme ni ne conteste
  (`MouvementFonds::separationEnvoiReceptionRespectee()`, appliquée par le service, la policy et les
  indicateurs de l'écran). **Seul le super admin peut y déroger** (décision du 2026-09-19) ;
  `sent_by` et `received_by` restent enregistrés, l'exception est donc traçable. Un
  `admin_entreprise` n'a pas cette dérogation.
- **Contestation / retour** : réutilisés tels quels (Contesté → Reçu, ou → Retourné qui recrédite la
  caisse de l'agent). Pas d'annulation après l'envoi. Une caisse ne peut pas être désactivée tant
  qu'un de ses versements est Envoyé ou Contesté.
- **Permission `tresorerie.verser`** (nouvelle, refusée par défaut — aucun backfill des rôles
  existants ; présente dans les préréglages admin, manager et comptable du seeder). Portée : agence
  de l'utilisateur ; sans `tresorerie.envoyer` (hors responsable) on ne verse que **sa propre**
  caisse. La réception reste sous `tresorerie.recevoir`. Le `Gate::before` du super admin neutralise
  les policies : l'état de la caisse et la séparation sont donc revérifiés par le service, et les
  indicateurs `peut_*` de l'écran Mouvements vérifient désormais l'état du mouvement explicitement
  (un super admin ne voit plus toutes les actions sur une ligne terminée).
- **Financement** : les versements internes sont exclus de « fonds en transit » et de « déjà
  financé » (`TresorerieDisponibiliteService`) — ce n'est pas un financement du siège. Pendant l'état
  Envoyé, l'argent n'est compté dans aucun solde (ni caisse de l'agent, ni disponible de l'agence) :
  il est au compte de transit 588000.
- **« En cours de versement »** (affichage, 2026-09-20) : pour que cet argent ne semble jamais
  disparaître, les écrans le signalent **à part**, sans jamais l'ajouter à un solde —
  **Solde ≠ en cours de versement ≠ reçu**. Le calcul est une simple lecture des mouvements
  (`TresorerieDisponibiliteService::versementsEnCours()`) : versements `interne_caisses` **Envoyé ou
  Contesté** (un litige non résolu laisse l'argent en transit), par caisse source, envoyés au plus tard
  à la date de situation. Aucune écriture, aucun solde ni workflow Envoyé → Reçu n'est modifié ; le
  grand livre reste la source de vérité. Où le voir :
  - **Supports** : 4ᵉ carte « En cours de versement » (montant + « N versement(s) à confirmer », 0 GNF
    sans versement), et sous le solde de la caisse qui verse « En cours de versement : X GNF » —
    solde actuel + en cours = ce que la caisse détenait avant le versement. Les deux suivent les
    filtres, comme le solde total.
  - **Situation** (liste et fiche d'une agence) : bandeau « X GNF en cours de versement » et mention
    sous le total de l'agence / le solde de la caisse concernée, seulement quand un versement est en
    cours. Le total de la Situation n'est pas modifié. À une date passée, un versement reçu après
    cette date y est encore « en cours » ; un versement retourné depuis n'est pas retrouvé (le retour
    n'est pas daté sur le mouvement) — cas rare, sans effet à la date du jour.
  - **Mouvements** : « En attente de confirmation » sous le statut de tout versement Envoyé, visible
    de l'envoyeur comme du destinataire.
  Après la réception : la caisse de l'agence est créditée, « en cours de versement » retombe à 0.
- **Non traité** : le sens inverse (alimenter une caisse dédiée depuis la caisse de l'agence) ; un
  contrôle de solde pour les mouvements entre agences (il n'en existe toujours pas).

**Phase suivante (non livrée)** : 4) fiche caisse (encaissements, versements, solde, historique).

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
| `mouvements_fonds` | Mouvement de fonds interne agence ↔ siège (remise/financement), ou — `nature = interne_caisses` depuis le 2026-09-19 — versement d'une caisse dédiée à un agent vers une caisse de l'agence, au sein d'un même site (cf. « Caisses dédiées à un agent » ; `nature` vaut `inter_sites` pour tout l'existant). Porte `echeance_debut`/`echeance_fin` (nullable) pour rattacher le mouvement à un besoin précis (P1/P2/mois) et éviter un double financement — cf. `FinancementAgenceService`. Workflow : brouillon → envoyé → (contesté ↔) reçu / retourné. Une contestation seule ne contrepasse jamais rien : seul le retour confirmé le fait. `compte_tresorerie_origine_id` est choisi à la création (l'émetteur sait d'où part l'argent) ; `compte_tresorerie_destination_id` est nullable et choisi par le destinataire au moment de `MouvementFondsService::recevoir()`, pas à la création — le site destinataire est connu à l'avance, mais pas forcément la caisse/wallet précis qui recevra réellement les fonds (revue produit du 2026-09-13). | `MouvementFondsComptabilisationService` — 2 pièces mono-site (émission + réception) via le compte 58 "virements internes" | `mouvement_fonds_envoye`, `mouvement_fonds_recu` |
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
