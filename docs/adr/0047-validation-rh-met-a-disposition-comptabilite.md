# ADR 0047 — La validation RH met la paie à disposition de la Comptabilité

- **Date** : 2026-10-31
- **Statut** : accepté (décisions de l'utilisateur du 31/10/2026) — révise l'ADR 0040 (étape
  « Transmettre à la Comptabilité »)
- **Périmètre** : RH → Transmission de paie, Comptabilité → Paiement salaire — cf.
  [transmission-paie.md](../transmission-paie.md), [paie.md](../paie.md)

## Contexte

L'ADR 0040 prévoyait trois actions RH : préparer, valider (figer), puis transmettre. Dans une
application unique où RH et Comptabilité partagent les mêmes données, la transmission faisait doublon
avec la validation (qui fige déjà), exposait à l'oubli, et la Comptabilité ne voyait rien avant. De
plus, une version validée fige le mois côté RH : sans retour possible, une prime oubliée ne pouvait
plus être corrigée sur le mois, même avant toute reprise comptable.

## Décision

1. **Valider = figer + mettre à disposition** : la version validée prend directement le statut
   `transmise` (« Validée et figée par les RH ») et remplace la version disponible jusque-là. Plus de
   bouton « Transmettre » ; l'action ne subsiste que pour les versions validées avant cette décision.
2. **Deux validations distinctes conservées** : RH valide les éléments ; la Comptabilité reprend,
   contrôle, valide la période, paie et clôture. La validation RH ne déclenche ni paiement ni écriture.
3. **Brouillon visible et à jour** : la Comptabilité voit le brouillon en lecture seule et provisoire
   (`SourceDonneesPaie::brouillonEnCours`), avec l'étape de la paie du mois ; il n'est jamais repris ni
   payé. Le brouillon est recalculé à l'ouverture de l'écran RH (si les données ont changé, pour qui
   peut préparer la paie) et juste avant la validation, qui est alors refusée pour être revérifiée.
   Pas de recalcul en tâche de fond : pas de traitements concurrents ni de recalculs en cascade.
4. **Annuler la validation** tant que la Comptabilité n'a pas repris la version ni validé, payé ou
   clôturé le mois : motif obligatoire, droit de validation, version conservée (statut
   `validation_annulee`, contenu immuable, ne fige plus le mois), version précédente rétablie, nouveau
   brouillon ouvert automatiquement. Traçabilité par le journal (auteur, date, motif) et le motif du
   nouveau brouillon — aucune colonne ajoutée.
5. Après reprise, paiement ou clôture : protections inchangées (nouvelle version tant que la
   Comptabilité n'a pas validé le mois, puis régularisation sur le mois suivant).

## Conséquences

- Aucune migration : statut texte existant, nouvelle valeur `validation_annulee`.
- Permission `rh-transmissions-paie.transmettre` conservée dans le catalogue (rôles existants) mais
  plus utilisée hors versions antérieures.
- Le contenu d'une version annulée reste protégé comme une version figée (modèles).
