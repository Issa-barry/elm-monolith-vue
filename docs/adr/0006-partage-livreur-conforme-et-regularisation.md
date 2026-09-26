# ADR 0006 — Partage Livreur conforme obligatoire et régularisation des commissions partielles

- **Date** : 2026-09-24
- **Statut** : accepté ; lot 1 livré le 2026-09-24, lot 2 (brouillon de barème + reconfiguration
  groupée + publication atomique) livré le 2026-09-25 ; lot 3 (import/export Excel des partages,
  publication programmée à une date future) à venir
- **Périmètre** : barèmes Paramètres → Commissions, partages Livreur d'équipe, création/chargement
  de commande, création de transfert, relance de génération — cf. [commissions.md](../commissions.md)
  (COMM-015 à COMM-018)

## Contexte

Le partage Livreur d'une équipe est un montant fixe par livreur, dont la somme doit égaler le
barème Livreur de la catégorie. Modifier le barème (ex. 800 → 1 000 GNF/pack) ne touchait aucun
partage : avec plusieurs centaines de véhicules, les commandes restaient acceptées (le contrôle de
création ne vérifiait que l'existence d'un partage), la cible Livreur échouait à la génération
(tentative PARTIEL) et :

- « Relancer la génération » ne faisait rien dès qu'une enveloppe existait (R3) ;
- une régénération à une date passée ne retrouvait plus un barème remplacé depuis et calculait 0
  (R1) — contrairement à ce qu'affirmait COMM-014 ;
- un partage corrigé le jour J n'était pas actif à la date d'origine d'une vente partielle : même
  corrigée, la relance aurait échoué.

## Décision

1. **Partage conforme = somme exacte + chaque membre actif présent (0 GNF accepté)**, jugé par le
   seul `CommissionPartageLivraisonValidator`. Ce contrôle **bloque** la création et la
   modification de commande (catégories présentes seulement), la création de transfert et
   l'enregistrement de l'équipe ; il est rejoué au **chargement** comme filet de sécurité ; il ne
   bloque **jamais l'encaissement**. Le véhicule n'est jamais désactivé.
2. **Barème résolu par date**, règle remplacée comprise ; un brouillon n'est jamais applicable.
3. **Relance = complétion** des seules cibles manquantes d'une génération PARTIELLE, à la date de
   gain d'origine, sans jamais recréer une enveloppe existante ; refusée avec motif si la période de
   paiement de la cible est validée/clôturée.
4. **Option A** : une correction de partage qui remplace une version non conforme au barème en
   vigueur prend effet à la date d'effet de ce barème ; tout autre changement prend effet
   immédiatement.
5. Commande de diagnostic en lecture seule (`commissions:diagnostiquer-partages`) à exécuter avant
   la mise en production.
6. **Lot 2 — changement de barème en deux temps.** Un barème qui rend non conformes des partages
   d'équipe est préparé dans un brouillon (un par processus), les partages concernés sont
   reconfigurés dans une grille groupée (proposition proportionnelle, reliquat au chauffeur,
   jamais appliquée seule), puis barème + partages sont publiés dans une seule transaction à la
   même date d'effet — refusée tant qu'un partage concerné n'est pas conforme, si une équipe a
   changé depuis sa préparation, ou si la configuration a été modifiée par un autre chemin. Un
   barème sans impact sur les partages reste appliqué immédiatement.
7. **Équipe à un seul livreur actif** (décision du 25/09/2026) : sa part suit automatiquement le
   barème (version de partage écrite par le système, même date d'effet), sans grille ni
   brouillon — il n'y a aucune répartition à décider. Seules les équipes à plusieurs livreurs
   actifs exigent un partage explicite. Choix d'une écriture matérialisée plutôt que d'une règle
   implicite à la génération : moteur inchangé, partage affiché = partage payé, historique daté.

Options écartées : désactiver les véhicules concernés (mélange un indicateur structurel —
véhicule doté d'une équipe — et une configuration de commission ; arrêt des ventes) ; recalculer
automatiquement les partages (modifie en silence la rémunération de chaque livreur, arrondis
indécidables) — une proposition assistée, jamais appliquée seule, est prévue au lot 2.

## Conséquences

- Dès la mise en production, toute équipe non conforme ne peut plus prendre de commande sur la
  catégorie concernée : exécuter le diagnostic avant, corriger les équipes listées.
- La génération ne contrôle toujours que la somme (un membre sans ligne n'a aucun effet financier).
- Sous le déclencheur `FACTURE_ENCAISSEE`, un PARTIEL reste possible si le partage change entre
  chargement et encaissement ; il est désormais régularisable.
- Une relance après échec total (`erreur`) reste à la date du jour (comportement inchangé).
- Lot 2 : plus aucune fenêtre où barème et partages divergent après un changement de barème ; le
  formulaire normal d'équipe reste utilisable pendant un brouillon (validé contre le barème en
  vigueur), la publication revérifie tout.
- La grille charge toutes les (équipe, catégorie) concernées et filtre/pagine côté navigateur pour
  que les saisies non enregistrées survivent aux filtres ; seules les équipes concernées sont
  chargées (quelques centaines de lignes au plus).
