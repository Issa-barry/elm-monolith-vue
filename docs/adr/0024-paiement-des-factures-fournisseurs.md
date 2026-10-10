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
