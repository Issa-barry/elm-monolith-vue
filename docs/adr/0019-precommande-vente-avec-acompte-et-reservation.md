# ADR 0019 — Précommande : vente marquée, acompte en avance client, réservation stricte

- **Date** : 2026-10-04
- **Statut** : **accepté le 2026-10-04** — décisions D1 à D14 validées (cf. § 13 de la
  [spécification](../precommandes.md#13-décisions-validées-le-04102026)) ; aucun interrupteur
  d'activation en V1. Implémentation par lots. D13 et D14 (lot 3, même jour) révisent la
  décision 8 sur la livraison soldée et le cashback. D16 (05/10/2026) permet de changer le mode de
  remise (retrait ↔ livraison) avant le chargement, sans changement de prix, et amende C7.
  **Toute référence D1 à D14 renvoie au § 13 de `docs/precommandes.md` ; en cas de divergence de
  numérotation ou de formulation, ce § 13 fait foi.**
- **Périmètre** : Ventes (`CommandeVente`), stock (`StockReservation`), encaissements, trésorerie,
  comptabilité, commissions, cashback, rapports — cf. [precommandes.md](../precommandes.md)
- **Amende** : ADR 0003 (retour avant encaissement), ADR 0007 §3 (date de vente)

## Contexte

Des clients viennent au comptoir commander une marchandise qu'ils retireront ou se feront livrer
plus tard, et versent tout ou partie du prix à l'avance. L'application ne connaît que la vente
immédiate : un encaissement n'est possible qu'après validation du chargement, une vente sans
véhicule fait sortir le stock immédiatement, et aucun remboursement à un client n'existe.

Le stock sait déjà réserver (`StockReservation`, colonne « Engagé », depuis le 24/08/2026), la
facture sait rester « Créée » avant d'être comptabilisée, et le décaissement depuis un support
existe pour les fiches (ADR 0009).

## Décision

1. **Une précommande est une `CommandeVente`** portant un indicateur immuable `est_precommande`.
   Pas de nouvelle entité, pas de nouvelle `NatureOperation` : nature, prix, commissions et préfixe
   `VTE-`/`DST-` restent calculés comme aujourd'hui. Pas de « transformation en vente ».
2. **Deux points d'entrée, deux routes, une permission dédiée** (`ventes.precommander`) ; le
   backend distingue les parcours, jamais l'interface seule. Les droits sont gérés dans
   **Rôles & Permissions → Ventes** : `admin_entreprise` et `manager` reçoivent toutes les
   permissions de précommande, `commerciale` uniquement `ventes.precommander` (D12).
3. **Réservation stricte à la création**, par le mécanisme existant, **sans exception** pour le
   paramètre de vente sans stock : une réservation payée correspond toujours à du stock réel.
   « Engagé » est renommé « Réservé ».
4. **Acompte paramétrable** dans **Paramètres → Ventes** (`parametres.update`) — une règle
   configurable, jamais une permission (D6) : « Acompte obligatoire : Oui / Non » et taux en %
   saisi par l'entreprise, sans valeur imposée — strictement positif (au plus 100 %) quand l'acompte
   est obligatoire ; sinon une précommande peut être créée sans acompte. Jamais configuré : aucune
   précommande possible tant que l'organisation n'a pas choisi. Paiement partiel autorisé.
   L'acompte est un `EncaissementVente` marqué `est_acompte` — même écran, mêmes supports, même
   caisse dédiée, même unicité des références que tout encaissement — comptabilisé
   **D trésorerie / C 419100**, sans effet sur le statut de la facture (qui reste « Créée »).
   C'est la seule exception à la règle « aucun encaissement avant chargement validé ».
5. **Atomicité** : contrôle des impayés, disponibilité, acompte éventuel, réservation, commande,
   facture, encaissement et écriture dans une seule transaction — jamais d'argent sans réservation.
6. **Trois statuts avant remise** : `reservee` → `a_preparer` → `preparee` (retrait) ou
   `a_charger` (livraison). Ensuite, workflow existant : retrait validé ⇒ `facturation`
   (sortie de stock, facture activée) ; livraison ⇒ chargement, livraison, réception existants.
7. **Mode de remise dérivé du véhicule** (livraison ⇒ véhicule obligatoire dès la création,
   retrait ⇒ aucun véhicule), comme pour le Grossiste : aucun second champ qui pourrait diverger.
8. **À la remise** : montant recalculé sur la quantité réellement remise ou chargée ; facture
   activée avec un statut calculé depuis l'encaissé net ; acomptes imputés (**D 419100 / C 411000**) ;
   cascade « facture payée » (commission, cashback, clôture) par un point unique partagé avec
   l'encaissement. Le solde éventuel est une créance ordinaire, soumise aux règles d'impayés existantes.
   **Chargement ≠ livraison (D13)** : une précommande en livraison reste « En livraison » après le
   chargement, même soldée par ses acomptes, jusqu'à « Confirmer la livraison »
   (`ventes.valider_reception`), l'encaissement du solde ou la validation de réception. **Le
   cashback attend cette livraison définitive (D14)** et se calcule, pour toutes les ventes, sur la
   quantité effective (livrée, sinon chargée, sinon commandée). La facture reste activée et
   comptabilisée au chargement, comme pour toute vente livrée.
9. **Trop-perçu remboursé**, jamais conservé ni converti en avoir : nouvelle table
   `remboursements_ventes`, décaissement ADR 0009 (solde vérifié sous verrou), clôture bloquée tant
   qu'il reste à rembourser.
10. **Annulation de précommande distincte de l'annulation exceptionnelle** (ADR 0004, réservée aux
    ventes fictives et sans remboursement) : statut `annulee`, motif obligatoire, réservation libérée,
    remboursement obligatoire ; après préparation, permission renforcée et confirmation paramétrable
    réutilisant le mécanisme de l'ADR 0004.
11. **Pas d'expiration automatique** : indicateur dérivé « En retard », en ambre.
12. **Amendements** : pour une précommande, la date de vente est la date de **réalisation** (décision
    D15 du 05/10/2026, ADR 0007 §3) — confirmation de livraison (ou réception validée) en livraison,
    remise effective en retrait ; le chargement n'est que la sortie du stock et une précommande non
    réalisée ne compte jamais dans le chiffre d'affaires. Sa comptabilisation au même moment (D16) et
    le sort des ventes ordinaires (D17) restent à valider.
    et un acompte n'empêche ni un retour (ADR 0003) ni un écart de réception : l'excédent devient un
    trop-perçu. Seul un encaissement du **solde** ferme la fenêtre de retour. Un retour total annule
    la facture mais laisse l'encaissé net **à rembourser** au client.

## Alternatives écartées

- **`NatureOperation::PRECOMMANDE`** : la nature est dérivée (client × véhicule) et pilote prix et
  commissions ; une précommande de distributeur aurait perdu son tarif et ses règles.
- **Entité `Precommande` convertie en vente** : transfert de la réservation, des paiements et de la
  facture d'un objet à l'autre, avec un risque de perte ou de doublon à chaque conversion.
- **Table d'acomptes séparée des encaissements** : aurait dupliqué `PaymentCard`, la résolution des
  supports, la caisse dédiée obligatoire, l'unicité des références Mobile Money (ADR 0014, index
  limité à `encaissements_ventes`), les règles de suppression et les rapports.
- **Encaisser l'acompte sur la facture comme un paiement ordinaire** : facture « Payée » avant toute
  livraison, compte 411 créditeur, commission et cashback déclenchés trop tôt.
- **Réutiliser l'annulation exceptionnelle** : contredit l'ADR 0004 (pas de remboursement, saisie
  fictive) et fausserait ses statistiques.
- **Réservation à découvert ou adossée à l'« Entrant »** : promettre une marchandise inexistante ;
  l'« Entrant » n'est pas encore calculé.
- **Expiration automatique** : ne règle rien tant que l'argent du client est détenu ; il faut de toute
  façon une décision et un remboursement tracés.
- **Avoir client** : nouveau concept (solde client, imputation future) sans besoin exprimé.

## Conséquences

- Migrations non destructives : colonnes sur `commandes_ventes`, `commande_vente_lignes`,
  `encaissements_ventes` (historique à `false` / `null`), table `remboursements_ventes`, paramètre
  d'acompte (obligatoire oui/non, taux minimum).
- Plan comptable : compte 419100, ajouté aussi aux organisations existantes. L'acompte réutilise
  l'événement `encaissement_vente_recu` avec un rôle crédité `avance_client` (419100) au lieu de
  `client` : tous les consommateurs de cet événement (contrepassation, rattrapage, journal financier,
  fiche de caisse, annulation exceptionnelle) restent valables. Lot 2 : événements
  `acompte_precommande_impute` (419100 → 411000 à la remise) et `remboursement_client`.
- `FactureVente::recalculStatut()` ne fait jamais sortir de « Créée » la facture d'une précommande
  pas encore remise ; à la remise, `PrecommandeService::activerFacture()` calcule le statut depuis
  l'encaissé net. Le total remboursé est dénormalisé (`factures_ventes.montant_rembourse`) : statut,
  reste à payer et trop-perçu se calculent net des remboursements.
- Cashback : déclenchement extrait du contrôleur d'encaissement dans `FacturePayeeCascade`,
  appelé aussi à la remise d'une précommande soldée par ses acomptes ; sans effet tant qu'une
  précommande est « En livraison » (D14), rappelé à la confirmation de livraison et à la validation
  de réception. `CashbackService::quantiteEligible()` lit la quantité effective (révision pour
  toutes les ventes, cf. `docs/cashback.md`).
- Lot 3 : nouvelle route `precommandes.livraison.confirmer` (permission existante
  `ventes.valider_reception`, aucune migration). Une livraison soldée chargée sous le lot 2 et déjà
  passée « Livrée » n'est pas modifiée.
- `StockReservationService` gagne une réduction partielle (préparation inférieure à la demande).
- Rapports : statuts avant remise hors chiffre d'affaires, date de vente = remise, une facture
  « Créée » de précommande n'est pas un impayé (situation véhicule), deux catégories de caisse.
- Nouvelles permissions, attribuées aux rôles existants uniquement par migration de backfill (ADR 0011).
- Les acomptes entrent dans le disponible de l'agence comme tout encaissement (ADR 0016) : un
  remboursement ultérieur dépend du solde du support.
