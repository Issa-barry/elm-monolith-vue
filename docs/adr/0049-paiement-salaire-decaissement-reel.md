# ADR 0049 — Payer un salaire = décaissement réel depuis un support de trésorerie

- **Date** : 2026-11-01
- **Statut** : accepté (demande de l'utilisateur du 01/11/2026 : « se comporter comme le paiement
  commission », carte partagée et unique) — réalise le « chantier séparé » annoncé par l'ADR 0009
  et la partie paiement du lot 6 de l'ADR 0037
- **Périmètre** : Comptabilité → Paiement salaire, fiche du salarié, ancien écran Paie, `PaymentCard`,
  trésorerie — cf. [paie.md](../paie.md), [encaissements.md](../encaissements.md)

## Contexte

Un paiement de salaire enregistrait un mode générique (`especes`, `mobile_money`…) et la comptabilité
le rangeait sur un compte générique (571000, 561000) : aucune caisse ni aucun compte réel ne baissait,
aucun solde n'était contrôlé, et les espèces ne passaient pas par la caisse dédiée. Le dialogue
(`PaymentDialogCompact`) proposait une liste fixe de quatre modes, sans opérateur ni référence, alors
que les commissions utilisent `PaymentCard` depuis l'ADR 0009. Constaté en formation le 01/11/2026.

## Décision

1. **Mêmes règles que le paiement d'une fiche (ADR 0009), sans règle parallèle** : espèces = caisse
   dédiée active du payeur ; Mobile Money, virement, chèque = support actif de l'agence
   (`DecaissementSupportResolver`, `MoyensEncaissementResolver`). Référence selon le moyen.
2. **Agence de trésorerie** = agence du salarié (`DecaissementSalaireResolver`). Salarié sans agence →
   site central de trésorerie (ADR 0017) ; sans site central, paiement impossible. Jamais l'agence de
   l'utilisateur, jamais un choix manuel.
3. **Solde garanti sous verrou** (`garantirSoldeSuffisant()`), dans la transaction du paiement, après
   les contrôles existants (droits, période validée, reste à payer). Refus = aucun effet. Le paiement
   global contrôle le **montant total** : la répartition par mois ne sort l'argent qu'une fois.
4. **Un seul service pour les trois chemins** (`PaiementSalaireService`, ADR 0037) : ligne de paie,
   paiement global, ancienne route Paie RH. Mêmes règles de validation (`regles()`) : l'ancienne route
   ne reste pas un contournement.
5. **Comptabilité** : la ligne de trésorerie vise le compte du support débité ; la pièce est rattachée
   à l'agence d'où sort l'argent, avec son auteur. Le débit reste la charge de salaire (pas
   d'engagement préalable, toujours au lot 6).
6. **Données** : `paie_paiements` reçoit `site_id`, `moyen_paiement_detail`, `reference_paiement`,
   `compte_tresorerie_id`, `created_by`, tous nullables. Un paiement antérieur garde sa résolution par
   `compta_mappings`, jamais reclassé. Un support référencé par un paiement de salaire n'est pas
   supprimable (ADR 0028).
7. **Interface** : `PaymentCard` en mode `decaissement` sur les trois écrans. Le comportement commun du
   dialogue est extrait dans `useDecaissement` (partagé avec `usePaiementFiche`). Une ligne que
   l'utilisateur ne peut pas payer ne reçoit aucune donnée de trésorerie et n'affiche pas « Payer ».

## Conséquences

- **Changement pour les comptables** : payer exige désormais un moyen réel et approvisionné. Sans
  caisse dédiée active sur l'agence du salarié, pas d'espèces ; sans support dans cette agence, aucun
  autre moyen ; solde insuffisant, paiement refusé. Une agence doit être alimentée avant la paie
  (mouvement de fonds, approvisionnement de caisse, ADR 0018).
- Payer les salariés d'une autre agence en espèces demande une caisse dédiée du payeur sur cette
  agence : l'argent sort de l'agence du salarié, jamais de celle du payeur.
- Les paiements de salaire déjà enregistrés ne sont pas repris : leur sortie reste sur le compte
  générique. Toute régularisation est une décision métier explicite.
- `PaymentDialogCompact` reste utilisé par l'écran Commission Logistique (paiement direct historique,
  hors périmètre ADR 0009) : il ne sert plus à aucun paiement de salaire.
- Reste du lot 6 (ADR 0037) : écriture d'engagement (charge / dette) à la validation de la période.
