# ADR 0007 — Rapport d'activité : un moteur, un périmètre serveur, des blocs indépendants

- **Date** : 2026-09-26
- **Statut** : accepté ; lot 1 (rapport d'activité + « Ma situation ») livré le 2026-09-26 ;
  lot 2 (Stock, Achats, Dépenses) et lot 3 (clôture journalière, écart de caisse) à venir
- **Périmètre** : [rapports.md](../rapports.md) (RAP-001 à RAP-009)

## Contexte

Chaque agent doit pouvoir faire sa situation (le soir ou à tout moment) : ses ventes, ses
encaissements, ses impayés et partiels, ses Mobile Money avec leurs références. Les responsables ont
besoin de la même vue par agence et pour l'organisation. Le tableau de bord existant est une synthèse
graphique, non filtrée par agence.

## Décision

1. **Tableau de bord conservé** tel quel ; nouvelles entrées **Ma situation** (tous) et
   **Rapports › Rapport d'activité**. Un seul rapport à onglets, pas un rapport par sujet.
2. **Un moteur, un périmètre** : les deux écrans et les exports passent par
   `RapportPerimetreResolver` puis `RapportActiviteService`. Le périmètre est imposé côté serveur ;
   les paramètres du navigateur ne font que le restreindre. Pas de filtre « Périmètre » : il se déduit
   des filtres Agence/Agent et des permissions (`rapports.read_own`, `rapports.read`), jamais du nom
   d'un rôle.
3. **Date d'une vente = création de sa facture** (`factures_ventes.created_at`) : toute vente réelle a
   une facture, le brouillon n'en a pas, `validated_at` n'est pas renseigné partout, le PDV n'a pas de
   livraison, et le tableau de bord utilise déjà cette date.
4. **Blocs indépendants** : ventes (agent = vendeur, date de facture), encaissements (agent = auteur,
   `date_encaissement`), créances (état actuel, toutes dates). Aucun « reste » calculé par différence
   entre blocs — l'agent qui encaisse n'est pas toujours celui qui a vendu, et un encaissement du jour
   solde souvent une vente ancienne.
5. **Créances à l'état actuel** en lot 1 ; la reconstitution à une date passée est une évolution.
6. **Caisse = grand livre** (solde de début + mouvements = solde de fin), dans un service et un
   composant réutilisés par la future fiche caisse. L'onglet s'appelle « Caisse » : sans comptage
   physique, aucun écart n'est affiché.
7. **Consultation seulement** en lot 1 : pas de clôture, de comptage ni de régularisation.

## Conséquences

- Une annulation, un retour ou un encaissement antidaté modifie après coup les chiffres d'une période
  passée : documenté, et les encaissements saisis un autre jour sont signalés.
- Les références Mobile Money sont contrôlées dans toute l'organisation (doublons entre agents ou
  agences), avec le détail limité au périmètre de l'utilisateur.
- `SituationPeriode` devient partagée (paramètre d'URL et période par défaut configurables) ; le
  comportement de la fiche véhicule est inchangé.
- Le tableau de bord reste non filtré par agence : correction prévue dans un chantier séparé.

## Alternatives écartées

- Renommer le tableau de bord « Statistiques » ou y ajouter les rapports : mélange synthèse et
  justification.
- Un rapport par sujet (ventes, encaissements, créances, Mobile Money) : mêmes données éclatées.
- Onglet « Écart de caisse » dès le lot 1 : aucun montant compté n'existe, l'onglet serait vide ou
  trompeur.
