# ADR 0021 — Achats : bon de commande direct, validation par plafond du rôle, réception dans Logistique

- **Date** : 2026-10-07
- **Statut** : **accepté le 2026-10-07** (décisions de l'utilisateur pendant la conception du module Achats)
- **Périmètre** : bons de commande fournisseurs, Paramètres → Achats, Logistique → Réceptions,
  stock entrant. Lots 1 et 2 livrés ; factures fournisseurs, dette, paiement et comptabilité
  (lots 3 et 4) restent à faire.
- **Détail fonctionnel** : [docs/achats.md](../achats.md)

## Contexte

Avant cette décision, le module Achats n'était qu'un bon de commande avec une réception unique :
réception partielle = reliquat perdu, stock entré sur le site par défaut de celui qui réceptionne,
aucune validation, `exists:` sans contrôle d'organisation (une variante d'une autre organisation
pouvait être commandée puis mise en stock), produits à plusieurs variantes impossibles à commander,
annulation et réception sous la même permission `achats.update`.

## Décision

1. **Pas de demande d'achat.** Le circuit part directement du bon de commande :
   bon de commande → validation → réception (Logistique) → facture → dette → paiement.
2. **Validation = permission `achats.valider` ET plafond du rôle.** Le plafond se règle par rôle
   (Paramètres → Achats), avec les agences couvertes. Égalité autorisée ; pas de règle = rien à
   valider ; « sans limite » est un choix explicite. Utilisateur à plusieurs rôles : la règle la
   plus favorable qui couvre l'agence. Contrôle côté serveur, sous verrou, montant relu.
3. **Aucune exception pour le super administrateur** : il lui faut aussi une règle de plafond, et la
   séparation des tâches s'applique à lui. Diffère volontairement des dépenses (DEPVAL-001), dont
   les règles ne changent pas.
4. **Séparation des tâches** : ni le créateur du bon, ni le dernier utilisateur ayant modifié son
   contenu ne peuvent le valider.
5. **Snapshot à la validation** : fournisseur, agence, libellé et référence (SKU) de chaque ligne,
   montant validé et règle de plafond appliquée (rôle, plafond, agences) sont figés.
6. **Annulation** (permission `achats.annuler`, motif) possible quel que soit le plafond, tant que
   rien n'a été reçu ; ensuite seule la clôture du reliquat est possible.
7. **Réception uniquement dans Logistique → Réceptions** (onglet « Commandes fournisseurs »,
   permission `receptions.create`), en plusieurs fois, bornée par le reliquat. Le stock entre dès
   l'enregistrement, sur l'agence de la commande, par `MouvementStockService::appliquer()`. Le
   `prix_achat` de la variante est mis à jour, sauf s'il atteindrait le prix de vente d'un produit
   soumis à la règle de marge (avertissement, réception jamais bloquée).
8. **Lecture** : commandes des agences de l'utilisateur, plus celles qu'il a créées ou validées.
9. **Notifications** (base + push, après commit, en file) : création → validateurs potentiels et
   super administrateurs ; validation → créateur et réceptionnaires de l'agence ; annulation →
   créateur.

## Conséquences

- `achats.update` ne sert plus qu'à modifier un bon non validé. Migration de complément (ADR 0011) :
  tout rôle qui avait `achats.update` reçoit `achats.annuler`, `receptions.read` et
  `receptions.create` ; `admin_entreprise` reçoit `achats.valider`. Aucune règle de plafond n'est
  créée : tant qu'elles ne sont pas configurées, aucun bon ne peut être validé.
- Les commandes historiques `ACH-…` (statut `en_cours`) sont traitées comme « à valider ».
- `ValidationParPlafondService` est générique (colonne `domaine`) : les dépenses pourront s'y
  brancher lors de leur refonte.
