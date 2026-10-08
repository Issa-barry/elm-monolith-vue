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
| ACH-000 | **Périmètre « Peut acheter pour »** : règle par rôle (son agence / agences sélectionnées / toutes les agences). Il gouverne la création, la lecture et la validation. Sans règle couvrant l'agence : aucun accès, admin_entreprise et super administrateur compris. |
| ACH-001 | Créer exige `achats.create` et une règle d'un des rôles couvrant l'agence de livraison (seules ces agences sont proposées, et le serveur refuse les autres). Fournisseur actif, au moins une ligne ; agence, fournisseur et variantes de l'organisation. |
| ACH-002 | Un bon est modifiable (`achats.update`) tant qu'il est à valider. Chaque modification enregistre son auteur (`contenu_modifie_par`). |
| ACH-003 | Valider exige `achats.valider` ET une règle couvrant l'agence avec montant ≤ plafond (égalité autorisée). Règle sans plafond : ne valide rien. Plusieurs rôles : le plafond le plus élevé parmi les règles couvrant l'agence. |
| ACH-004 | Aucun passe-droit : super administrateur et admin_entreprise suivent ACH-000, ACH-003 et ACH-005 comme tout rôle. Règles de départ (modifiables) : « toutes agences, sans limite » pour ces deux rôles, créées par migration et à l'installation. |
| ACH-005 | Le créateur du bon et le dernier modificateur de son contenu ne peuvent pas le valider. |
| ACH-006 | À la validation sont figés : montant, nom du fournisseur, nom de l'agence, libellé et référence des lignes, règle de plafond appliquée (rôle, plafond, agences). Fiche et PDF lisent ce snapshot. |
| ACH-007 | Annuler (`achats.annuler`, motif obligatoire) est possible quel que soit le plafond, tant qu'aucune quantité n'a été reçue. |
| ACH-008 | Une commande partiellement reçue peut être clôturée (`achats.annuler`, motif) : le reliquat n'est plus attendu. |
| ACH-009 | Réception (`receptions.create`, utilisateur rattaché à l'agence de la commande) : uniquement depuis Logistique → Réceptions, sur une commande validée ou partiellement reçue. Chaque quantité ≤ reliquat, relu sous verrou ; sinon refus sans aucun effet. |
| ACH-010 | Le stock entre dès l'enregistrement de la réception, sur l'agence de la commande. Le coût unitaire (prix de la commande) est figé sur la ligne de réception ; le mouvement de stock porte le motif « Réception achat — référence du bon ». |
| ACH-011 | Le `prix_achat` de la variante prend le coût reçu, sauf pour un produit vendable dont la marge se calcule sur le prix d'achat si ce coût atteint le prix de vente : avertissement orange, réception non bloquée. |
| ACH-012 | Lecture (`achats.read`) : bons des agences couvertes par ACH-000, plus ceux que l'utilisateur a créés ou validés. |
| ACH-013 | Notifications après commit : création → validateurs potentiels (permission + plafond suffisant) et super administrateurs dont le périmètre couvre le bon ; validation → créateur et utilisateurs de l'agence ayant `receptions.create` ; annulation → créateur. Jamais l'auteur de l'action. |
| ACH-014 | PDF : filigrane « NON VALIDÉ » tant que le bon n'est pas validé, « ANNULÉ » s'il est annulé. |

## Paramètres → Achats

Une ligne par rôle (super administrateur compris, rien n'est verrouillé) : case « Peut acheter
pour » (sans elle, aucun accès), agences couvertes, et plafond de validation en GNF ou « sans
limite » (vide = le rôle ne valide rien). Les permissions `achats.*` se cochent dans l'écran Rôles.
Permission de la page : `parametres.update`.

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
