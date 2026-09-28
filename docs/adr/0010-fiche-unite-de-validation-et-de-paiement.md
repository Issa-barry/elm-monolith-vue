# ADR 0010 — La fiche de paiement, unité de validation et de paiement

- **Date** : 2026-09-28
- **Statut** : accepté ; **lot 1 livré le 2026-09-28** (socle et protection) ; lots 2 (validation et
  paiement par fiche) et 3 (écrans, comptabilité, documentation) à venir
- **Remplace en partie** : [ADR 0008](0008-validation-automatique-des-periodes-de-paiement.md)
  (points 1 et 3, à l'issue du lot 2)
- **Périmètre** : fiches de paiement Livreur, Propriétaire, Site, Consultant (et Salarié pour la
  protection) — cf. [commissions.md](../commissions.md)

## Contexte

Le paiement exigeait que toute la période soit validée, alors que le métier valide véhicule par
véhicule et veut payer dès qu'un bénéficiaire est prêt. Par ailleurs, le recalcul d'une période
supprimait puis recréait toutes les fiches non soldées : une fiche partiellement payée disparaissait,
et ses paiements avec elle (suppression en cascade). Une fiche déjà payée ne pouvait pas non plus
recevoir de commission ou de dépense tardive (contrainte d'unicité période × bénéficiaire).

## Décision

1. **La fiche est l'unité de validation et de paiement.** Une fiche est validée quand toutes ses
   commissions le sont ; une fiche validée est payable, quel que soit le statut de la période, qui
   devient un reflet de ses fiches. « Valider la période de paiement » reste un raccourci. *(lot 2)*
2. **Bénéficiaire sur plusieurs véhicules** : sa fiche n'est validée qu'une fois tous ses véhicules
   validés — pas de fractionnement de fiche par véhicule. *(lot 2)*
3. **Une fiche ayant reçu un paiement, même partiel, est définitivement figée** : jamais supprimée,
   jamais recréée, jamais modifiée dans ce qu'elle doit. *(lot 1)*
4. **Fiche complémentaire** : toute commission ou dépense arrivée ensuite pour ce bénéficiaire va
   sur une nouvelle fiche de la même période (`rang` 2, 3…, `fiche_origine_id` vers la fiche
   initiale). La fiche payée garde son historique intact. *(lot 1)*
5. **Report de déduction** : si une fiche complémentaire (ou une fiche imputant un report) a un
   solde négatif, rien n'est dû et le solde est reporté (`report_a_deduire`) ; il est imputé sur la
   prochaine fiche du bénéficiaire, dans la même période si possible, sinon la suivante (ligne
   `report`). Aucune dette séparée à recouvrer. *(lot 1)*
6. **Validation automatique** : conservée pour Propriétaire, Site, Consultant (leur fiche devient
   payable dès la génération, lot 2) ; **les livreurs restent en validation manuelle, même seuls sur
   leur véhicule**. *(lot 2)*
7. **Après tout ajustement manuel**, la fiche repasse « À valider » : pas de revalidation automatique.
   *(lot 2, règle déjà appliquée au niveau des commissions)*
8. **Reprise** : les fiches des périodes déjà validées ou clôturées reçoivent la date et l'auteur de
   validation de leur période, sans aucune autre modification. *(lot 1)*

## Lot 1 — livré

- **Base** : `paiement_fiche_paiements.fiche_id` passe de suppression en cascade à suppression
  interdite ; nouvelles colonnes `rang`, `fiche_origine_id`, `report_a_deduire`, `validated_at`,
  `validated_by` ; unicité (période, type, bénéficiaire, **rang**).
- **Modèle** (`PaiementFiche::estFigee()`) : suppression (douce ou définitive) et modification des
  montants refusées pour une fiche figée ; lignes (`PaiementFicheLigne`) intouchables.
- **Recalcul** (`PeriodeCalculatorService::calculer()`) : ne reconstruit que les fiches non figées,
  n'y reprend jamais une ligne déjà portée par une fiche figée, crée la fiche complémentaire et gère
  le report. Corrige au passage un bug latent : une fiche entièrement payée conservée puis recréée
  pour le même bénéficiaire violait la contrainte d'unicité.
- **Reprise** : migration `2026_09_28_100100_reprise_validation_fiches_periodes_validees`, rejouable.

## Conséquences

- Au lot 1, le paiement reste conditionné à la validation de la période (inchangé) : le mécanisme
  de fiche complémentaire est en place mais ne se déclenche en pratique qu'au lot 2, lorsque le
  recalcul pourra toucher une période dont une fiche est déjà payée.
- **Limite existante non modifiée** : une fiche ordinaire (non complémentaire) dont les déductions
  dépassent les gains reste ramenée à 0 sans report, comme avant l'ADR.
- La numérotation « ADR 0009 » est déjà utilisée par le chantier « paiement de fiche = décaissement
  réel depuis un support de trésorerie » ; cet ADR prend donc le numéro 0010.
