# ADR 0005 — Moyens de paiement dérivés des supports de trésorerie de l'agence

- **Date** : 2026-09-24
- **Statut** : accepté et livré le 2026-09-24
- **Périmètre** : encaissements de vente (`PaymentCard`, `StoreEncaissementVenteController`),
  supports de trésorerie, plan comptable par défaut — cf. [encaissements.md](../encaissements.md)

## Contexte

La fenêtre d'encaissement proposait une liste fixe (Espèces, Orange Money, Kulu, Soutra Money, MOMO,
PayCard, Virement, Chèque) et le compte débité était déduit du seul opérateur. Seul Orange Money
(561100) et MOMO (561200) avaient un compte dédié : un paiement Kulu retombait sur le compte générique
561000, qu'aucun support n'affichait. Constaté le 24/09/2026 : 1 000 000 GNF Orange Money visibles
sur « Mobile Money de Matoto », puis 500 000 GNF Kulu invisibles.

## Décision

1. **Chaque Mobile Money est un compte à part**, porté par son propre support de trésorerie, qui
   enregistre son opérateur (`compta_supports_tresorerie.operateur_mobile_money`). Le plan comptable
   par défaut donne un compte à chaque opérateur saisissable (561400 Kulu, 561500 PayCard, 561600
   Soutra Money, en plus de 561100/561200).
2. **Un moyen de paiement n'est proposé et accepté que si un support actif de l'agence de la facture
   peut le recevoir** : Mobile Money par opérateur, virement et chèque par banque. Liste construite
   par `MoyensEncaissementResolver`, rejouée à l'identique côté serveur.
3. **Le support choisi détermine le compte débité** (`encaissements_ventes.compte_tresorerie_id`) et
   l'opérateur enregistré ; aucune valeur libre du navigateur n'est crue.
4. **Les espèces gardent leur règle propre à l'utilisateur** (ADR 0001, décision du 23/09/2026) :
   une seule option « Espèces », routée vers la caisse dédiée active de l'utilisateur connecté,
   jamais un choix entre les caisses des agents. Sans caisse, l'option reste visible mais désactivée
   avec son message.
5. `mode_paiement` reste l'une des 4 valeurs stables : aucune valeur par opérateur.

## Conséquences

- Ajouter un opérateur dans une agence = créer et valider son support ; aucun changement de code.
- Un support Mobile Money sans opérateur connu (ex. sur 561000) n'est proposé nulle part tant que
  son opérateur n'est pas renseigné.
- Deux supports Mobile Money d'une même agence ne peuvent pas partager un compte.
- Historique non reclassé : les encaissements Kulu antérieurs restent sur 561000 ; leur
  régularisation est une décision distincte (diagnostic : `encaissements:diagnostiquer-destination`).
- Hors périmètre : `PaymentDialogCompact` (paiements sortants : commissions, paie, cashback) garde sa
  liste actuelle.
