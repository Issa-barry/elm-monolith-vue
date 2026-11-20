# ADR 0068 — Paiement groupé des fiches de commission

- **Date** : 2026-11-19
- **Statut** : accepté le 2026-11-19 (demande de l'utilisateur)
- **Complète** : [ADR 0009](0009-paiement-de-fiche-decaissement-tresorerie.md) (paiement de fiche =
  décaissement réel) et [ADR 0061](0061-agences-payeuses-des-commissions.md) (agences payeuses)
- **Détail fonctionnel** : [docs/commissions.md](../commissions.md), § Paiement groupé

## Contexte

La plupart des livreurs ne viennent plus chercher leur commission en espèces. La comptabilité
effectue des dépôts Orange Money, puis revient dans l'application et paie chaque livreur un par un :
une fenêtre de paiement, une confirmation et un rechargement de page par livreur. Avec des dizaines
de livreurs par quinzaine, c'est long et propice aux oublis.

## Décision

1. **Sélection puis « Marquer comme payés »** sur l'écran Commissions des livreurs. La case à cocher
   d'une ligne sert à la validation (parts à valider) comme au paiement (fiche due que l'utilisateur
   peut payer). La barre de sélection propose les deux actions, chacune avec son nombre de lignes.
2. **Même chaîne que le bouton Payer** : chaque fiche reçoit un `PaiementFichePaiement` ordinaire,
   alloué aux parts et comptabilisé comme un paiement unitaire (`PaiementFicheService`, partagé avec
   `PaiementFichePaiementController::store()`). Il n'y a pas de type de paiement « groupé ».
3. **Reste entier** : chaque fiche est payée pour la totalité de son reste. Un paiement partiel se
   fait toujours fiche par fiche, avec le bouton Payer.
4. **Un seul support, une seule agence payeuse** : le groupe sort d'un seul compte (le compte
   Orange Money de l'agence, par exemple). Une sélection payée par plusieurs agences est refusée ;
   l'utilisateur filtre par agence et paie agence par agence.
5. **Référence** : une référence commune, appliquée à chaque ligne sans référence propre, et une
   référence par ligne si chaque dépôt a la sienne. Si le moyen exige une référence, chaque fiche
   doit en avoir une.
6. **Date de paiement** saisie, aujourd'hui par défaut, jamais dans le futur : les dépôts peuvent
   précéder la saisie.
7. **Tout ou rien par lot** (`POST /comptabilite/fiches/paiements/lot`, 50 fiches au plus ; l'écran
   envoie des lots de 20). Sous verrou, le serveur vérifie pour chaque fiche les droits
   (`PaiementFichePolicy::payer`), la période validée, l'agence, le reste, puis le solde du support
   pour le total du lot. Un refus annule tout le lot. Les lots déjà passés restent enregistrés.
8. **Reste modifié depuis l'affichage = refus** : l'écran envoie le reste qu'il a affiché. S'il ne
   correspond plus au reste réel, le lot est refusé et l'utilisateur recharge. Une fiche déjà soldée
   est ignorée, ce qui rend sûr le renvoi d'un lot après une coupure.
9. Notification du bénéficiaire et journal d'audit (« paiement groupé ») **après** validation de la
   transaction, fiche par fiche. Depuis ce chantier, le paiement unitaire notifie lui aussi après la
   validation : aucune notification pour un paiement annulé.

## Options écartées

- **Simple case « payé » sans décaissement** : les soldes de trésorerie seraient faux, ce que l'ADR
  0009 interdit.
- **Un paiement par agence dans un même envoi** : un moyen choisi n'existe que dans une agence ; il
  faudrait un choix de compte par agence dans la fenêtre. Le filtre Agence couvre le besoin.
- **Lot unique pour toute la sélection** : une grande sélection risque le délai maximal du serveur
  mutualisé, comme pour la validation en masse.

## Conséquences

- Le bouton Payer et la fenêtre de paiement unitaire restent inchangés.
- Les écrans Propriétaires, Sites et Consultants n'ont pas encore la sélection pour paiement ; le
  point d'entrée et la fenêtre (`PaiementGroupeDialog`) sont prêts pour eux.
