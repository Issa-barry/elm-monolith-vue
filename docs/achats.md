# Achats — bons de commande fournisseurs

Décision structurante : [ADR 0021](adr/0021-achats-bon-de-commande-valide-par-plafond-de-role.md).
Module `module.achats` (désactivé par défaut).

## Circuit

```text
Bon de commande ──► À valider ──valider (permission + plafond + séparation des tâches)──► Validée
                        │                                                                  │
                        └── annuler (motif) ──► Annulée          Logistique → Réceptions : 1..n réceptions
                                                                                            ▼
                                       Partiellement réceptionnée ──► Réceptionnée (ou Clôturée : reliquat abandonné)
```

Factures fournisseurs, dette, paiement et écritures comptables : lots 3 et 4, pas encore livrés.

## Règles

| Code | Règle |
|---|---|
| ACH-001 | Un bon de commande a une agence de livraison (dans le périmètre du créateur), un fournisseur actif et au moins une ligne. Agence, fournisseur et variantes doivent appartenir à l'organisation. |
| ACH-002 | Un bon est modifiable (`achats.update`) tant qu'il est à valider. Chaque modification enregistre son auteur (`contenu_modifie_par`). |
| ACH-003 | Valider exige la permission `achats.valider` ET une règle de plafond d'un des rôles de l'utilisateur qui couvre l'agence, avec montant ≤ plafond (égalité autorisée). Sans règle : refus. Plusieurs rôles : la règle la plus favorable. |
| ACH-004 | Le super administrateur est soumis à ACH-003 et ACH-005, sans exception. |
| ACH-005 | Le créateur du bon et le dernier modificateur de son contenu ne peuvent pas le valider. |
| ACH-006 | À la validation sont figés : montant, nom du fournisseur, nom de l'agence, libellé et référence des lignes, règle de plafond appliquée (rôle, plafond, agences). Fiche et PDF lisent ce snapshot. |
| ACH-007 | Annuler (`achats.annuler`, motif obligatoire) est possible quel que soit le plafond, tant qu'aucune quantité n'a été reçue. |
| ACH-008 | Une commande partiellement reçue peut être clôturée (`achats.annuler`, motif) : le reliquat n'est plus attendu. |
| ACH-009 | Réception (`receptions.create`, agence de la commande dans le périmètre de l'utilisateur) : uniquement depuis Logistique → Réceptions, sur une commande validée ou partiellement reçue. Chaque quantité ≤ reliquat, relu sous verrou ; sinon refus sans aucun effet. |
| ACH-010 | Le stock entre dès l'enregistrement de la réception, sur l'agence de la commande. Le coût unitaire (prix de la commande) est figé sur la ligne de réception ; le mouvement de stock porte le motif « Réception achat — référence du bon ». |
| ACH-011 | Le `prix_achat` de la variante prend le coût reçu, sauf pour un produit vendable dont la marge se calcule sur le prix d'achat si ce coût atteint le prix de vente : avertissement orange, réception non bloquée. |
| ACH-012 | Lecture (`achats.read`) : commandes des agences de l'utilisateur, plus celles qu'il a créées ou validées. |
| ACH-013 | Notifications après commit : création → validateurs potentiels (permission + plafond suffisant) et super administrateurs ; validation → créateur et utilisateurs de l'agence ayant `receptions.create` ; annulation → créateur. Jamais l'auteur de l'action. |
| ACH-014 | PDF : filigrane « NON VALIDÉ » tant que le bon n'est pas validé, « ANNULÉ » s'il est annulé. |

## Paramètres → Achats

Une ligne par rôle (super administrateur compris) : plafond en GNF ou « sans limite », et agences
couvertes (toutes, son agence, agences sélectionnées). Pas de plafond = le rôle ne valide rien. La
permission `achats.valider` se coche dans l'écran Rôles. Permission de la page : `parametres.update`.

## Permissions

| Permission | Usage |
|---|---|
| `achats.read` / `create` / `update` / `delete` | lire, créer, modifier un bon à valider, supprimer un bon annulé |
| `achats.valider` | valider (borné par le plafond du rôle) |
| `achats.annuler` | annuler, clôturer un reliquat |
| `receptions.read` / `receptions.create` | Logistique → Réceptions → Commandes fournisseurs |

## Données

- `commandes_achats` : `site_id`, `numero`, `contenu_modifie_par/at`, `validee_at/par`,
  `montant_valide`, `fournisseur_nom_snapshot`, `site_nom_snapshot`, `validation_regle_snapshot`,
  `cloturee_at/par`, `motif_cloture`. Références `BC-JJMMAA-NNN` ; les `ACH-…` historiques restent.
- `commande_achat_lignes.reference_snapshot` (SKU) ; `qte_recue` = cumul des réceptions.
- `receptions_achats` (`RCA-JJMMAA-NNN`) et `reception_achat_lignes` (coût, mouvement de stock).
- `regles_validation_roles` : organisation, domaine (`achats`), rôle, plafond, sans limite,
  périmètre, agences.

## Historique

Commandes antérieures au 07/10/2026 (`en_cours`) : traitées comme « à valider » ; sans agence, elles
doivent être modifiées (agence + fournisseur) avant validation.
