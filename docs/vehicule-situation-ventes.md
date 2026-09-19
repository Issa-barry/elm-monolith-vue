# Situation véhicule — dashboard (section Activité commerciale)

Onglet **Situation** de la fiche véhicule (`Vehicules/Show.vue`). C'est un **tableau de bord de
synthèse**, pas une liste de transactions : indicateurs, agrégations, graphiques. Le détail des
ventes reste sur l'écran **Ventes** (bouton « Voir les ventes », préfiltré sur le véhicule et la
période). Aucune nouvelle mécanique financière — uniquement de la lecture/agrégation de
`CommandeVente` / `FactureVente` / `EncaissementVente`.

## Contenu actuel (V1 — Activité commerciale)

- 4 KPI : CA vendu, Encaissé, Reste dû, Nombre de ventes.
- Graphique **Produits vendus** : barres horizontales, quantité vendue par produit.
- Graphique **Situation des paiements** : anneau Payé / Partiel / Dû + tableau
  (montant, % du montant, nombre de ventes, % des ventes).
- Filtre période : Tout / Mois / Année (calendaires : mois en cours, année en cours).

**Hors périmètre (sections à venir)** : commissions, dépenses, pannes/maintenance,
immobilisation, marge/rentabilité, comparaison de périodes.

## Règles métier

### Une vente « vendue » exclut le brouillon et l'annulation

Une `CommandeVente` rattachée au véhicule (`vehicule_id`) n'entre dans la Situation que si son
statut a dépassé `brouillon` et n'est pas `annulee` (`App\Enums\StatutCommandeVente`). Une
commande encore en brouillon n'a rien vendu ; une commande annulée non plus (décision produit du
15/09/2026). `vente_standard` et `distribution_client` (`docs/commissions.md` COMM-001) sont
additionnées : les deux sont commercialement des ventes à part entière du véhicule (COMM-004).

### CA, encaissé, reste dû

- **CA vendu** = Σ `commandes_ventes.total_commande` des ventes retenues.
- **Encaissé** = Σ `FactureVente::montant_encaisse` ; **Reste dû** = Σ `FactureVente::montant_restant`,
  sur les factures **non annulées** uniquement (même exclusion que l'écran Ventes,
  `IndexCommandeVenteController`).

### Situation des paiements (Payé / Partiel / Dû)

Une vente est classée selon le `statut_facture` de sa facture, tel que le calcule
`FactureVente::recalculStatut()` à chaque encaissement :

| Catégorie | `statut_facture` | Définition |
|---|---|---|
| **Payé** | `payee` | encaissé ≥ `montant_net` |
| **Partiel** | `partiel` | 0 < encaissé < `montant_net` |
| **Dû** | `impayee`, `creee` | aucun encaissement (« créée » = facture pas encore recalculée, même situation financière) |

Une facture n'a qu'**un** statut : les catégories sont **mutuellement exclusives**, sans double
compte. Chaque catégorie est valorisée au **montant facturé** (`montant_net`) de ses ventes ; leur
total redonne donc le facturé (= CA vendu tant que commande et facture sont synchronisées). Les
factures annulées sont exclues.

Conséquence à connaître : la part non encaissée d'une vente **partielle** reste dans « Partiel »
(le tableau l'indique : « dont reste X »). Le **Reste dû** global = « reste » de *Dû* + « reste »
de *Partiel*. « Dû » seul n'est donc pas égal au KPI Reste dû dès qu'il existe une vente partielle.

### Pourcentages

Deux pourcentages **distincts**, jamais mélangés (colonnes « Montant » et « Ventes » du tableau) :

- % du montant = montant de la catégorie ÷ montant total facturé × 100 ;
- % des ventes = nombre de ventes de la catégorie ÷ nombre total de ventes × 100.

Arrondis à 0,1 (la somme affichée peut différer de 100 de ±0,1 ; la ligne Total affiche 100 %).
Total nul → 0 %, jamais de division par zéro. Calculés côté backend
(`VehiculeSituationVentesService::pourcentage()`), le frontend ne fait qu'afficher.

### Quantité vendue et produits

Il n'existe pas de colonne `quantite_vendue` (`quantite_demandee`, `quantite_chargee`,
`quantite_livree` seulement). La quantité vendue reprend le repère de
`CashbackService::quantiteEligible()` : **`quantite_livree` si renseignée, sinon
`quantite_demandee`** (une vente sans étape de chargement/livraison — comptoir — n'a jamais de
`quantite_livree`).

Regroupement **par variante** (`variante_id`, grain transactionnel réel) : toutes les ventes d'une
même variante sont agrégées, aucun produit n'est codé en dur. Tri par quantité décroissante. Le
libellé est le snapshot figé à la vente (`libelle_snapshot`) de la vente la plus récente, avec
repli sur le nom du produit pour les lignes antérieures aux snapshots — comme
`CommandeVenteFormBuilder`.

### Lien « Voir les ventes »

Affiché seulement si `can('ventes.read')` **et** module Ventes actif (même garde que la sidebar).
Il ouvre `/backoffice/ventes?vehicule=<immatriculation>&date_debut=…&date_fin=…` : le filtre
« Véhicule » existant (recherche texte nom/immatriculation) et la période affichée, visibles et
modifiables dans la barre de filtres de Ventes. Le total peut différer de la Situation : Ventes
affiche aussi brouillons et annulées, et ne liste que les ventes standard (les distributions ont
leur écran `/backoffice/distributions`).

## Implémentation

Backend — une section = un service + une prop Inertia :
- `App\Services\Vehicules\VehiculeSituationVentesService::pourVehicule()` → prop
  `situation_ventes` (`kpis`, `produits`, `paiements`, `periode_debut`, `periode_fin`), appelée
  depuis `VehiculeController::show()`. Période via `?situation_periode=all|month|year`.
- `Vehicule::commandesVentes()` (relation inverse de `CommandeVente::vehicule()`).

Frontend :
- `Vehicules/partials/SituationTab.vue` : coque (titre, sélecteur de période partagé, sections).
- `Vehicules/partials/situation/` : `SituationSection` (enveloppe réutilisable d'une section),
  `SituationKpiCard`, `SituationVentesSection`, `ProduitsVendusChart`, `PaiementsChart`.
- Types partagés : `resources/js/types/vehicule-situation.ts`.
- Graphiques : `primevue/chart` (Chart.js) + `useChartTheme`, comme les widgets dashboard Apollo
  déjà présents (`components/dashboard/ventes/*`) — aucune dépendance ajoutée.
- Grilles adaptatives par **container queries** (Tailwind v4) : graphiques côte à côte dès que la
  largeur du contenu le permet, empilés sinon ; KPI empilés sur mobile.

Ajouter une section (finance, maintenance…) : un service + une prop côté backend, un composant
`Situation…Section` basé sur `SituationSection` côté frontend, ajouté dans `SituationTab.vue`.

### Couleurs

Statuts de paiement portés par la palette PrimeVue (variables CSS `--p-*`), étapes choisies avec
le validateur du guide de visualisation (écart entre les 3 tranches mutuellement adjacentes) :
clair = emerald-600 / amber-500 / red-600 ; sombre = emerald-600 / amber-600 / red-700. La couleur
n'est jamais le seul canal : chaque ligne du tableau porte un libellé et une icône (coche /
demi-disque / alerte). Produits : une seule couleur (primaire), une série, pas de légende.
Le vert/ambre pâle ayant un contraste < 3:1 sur fond clair, le tableau des paiements (chiffres
visibles) et le tableau accessible masqué des produits font office de vue tabulaire.

## Points hors périmètre identifiés (non traités)

- `resources/js/components/StatusDot.vue` (`STATUS_COLOR_MAP`) ne couvre pas la valeur de statut
  `facturation` (couleur grise par défaut) — gap préexistant, déjà présent sur `Ventes/Index.vue`.
- L'onglet Situation, comme l'onglet Dépenses, est visible avec `vehicules.read` seul (pas de
  `ventes.read` exigé pour lire les chiffres) ; seul le lien vers Ventes est conditionné.
