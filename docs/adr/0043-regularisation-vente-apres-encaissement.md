# ADR 0043 — Régularisation d'une vente après encaissement

- **Date** : 2026-10-30
- **Statut** : accepté — lot 1 (baisse de quantité) livré le 2026-10-30 (non commité)
- **Périmètre** : Ventes, Facturation, Encaissements, Stock, Comptabilité, Commissions — cf.
  [regularisation-vente.md](../regularisation-vente.md)
- **Révise** : [retour-commande.md](../retour-commande.md) « Retour après encaissement : hors
  périmètre » ; complète [ADR 0004](0004-annulation-exceptionnelle-commande-saisie-par-erreur.md)
  et [ADR 0010](0010-fiche-unite-de-validation-et-de-paiement.md)

## Contexte

VTE-301026-029 : 2 700 packs saisis au lieu de 270, vente livrée, encaissée (16 200 000 GNF),
commission générée et en partie payée. Le retour de livraison s'arrête au premier encaissement ;
l'annulation exceptionnelle défait toute la vente et refuse une commission payée ; aucun mécanisme
d'avoir n'existe.

## Décision

Décisions de l'utilisateur du 30/10/2026 :

1. **Premier lot : baisse de quantité** uniquement.
2. **Excédent encaissé : choix à chaque régularisation** — « jamais reçu » (encaissement réduit,
   contrepassation) ou « reçu » (trop-perçu remboursé par l'action de remboursement existante,
   opération distincte).
3. **Commissions recalculées immédiatement** sur la quantité corrigée, avec le moteur existant ;
   parts non payées mises à jour ; parts payées conservées avec leurs paiements, écart (trop-perçu
   ou complément) enregistré et suivi ; jamais de commission négative ni de compensation entre
   bénéficiaires ; la régularisation n'est pas bloquée par une commission payée.
4. **Confirmation** : même réglage que l'annulation exceptionnelle (code e-mail ou simple).
5. Permission dédiée `ventes.regulariser_apres_encaissement`, super admin par défaut.

Choix d'implémentation :

- Le mouvement de quantité **réutilise** `commande_vente_retours` (nouvelle colonne `nature` =
  `regularisation`) : mêmes effets que le retour sur la quantité livrée, le stock (ENTRÉE) et la
  comptabilité (pièce `vente_retour`, sans nouvel événement comptable ni nouveau mapping). Le motif
  réservé `regularisation` est refusé par le formulaire de retour.
- Recalcul par **simulation** du générateur (`simulerPourCommandeVente()`, persistance omise) puis
  réconciliation part par part : part non engagée remplacée (la période est rouverte par le
  mécanisme commun), part engagée mise à jour en place (identité et paiements conservés) et écart
  tracé dans `commission_ecarts_regularisation`.
- Une **fiche figée** ne peut plus être modifiée (ADR 0010) : ses montants restent intacts, mais
  `reduction_regularisation` retire de son reste ce qui n'est plus dû (`ligne − max(recalculé,
  versé)` par commission recalculée). Le paiement est plafonné à ce reste corrigé. *Révision de
  l'audit du 30/10/2026* : la première version bloquait la fiche jusqu'à « Marquer régularisé »,
  puis rouvrait l'ancien reste — double paiement possible et sommes non contestées bloquées.
- Un **complément** se paie par la fiche et se solde automatiquement ; seul un **trop-perçu** se
  constate par « Marquer régularisé » (commentaire obligatoire). Un trop-perçu n'est jamais compensé
  sur les autres commissions de la fiche.
- Réduction d'encaissement : remplacement par un encaissement du montant restant **avant** la
  suppression, pour que la facture ne quitte jamais « Payée » (sinon, déclencheur
  « facture encaissée », la commission serait annulée).
- Le remboursement du trop-perçu, réservé aux précommandes, est ouvert aux ventes régularisées
  (créance client 411, pas l'avance 419).
- `CommissionEnveloppePart::recalculStatut()` : une part dont le versé dépasse un montant ramené à 0
  reste « payée » (auparavant « partielle »).

## Conséquences

- Les paiements historiques ne sont jamais modifiés ; les écarts sont visibles sur la fiche commande.
- La retenue automatique sur la prochaine fiche n'est pas faite : volontairement, l'utilisateur a
  demandé un recalcul immédiat et un suivi, la régularisation financière restant une opération
  distincte.
- Non couverts : hausse, prix, mode de paiement, cashback (refus), précommandes, ventes à réception
  explicite ou sans véhicule.
