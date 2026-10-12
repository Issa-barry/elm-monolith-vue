# ADR 0024 — Paiement des factures fournisseurs : décaissement réel depuis l'agence de la facture

- **Date** : 2026-10-09
- **Statut** : **accepté le 2026-10-09** (lot 4 du module Achats)
- **Périmètre** : paiement des factures fournisseurs validées (ADR 0022), trésorerie, comptabilité,
  obligations des agences (ADR 0016)
- **Détail fonctionnel** : [docs/achats.md](../achats.md), section Paiement des fournisseurs

## Contexte

Depuis le lot 3 (ADR 0022), une facture fournisseur validée porte une dette (TTC, payé, reste dû) et
est comptabilisée au crédit du 401000. Il manquait le règlement. Le paiement des fiches de
commission (ADR 0009) a déjà résolu le décaissement réel : support de l'agence, caisse dédiée du
payeur en espèces, solde contrôlé sous verrou au grand livre, écriture indissociable.

## Décision

1. **Même mécanisme que les fiches, sans règle parallèle.** La partie « agence » de
   `DecaissementFicheResolver` est extraite dans `DecaissementSupportResolver`, partagé par les fiches
   et les factures fournisseurs (comportement des fiches inchangé). Même dialogue `PaymentCard` en
   décaissement.
2. **L'argent sort de l'agence de la facture** (celle du bon de commande) : caisse dédiée active du
   payeur en espèces, sinon un moyen actif de l'agence (Mobile Money, banque, chèque). Référence
   obligatoire selon le moyen, comme à l'encaissement.
3. **Paiement partiel ou total, plusieurs paiements par facture.** Montant ≤ reste dû, relu **sous
   verrou de la facture** (lecture verrouillante, prouvée par un test MySQL réel à deux connexions) ;
   solde du support garanti sous verrou (`garantirSoldeSuffisant`). Statut : validée → partiellement
   payée → payée. Seule une facture validée ou partiellement payée est payable.
4. **Paiement, statut et écriture dans une seule transaction**, écriture **bloquante** (le solde des
   supports est lu au grand livre) : débit 401000 Fournisseurs (tiers = fournisseur), crédit du compte
   du support réellement débité ; journal résolu par le moyen de paiement (CA / MM / BQ), événement
   `paiement_fournisseur`. Un refus (solde, reste dû, écriture) n'a aucun effet.
5. **Droits** : permission `factures-fournisseurs.payer` + agence de la facture couverte par le
   périmètre « Peut acheter pour », sans passe-droit de rôle (contrôle explicite contre le
   Gate::before du super administrateur). Rôles par défaut : admin_entreprise, comptable.
6. **ADR 0016** : nouvel `FournisseurObligationContributor` — les factures validées non soldées de
   l'agence entrent dans ce qu'elle conserve avant toute remise (colonne « Fournisseurs » du
   Financement des agences). Échéance = date d'échéance, à défaut date de facture ; obligation du
   mois de son échéance (fin de mois), arriéré une fois échue et encore due.
7. **Hors V1** : annulation d'un paiement (une erreur se corrige par une opération manuelle), règle
   « saisie ≠ paiement », paiement depuis la trésorerie principale (elle finance l'agence par la voie
   existante, ADR 0016).

## Conséquences

- Une facture partiellement payée ne peut plus être annulée (règle du lot 3, inchangée).
- **Révisé le 2026-10-10 (décision de l'utilisateur) : une facture dont la pièce de validation est
  « en attente de paramétrage » (comptes d'achat ou de TVA non décidés) n'est PAS payable.** Refus
  serveur dans `PaiementFournisseurService::motifNonPayable()`, relu sous verrou (un appel direct est
  refusé de la même façon), sans paiement, sans écriture, sans sortie de trésorerie ; la fiche
  affiche le motif à la place du bouton Payer. Le paiement redevient possible dès que l'écriture
  est passée (bouton « Relancer » de la fiche ou `comptabilite:rattraper --type=facture-fournisseur`).
  Conséquence assumée : tant que le comptable n'a pas fixé les comptes d'achat et de TVA, aucune
  facture d'achat ne peut être payée.
  *Avant le 10/10/2026 : le paiement était accepté et le 401000 restait temporairement débiteur
  jusqu'au rattrapage — règle remplacée.*

## Révision du 2026-10-12 — l'agence qui paie se choisit au moment du règlement

**Décision de l'utilisateur.** Sur le terrain, l'agence prévue pour payer et celle qui règle
réellement ne sont pas toujours les mêmes : un virement ou un chèque part de la trésorerie
principale, des espèces sont remises à un agent d'une autre agence, un bon créé dans une agence est
réceptionné dans une deuxième et réglé par une troisième. Les points 2, 5 et 7 ci-dessus sont
remplacés comme suit ; le reste de l'ADR est inchangé.

1. **« Payé par » reste sur le bon, comme agence PRÉVUE** (ADR 0021, ACH-015) : elle fixe le plafond
   de validation, le droit de saisir et valider la facture, et l'agence de la facture. Elle n'est
   plus une contrainte au moment de payer.
2. **Au paiement, le payeur choisit l'agence d'où sort l'argent** parmi celles de son périmètre
   « Peut acheter pour » ; par défaut, l'agence de la facture. Les supports proposés et acceptés sont
   ceux de l'agence choisie (`DecaissementSupportResolver`, inchangé) : un support d'une agence non
   choisie est refusé. *Remplace le point 2 (« l'argent sort de l'agence de la facture ») et lève
   l'exclusion du point 7 (paiement depuis la trésorerie principale).*
3. **Droits** : `factures-fournisseurs.payer` + périmètre couvrant **l'agence de la facture ET
   l'agence qui paie**. Choisir un support n'élargit aucun droit ; une agence d'une autre
   organisation est toujours refusée. *Complète le point 5.*
4. **Espèces : la caisse dédiée reste obligatoire, sans exception.** L'argent sort de la caisse
   dédiée active du payeur **dans l'agence qui paie**. Quand la trésorerie principale remet des
   espèces à un agent, le circuit existant s'applique avant le paiement : transfert de fonds,
   confirmation par l'agent, fonds dans sa caisse dédiée (ADR 0018), puis règlement depuis cette
   caisse. Aucun paiement en espèces « direct ».
5. **Une seule dette.** La facture garde son agence et son reste dû ; chaque paiement le diminue.
   Le paiement enregistre l'agence d'où l'argent est réellement sorti (`paiements_fournisseurs.site_id`,
   affichée sur la fiche quand elle diffère) ; son écriture (débit 401000, crédit du support) est
   rattachée à cette agence.
6. **Financement des agences (ADR 0016)** : le reste dû reste une obligation de l'agence de la
   facture tant qu'il n'est pas réglé, et d'elle seule — ce qu'une autre agence a payé n'est plus
   dû et n'est compté nulle part ailleurs.
7. **Pas de dette entre agences en V1** : l'agence qui paie supporte le coût. Aucune créance n'est
   créée sur l'agence prévue (pas de compte de liaison, contrairement à l'encaissement inter-agences
   de l'ADR 0012). **À confirmer par le comptable** ; l'agence réellement payeuse étant tracée sur
   chaque paiement, une refacturation pourra être dérivée plus tard sans reprise de données.

Aucune migration : `paiements_fournisseurs.site_id` existait déjà et valait l'agence de la facture.
