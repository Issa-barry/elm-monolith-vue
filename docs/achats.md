# Achats — bons de commande fournisseurs

Décision structurante : [ADR 0021](adr/0021-achats-bon-de-commande-valide-par-plafond-de-role.md).
Module `module.achats` (désactivé par défaut).

## Circuit

```text
Bon de commande ──► À valider ──valider (permission + plafond + séparation des tâches)──► Validée
                        │                                                                  │
                        └── annuler (motif) ──► Annulée          Logistique → Réceptions : 1..n réceptions
                                                                                            ▼
                                       Partiellement réceptionnée ──► Réceptionnée (ou Clôturée : reliquat abandonné)
```

Factures d’achat et dette : lot 3 ([ADR 0022](adr/0022-factures-fournisseurs-dette-et-comptabilisation.md), section ci-dessous). Paiement des fournisseurs : lot 4 ([ADR 0024](adr/0024-paiement-des-factures-fournisseurs.md), section ci-dessous).

## Règles

| Code | Règle |
|---|---|
| ACH-000 | **Périmètre « Peut acheter pour »** : règle par rôle (son agence / agences sélectionnées / toutes les agences). Il gouverne la création, la lecture et la validation. Sans règle couvrant l'agence : aucun accès, admin_entreprise et super administrateur compris. **Seule ouverture, en consultation uniquement (ADR 0025)** : un rôle ayant la permission `sites.lecture_toutes_agences` consulte les bons, les factures d'achat (liste, fiche, PDF) et la liste des réceptions fournisseurs de toutes les agences, sans aucune action hors de son périmètre d'achat ; le super administrateur, qui détient toutes les permissions, est dans ce cas. |
| ACH-001 | Créer exige `achats.create` et une règle d'un des rôles couvrant l'agence de livraison (seules ces agences sont proposées, et le serveur refuse les autres). Fournisseur actif, au moins une ligne ; agence, fournisseur et variantes de l'organisation. |
| ACH-002 | Un bon est modifiable (`achats.update`) tant qu'il est à valider. Chaque modification enregistre son auteur (`contenu_modifie_par`). |
| ACH-003 | Valider exige `achats.valider` ET une règle couvrant l'agence avec montant ≤ plafond (égalité autorisée). Règle sans plafond : ne valide rien. Plusieurs rôles : le plafond le plus élevé parmi les règles couvrant l'agence. |
| ACH-004 | Aucun passe-droit : super administrateur et admin_entreprise suivent ACH-000, ACH-003 et ACH-005 comme tout rôle. Règles de départ (modifiables) : « toutes agences, sans limite » pour ces deux rôles, créées par migration et à l'installation ; le super administrateur peut en plus valider ses propres bons (ACH-005). |
| ACH-005 | Le créateur du bon et le dernier modificateur de son contenu ne peuvent pas le valider, sauf si une règle de leur rôle couvrant l'agence autorise « Peut valider ses propres bons » avec un plafond suffisant (révisé le 09/10/2026). Activé par défaut pour le super administrateur, désactivé pour les autres rôles. Le snapshot de validation note si le bon a été validé par son auteur. |
| ACH-006 | À la validation sont figés : montant, nom du fournisseur, nom de l'agence, libellé et référence des lignes, règle de plafond appliquée (rôle, plafond, agences). Fiche et PDF lisent ce snapshot. |
| ACH-007 | Annuler (`achats.annuler`, motif obligatoire) est possible quel que soit le plafond, tant qu'aucune quantité n'a été reçue. |
| ACH-008 | Une commande partiellement reçue peut être clôturée (`achats.annuler`, motif) : le reliquat n'est plus attendu. |
| ACH-009 | Réception (`receptions.create`, utilisateur rattaché à l'agence de la commande) : uniquement depuis Logistique → Réceptions, sur une commande validée ou partiellement reçue. Chaque quantité ≤ reliquat, relu sous verrou ; sinon refus sans aucun effet. |
| ACH-010 | Le stock entre dès l'enregistrement de la réception, sur l'agence de la commande. Le coût unitaire (prix de la commande) est figé sur la ligne de réception ; le mouvement de stock porte le motif « Réception achat — référence du bon ». |
| ACH-011 | Le `prix_achat` de la variante prend le coût reçu, sauf pour un produit vendable dont la marge se calcule sur le prix d'achat si ce coût atteint le prix de vente : avertissement orange, réception non bloquée. |
| ACH-012 | Lecture (`achats.read`) : bons dont l'agence de livraison OU l'agence payeuse est couverte par ACH-000, plus ceux que l'utilisateur a créés ou validés. Voir un bon ne donne pas le droit d'agir dessus (ACH-015). |
| ACH-015 | **Deux agences par bon** (10/10/2026) : agence de livraison (réception, stock) et agence payeuse (facture, dette, paiement, écritures, obligation du Financement) — « Payé par », par défaut la trésorerie principale si le périmètre de l'utilisateur la couvre, sinon l'agence de livraison ; figée à la validation. Créer, modifier, valider, annuler, clôturer et supprimer exigent que le périmètre ACH-000 couvre LES DEUX agences ; le plafond de validation (ACH-003) est celui d'une règle couvrant l'agence payeuse. Bons antérieurs : agence payeuse = agence de livraison. |
| ACH-013 | Notifications après commit : création → validateurs possibles (mêmes règles que la validation : permission, séparation des tâches, périmètre, plafond) et super administrateurs dont le périmètre couvre le bon ; validation → créateur et utilisateurs de l'agence ayant `receptions.create` ; annulation → créateur. Jamais l'auteur de l'action. |
| ACH-013b | Quand aucun autre utilisateur actif ne peut valider le bon (même calcul que les destinataires de ACH-013), la fiche l'indique : « Aucun autre utilisateur ne peut valider ce bon… » avec les rôles autorisés pour ce montant. |
| ACH-014 | PDF : filigrane « NON VALIDÉ » tant que le bon n'est pas validé, « ANNULÉ » s'il est annulé. |

## Factures d’achat (lot 3)

Terminologie (10/10/2026) : l'écran s'appelle « Factures d’achat » ; les noms techniques restent `factures_fournisseurs`, `FactureFournisseur` et les permissions `factures-fournisseurs.*`. Le « n° de facture du fournisseur » désigne le numéro inscrit par le fournisseur sur sa facture.

| Code | Règle |
|---|---|
| FAF-001 | Une facture appartient à un bon de commande **validé** (validée, partiellement réceptionnée, réceptionnée ou clôturée) et à son fournisseur ; un autre fournisseur est refusé. Agence de la facture = **agence payeuse** du bon (ACH-015) : c'est elle qui gouverne la saisie, la validation, le paiement et l'obligation de trésorerie. |
| FAF-002 | Elle facture des **lignes de réception** (une ou plusieurs réceptions du bon). Quantité facturable d'une ligne = reçu − déjà facturé sur des factures validées. Contrôlé à la saisie et **sous verrou à la validation** (lecture verrouillante, prouvée sur MySQL réel) : jamais deux fois la même quantité reçue, même en concurrence. Les réceptions ne sont jamais modifiées. |
| FAF-003 | Le numéro de facture du fournisseur est unique par fournisseur (factures non annulées), **garanti en base** (clé technique + index unique) : une saisie simultanée du même numéro est refusée. Un numéro annulé peut être ressaisi. |
| FAF-004 | Saisie (`factures-fournisseurs.create`) et modification (`update`) en brouillon, pour un bon dont l'agence est couverte par ACH-000. Une facture validée n'est plus modifiable. |
| FAF-005 | Validation : `factures-fournisseurs.valider` + agence couverte par ACH-000 + ni l'auteur de la saisie ni le dernier modificateur, sauf si une règle de leur rôle couvrant l'agence autorise « Peut valider ses propres factures d’achat » (révisé le 10/10/2026 ; activé par défaut pour le super administrateur, désactivé pour les autres rôles). Une facture déjà validée ou annulée ne se valide pas. Aucune condition sur le rôle dans le code. |
| FAF-010 | PDF de la fiche : récapitulatif de la facture enregistrée (référence, fournisseur, n° fournisseur, dates, bon de commande, réceptions, lignes, HT/TVA/TTC, statut), même modèle que le bon de commande, filigrane « BROUILLON » ou « ANNULÉE ». Il porte la mention qu'il n'est pas la facture originale du fournisseur. |
| FAF-006 | **La dette naît à la validation** (jamais à la réception ni au brouillon) : HT, TVA (taux saisi, 0 par défaut), TTC, payé = 0, reste dû = TTC. Dette d'un fournisseur = somme des restes dus de ses factures validées. Montants, libellés et nom du fournisseur figés. |
| FAF-007 | Écriture à la validation (événement `facture_fournisseur_validee`) : débit achat HT (rôle `achat_{type de produit}`, repli `achat`), débit `tva_deductible`, crédit `fournisseur` TTC (401000, journal AC, tiers = fournisseur). Comptes par défaut PROVISOIRES depuis le 10/10/2026, à valider par le comptable : `achat` → 601000 Achats de marchandises, `tva_deductible` → 445200 TVA récupérable sur achats (aucun compte par type de produit ; une correspondance déjà configurée n'est jamais écrasée). Non bloquante : sans compte d'achat/TVA paramétré, la dette existe et l'écriture est « en attente » (relance depuis la fiche ou `comptabilite:rattraper --type=facture-fournisseur`, idempotent). État comptable affiché séparément du statut : Comptabilisée / En attente de paramétrage / Contrepassée. |
| FAF-008 | Annulation (`factures-fournisseurs.annuler`, motif) d'un brouillon ou d'une facture validée sans paiement. **Atomique** : l'écriture est contrepassée dans la même transaction ; si la contrepassation échoue, rien n'est annulé. Une facture encore en attente de comptabilisation s'annule sans pièce. Une relance de comptabilisation ne passe jamais d'écriture sur une facture annulée (statut relu sous verrou). Les quantités et le numéro redeviennent disponibles. |
| FAF-009 | Lecture : factures des bons visibles (ACH-012), plus celles que l'utilisateur a saisies ou validées. |

**En attente du comptable** : compte(s) d'achat (un seul ou par type de produit), TVA récupérable
(compte et taux), confirmation 401000 / journal AC — cf. ADR 0022.

## Paiement des fournisseurs (lot 4)

| Code | Règle |
|---|---|
| PAF-001 | Seule une facture validée ou partiellement payée est payable ; montant ≤ reste dû, relu sous verrou de la facture (jamais payée deux fois, même en concurrence). Plusieurs paiements possibles : validée → partiellement payée → payée. |
| PAF-002 | L'argent sort d'un support de l'**agence de la facture** : caisse dédiée active du payeur en espèces, sinon un moyen actif de l'agence (même dialogue et mêmes moyens que le paiement des fiches, ADR 0009). Référence obligatoire selon le moyen. |
| PAF-003 | Solde du support garanti sous verrou avant la sortie ; solde insuffisant = refus sans aucun effet. |
| PAF-004 | Écriture bloquante, dans la même transaction que le paiement : débit 401000 Fournisseurs (tiers = fournisseur), crédit du compte du support débité (journal CA / MM / BQ). |
| PAF-005 | Droits : `factures-fournisseurs.payer` + agence couverte par ACH-000, sans passe-droit (super administrateur compris). |
| PAF-006 | Les factures validées non soldées entrent dans ce que l'agence conserve avant toute remise (ADR 0016, colonne « Fournisseurs » du Financement des agences) : obligation du mois de leur échéance (à défaut date de facture), arriéré une fois échues. |
| PAF-007 | Une facture déjà payée, même en partie, ne peut plus être annulée. L'annulation d'un paiement n'existe pas en V1. |
| PAF-008 | Une facture n'est payable que si son écriture de validation est passée (révisé le 10/10/2026). En attente de paramétrage des comptes d'achat ou de TVA : refus serveur sans aucun effet, motif affiché à la place du bouton Payer ; payable dès que l'écriture est relancée avec succès. |

## Paramètres → Achats

Une ligne par rôle (super administrateur compris, rien n'est verrouillé) : case « Peut acheter
pour » (sans elle, aucun accès), agences couvertes, et plafond de validation en GNF ou « sans
limite » (vide = le rôle ne valide rien), et cases « Peut valider ses propres bons » (ACH-005) et « Peut valider ses propres factures d’achat » (FAF-005). Les
permissions `achats.*` se cochent dans l'écran Rôles.
Permission de la page : `parametres.update`.

## Permissions

| Permission | Usage |
|---|---|
| `achats.read` / `create` / `update` / `delete` | lire, créer, modifier un bon à valider, supprimer un bon annulé |
| `achats.valider` | valider (borné par le plafond du rôle) |
| `achats.annuler` | annuler, clôturer un reliquat |
| `receptions.read` / `receptions.create` | Logistique → Réceptions → Commandes fournisseurs |
| `factures-fournisseurs.read` / `create` / `update` / `delete` | lire, saisir, modifier une facture en brouillon |
| `factures-fournisseurs.valider` | valider (constate la dette) |
| `factures-fournisseurs.annuler` | annuler (contrepassation si validée) |
| `factures-fournisseurs.payer` | payer une facture validée (décaissement depuis l'agence de la facture) |

## Données

- `commandes_achats` : `site_id` (livraison), `site_payeur_id` et `site_payeur_nom_snapshot` (paiement), `numero`, `contenu_modifie_par/at`, `validee_at/par`,
  `montant_valide`, `fournisseur_nom_snapshot`, `site_nom_snapshot`, `validation_regle_snapshot`,
  `cloturee_at/par`, `motif_cloture`. Références `BC-JJMMAA-NNN` ; les `ACH-…` historiques restent.
- `commande_achat_lignes.reference_snapshot` (SKU) ; `qte_recue` = cumul des réceptions.
- `receptions_achats` (`RCA-JJMMAA-NNN`) et `reception_achat_lignes` (coût, mouvement de stock).
- `regles_validation_roles` : organisation, domaine (`achats`), rôle, plafond, sans limite,
  périmètre, agences.

- `factures_fournisseurs` (`FAF-JJMMAA-NNN`) : bon, fournisseur, agence, n° du fournisseur, dates,
  taux de TVA, HT / TVA / TTC / payé, statut, auteurs (saisie, dernière modification, validation,
  annulation), nom du fournisseur figé, dernier motif d'échec de comptabilisation.
- `facture_fournisseur_lignes` : ligne de réception, ligne de commande, variante, libellé et
  référence figés, quantité, prix unitaire, total HT.
- `paiements_fournisseurs` : facture, fournisseur, agence, montant, mode et détail du moyen,
  support de trésorerie débité, référence, date, auteur — une pièce comptable par paiement.

## Historique

Commandes antérieures au 07/10/2026 (`en_cours`) : traitées comme « à valider » ; sans agence, elles
doivent être modifiées (agence + fournisseur) avant validation.
