# Situation véhicule — dashboard (section Activité commerciale)

Onglet **Situation** de la fiche véhicule (`Vehicules/Show.vue`). C'est un **tableau de bord de
synthèse**, pas une liste de transactions : indicateurs, agrégations, graphiques. Le détail des
ventes reste sur l'écran **Ventes** (bouton « Voir les ventes », préfiltré sur le véhicule et la
période). Aucune nouvelle mécanique financière — uniquement de la lecture/agrégation de
`CommandeVente` / `FactureVente` / `EncaissementVente`.

## Contenu actuel (V1 — Activité commerciale)

- 4 KPI : CA vendu, Encaissé, Reste à payer, Nombre de ventes.
- Graphique **Produits vendus** : barres horizontales, quantité vendue par produit.
- Graphique **Situation des paiements** : camembert plein Payé / Partiel / Impayé (type `pie` du
  template Apollo, `ChartDoc.vue`) + tableau **Statut | Montant | % | Nombre de ventes**. Aucun
  texte n'est superposé au graphique : l'infobulle au survol (statut en titre, puis montant,
  % du montant et nombre de ventes) reste entièrement lisible ; un anneau avec total au centre
  avait été essayé et abandonné pour cette raison (19/09/2026).
- **Période unique** pour toute la Situation (cf. section « Période » plus bas) : toute la période,
  périodes rapides (aujourd'hui, hier, semaine, mois, année, en cours et précédentes) ou
  période personnalisée (Du → Au).

**Hors périmètre (sections à venir)** : commissions, dépenses, pannes/maintenance,
immobilisation, marge/rentabilité, comparaison de périodes.

## Règles métier

### Une vente « vendue » exclut le brouillon et l'annulation

Une `CommandeVente` rattachée au véhicule (`vehicule_id`) n'entre dans la Situation que si son
statut a dépassé `brouillon` et n'est pas `annulee` (`App\Enums\StatutCommandeVente`). Une
commande encore en brouillon n'a rien vendu ; une commande annulée non plus (décision produit du
15/09/2026). `vente_standard` et `distribution_client` (`docs/commissions.md` COMM-001) sont
additionnées : les deux sont commercialement des ventes à part entière du véhicule (COMM-004).

### CA, encaissé, reste à payer

- **CA vendu** = Σ `commandes_ventes.total_commande` des ventes retenues.
- **Encaissé** = Σ `FactureVente::montant_encaisse` ; **Reste à payer** = Σ `FactureVente::montant_restant`,
  sur les factures **non annulées** uniquement (même exclusion que l'écran Ventes,
  `IndexCommandeVenteController`).

### Situation des paiements (Payé / Partiel / Impayé)

Une vente est classée selon le `statut_facture` de sa facture, tel que le calcule
`FactureVente::recalculStatut()` à chaque encaissement :

| Catégorie | `statut_facture` | Définition |
|---|---|---|
| **Payé** | `payee` | encaissé ≥ `montant_net` |
| **Partiel** | `partiel` | 0 < encaissé < `montant_net` |
| **Impayé** | `impayee`, `creee` | aucun encaissement (« créée » = facture pas encore recalculée, même situation financière) |

Une facture n'a qu'**un** statut : les catégories sont **mutuellement exclusives**, sans double
compte. Chaque catégorie est valorisée au **montant facturé** (`montant_net`) de ses ventes ; leur
total redonne donc le facturé (= CA vendu tant que commande et facture sont synchronisées). Les
factures annulées sont exclues.

Conséquence à connaître : la part non encaissée d'une vente **partielle** reste dans « Partiel »
(le tableau l'indique : « dont reste à payer X »). Le **Reste à payer** global = « reste » de la catégorie *Impayé* + « reste »
de la catégorie *Partiel*. « Impayé » seul n'est donc pas égal au KPI Reste à payer dès qu'il existe une vente partielle.

### Pourcentages

**Un seul pourcentage** : la part du **montant** (décision produit du 19/09/2026 — la question
métier est « quelle part du montant des ventes est payée, partielle ou encore due ? ») :

- % = montant de la catégorie ÷ montant total facturé × 100.

Le **nombre de ventes** reste affiché, à titre informatif, **sans pourcentage** : un second
pourcentage (sur le nombre de ventes) avait été affiché puis retiré, car deux pourcentages
différents côte à côte prêtaient à confusion. Le champ n'existe plus dans l'API.

Arrondi à 0,1 (la somme affichée peut différer de 100 de ±0,1 ; la ligne Total affiche 100 %).
Total nul → 0 %, jamais de division par zéro. Calculé côté backend
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

## Onglet actif et filtres dans l'URL (fiche véhicule)

L'onglet actif de `Vehicules/Show.vue` est porté par l'URL : `?tab=informations|equipe|parrain|situation|depenses`
(composable `resources/js/composables/useUrlTab.ts`). Un simple `ref('informations')` était perdu
à tout chargement complet de la page (F5, rechargement forcé par Inertia — changement de version
des assets, session expirée —, lien copié).

- **Au chargement** : `tab` valide → onglet actif ; absent ou invalide → `informations`.
- **Clic sur un onglet** : affichage immédiat + `tab` écrit dans l'URL par une visite Inertia
  côté client (`router.replace`, aucune requête serveur, pas d'entrée d'historique ajoutée) ;
  les autres paramètres (`situation_periode`, `processus`) sont conservés.
- **Filtre de période Situation** : ne modifie que les paramètres de période
  (`situation_periode`, `date_from`, `date_to`), garde `tab=situation` et les autres paramètres.
  Le sélecteur de processus de l'onglet Équipe fait de même (`tab` + période conservés).
- **Redirections serveur** vers l'URL nue de la fiche (enregistrement d'un parrain ou d'une
  équipe, transfert de livreur vers un autre véhicule) : le composant est conservé, l'onglet
  courant est réinscrit dans l'URL — l'onglet ne change **que** sur un clic explicite.

Règle pour tout nouveau `router.get` sur cette page : fusionner l'URL courante
(`queryDe(page.url)`) et y forcer `tab`, sinon les autres paramètres sont perdus.

## Période

Une **seule** période est active pour tout l'onglet : elle est résolue une fois par
`App\Support\Vehicules\SituationPeriode` (depuis la requête) puis transmise à chaque section
(`VehiculeSituationVentesService::pourVehicule($vehicule, $periode)` et les futures). Le
frontend n'applique aucun filtre de dates : il envoie la période et affiche ce que le serveur a
calculé (prop `situation_periode` : `cle`, `date_debut`, `date_fin`, `options`).

| Choix | URL | Bornes (ventes retenues sur leur date de création) |
|---|---|---|
| Toute la période | aucun paramètre | aucune |
| Aujourd'hui / Hier | `situation_periode=aujourd_hui` / `hier` | la journée |
| Cette semaine / Semaine précédente | `cette_semaine` / `semaine_precedente` | lundi → dimanche |
| Ce mois / Mois précédent | `ce_mois` / `mois_precedent` | 1er → dernier jour du mois |
| Cette année / Année précédente | `cette_annee` / `annee_precedente` | 1er janvier → 31 décembre |
| Période personnalisée | `date_from=YYYY-MM-DD&date_to=YYYY-MM-DD` | du début de `date_from` à la fin de `date_to` (bornes incluses) |

- Les périodes rapides sont résolues **côté serveur** (fuseau et locale de l'application) : elles
  restent relatives dans un favori, et « aujourd'hui » ne dépend pas de l'horloge du navigateur.
  `mois_precedent` / `annee_precedente` ne débordent jamais (31 mars → février).
- Période personnalisée : les **deux** dates sont obligatoires et `date_from` ≤ `date_to` ; toute
  valeur invalide (une seule date, ordre inversé, format ou date impossible) retombe sur « Toute
  la période ». Si `date_from`/`date_to` sont présents, ils l'emportent sur `situation_periode`.
- Côté interface : sélecteur de période (PrimeVue `Select`) ; « Période personnalisée » affiche
  `Du [date] → Au [date]` (champs date natifs, comme `DataFilters`), préremplis avec les bornes
  de la période active. La période s'applique dès que les deux dates sont valides ; sinon un
  message précise ce qui manque (date de début / de fin) ou l'inversion des dates.
- Le lien « Voir les ventes » reprend ces mêmes bornes.
- Le paramètre s'appelle `situation_periode` (et non `periode`) : la fiche héberge plusieurs
  sections, chacune pourra avoir son propre filtre sans collision.

## Implémentation

Backend — une section = un service + une prop Inertia :
- `App\Services\Vehicules\VehiculeSituationVentesService::pourVehicule()` → prop
  `situation_ventes` (`kpis`, `produits`, `paiements`), appelée depuis `VehiculeController::show()`
  avec la période commune (`SituationPeriode::depuisRequete()`, exposée en prop `situation_periode`).
- `Vehicule::commandesVentes()` (relation inverse de `CommandeVente::vehicule()`).

Frontend :
- `Vehicules/partials/SituationTab.vue` : coque (titre, filtre de période partagé, sections).
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
clair = emerald-600 / amber-500 / red-600 ; sombre = emerald-600 / amber-600 / red-700 ; au survol,
la tranche passe au cran plus clair (comme les `hoverBackgroundColor` d'Apollo). La couleur
n'est jamais le seul canal : chaque ligne du tableau porte un libellé et une icône (coche /
demi-disque / alerte). Produits : une seule couleur (primaire), une série, pas de légende.
Le vert/ambre pâle ayant un contraste < 3:1 sur fond clair, le tableau des paiements (chiffres
visibles) et le tableau accessible masqué des produits font office de vue tabulaire.

## Points hors périmètre identifiés (non traités)

- `resources/js/components/StatusDot.vue` (`STATUS_COLOR_MAP`) ne couvre pas la valeur de statut
  `facturation` (couleur grise par défaut) — gap préexistant, déjà présent sur `Ventes/Index.vue`.
- L'onglet Situation, comme l'onglet Dépenses, est visible avec `vehicules.read` seul (pas de
  `ventes.read` exigé pour lire les chiffres) ; seul le lien vers Ventes est conditionné.
