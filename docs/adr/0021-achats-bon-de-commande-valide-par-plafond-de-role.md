# ADR 0021 — Achats : bon de commande direct, validation par plafond du rôle, réception dans Logistique

- **Date** : 2026-10-07
- **Statut** : **accepté le 2026-10-07** (décisions de l'utilisateur pendant la conception du module Achats)
- **Périmètre** : bons de commande fournisseurs, Paramètres → Achats, Logistique → Réceptions,
  stock entrant. Lots 1 et 2 livrés ; factures fournisseurs et dette : lot 3, ADR 0022 ;
  paiement (lot 4) à faire.
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
2. **Un seul périmètre, « Peut acheter pour »**, porté par une règle par rôle (Paramètres → Achats,
   table `regles_validation_roles`, domaine `achats`) : son agence, agences sélectionnées ou toutes
   les agences. Il gouverne les TROIS actions :
   - **créer** = `achats.create` + une règle d'un des rôles de l'utilisateur couvrant l'agence ;
   - **voir** = `achats.read` + règle couvrant l'agence (le créateur et le validateur d'un bon le
     voient toujours) ; *amendé le 2026-10-10 par l'[ADR 0025](0025-vision-360-consultation-de-toutes-les-agences.md) :
     la permission `sites.lecture_toutes_agences` ouvre aussi la consultation, et elle seule* ;
   - **valider** = `achats.valider` + règle couvrant l'agence + montant ≤ plafond de cette règle
     (égalité autorisée ; règle sans plafond = ne valide rien ; « sans limite » explicite ; plusieurs
     rôles : le plafond le plus élevé parmi les règles couvrant l'agence, sans hiérarchie de rôles).
   Tout est contrôlé côté serveur, sous verrou pour la validation (montant relu).
3. **Aucun passe-droit, ni admin_entreprise ni super administrateur** : sans règle, aucun accès.
   Aucune condition `isAdmin()` / `hasRole('super_admin')` dans le moteur, la policy ou les
   contrôleurs. Le `Gate::before` du super administrateur ne laisse passer que la permission :
   périmètre, plafond et séparation des tâches sont vérifiés hors de lui (contrôle explicite dans
   les contrôleurs et le service). Les dépenses gardent leur exception DEPVAL-001, non modifiée.
   **Règles de départ** (données, modifiables) : admin_entreprise et super_admin reçoivent
   « toutes agences, sans limite » — migration pour les organisations existantes (sans écraser une
   règle déjà configurée), `InstallationService` pour les nouvelles.
4. **Séparation des tâches, réglable par rôle** (révisé le 2026-10-09) : par défaut, ni le créateur
   du bon ni le dernier utilisateur ayant modifié son contenu ne peuvent le valider. La règle du rôle
   peut l'autoriser (« Peut valider ses propres bons », Paramètres → Achats) : l'auteur valide alors
   son bon, toujours dans la limite de la permission, du périmètre et du plafond de cette règle.
   Activé par défaut pour le super administrateur (règle de départ et migration des règles
   existantes), désactivé pour les autres rôles. C'est une donnée de configuration, jamais une
   condition sur le rôle dans le code. *Avant le 09/10/2026 : séparation obligatoire pour tous,
   super administrateur compris — décision remplacée à la demande de l'utilisateur.*
5. **Snapshot à la validation** : fournisseur, agence, libellé et référence (SKU) de chaque ligne,
   montant validé et règle de plafond appliquée (rôle, plafond, agences) sont figés.
6. **Annulation** (permission `achats.annuler`, motif) possible quel que soit le plafond, tant que
   rien n'a été reçu ; ensuite seule la clôture du reliquat est possible.
7. **Réception uniquement dans Logistique → Réceptions** (onglet « Commandes fournisseurs »,
   permission `receptions.create`), en plusieurs fois, bornée par le reliquat. Le stock entre dès
   l'enregistrement, sur l'agence de la commande, par `MouvementStockService::appliquer()`. Le
   `prix_achat` de la variante est mis à jour, sauf s'il atteindrait le prix de vente d'un produit
   soumis à la règle de marge (avertissement, réception jamais bloquée).
8. **Réception** : réservée aux utilisateurs rattachés à l'agence de la commande (sans passe-droit
   de rôle).
9. **Notifications** (base + push, après commit, en file) : création → validateurs potentiels et
   super administrateurs ; validation → créateur et réceptionnaires de l'agence ; annulation →
   créateur.
10. **Deux agences par bon** (ajouté le 2026-10-10, décision de l'utilisateur : les achats sont le
    plus souvent faits par la trésorerie principale pour les autres agences, parfois délégués) :
    - **agence de livraison** (`site_id`) : elle réceptionne et reçoit le stock (points 7 et 8) ;
    - **agence payeuse** (`site_payeur_id`) : elle porte la facture, la dette fournisseur, le
      paiement, les écritures et l'obligation du Financement des agences. Proposée par défaut : la
      trésorerie principale (ADR 0017) si le périmètre de l'utilisateur la couvre, sinon l'agence de
      livraison. Choisie sur chaque bon, figée à la validation.
    Périmètre « Peut acheter pour » (point 2), sans nouveau réglage :
    - **créer, modifier, valider, annuler, clôturer, supprimer** : le périmètre doit couvrir LES
      DEUX agences — pouvoir acheter pour une agence ne permet pas d'engager la trésorerie d'une
      autre. Le plafond de validation est celui d'une règle couvrant l'agence payeuse ;
    - **voir** : une des deux agences suffit (plus créateur et validateur, et ADR 0025), sans
      donner le droit d'agir.
    Un achat délégué (l'agence commande, réceptionne et paie, au besoin après un financement par
    mouvement de fonds) est un bon dont les deux agences sont la même. Les bons antérieurs sont
    migrés avec agence payeuse = agence de livraison : aucun changement pour eux. Pas de dette
    entre agences : la charge reste à l'agence payeuse.

## Conséquences

- `achats.update` ne sert plus qu'à modifier un bon non validé. Migration de complément (ADR 0011) :
  tout rôle qui avait `achats.update` reçoit `achats.annuler`, `receptions.read` et
  `receptions.create` ; `admin_entreprise` reçoit `achats.valider`. Aucune règle de plafond n'est
  créée : tant qu'elles ne sont pas configurées, aucun bon ne peut être validé.
- Les commandes historiques `ACH-…` (statut `en_cours`) sont traitées comme « à valider ».
- `ValidationParPlafondService` est générique (colonne `domaine`) : les dépenses pourront s'y
  brancher lors de leur refonte.
