# Tableau de bord des dépenses

> **Statut** : en place (24/11/2026) · Menu **Tableau de bord → Stat-Dépenses**
> (`/backoffice/tableau-de-bord/depenses`, route `dashboard.depenses`).

Vue consolidée et **strictement consultative** des dépenses : elle lit la table `depenses` et ne
touche ni au circuit de validation ([depenses-validation.md](depenses-validation.md)) ni à la
comptabilisation des dépenses (`DepenseComptabilisationService`).

| Élément | Fichier |
|---|---|
| Contrôleur | `app/Http/Controllers/Divers/IndexDashboardDepensesController.php` |
| Agrégations | `app/Services/Depenses/TableauDeBordDepensesService.php` |
| Page | `resources/js/pages/DashboardDepenses.vue` |
| Cartes et graphiques | `resources/js/components/dashboard/depenses/` |
| Filtres (partagés avec Stat-Ventes et Stat-Commissions) | `resources/js/components/periodes/SelecteurPeriode.vue`, `resources/js/components/filters/SelecteurAgence.vue`, `app/Support/Periodes/TranchesPeriode.php` |
| Tests | `tests/Feature/DashboardDepensesTest.php`, `tests/Feature/Periodes/RegistreFiltresPeriodeTest.php`, `resources/js/components/dashboard/depenses/__tests__/`, `resources/js/pages/__tests__/DashboardDepenses.spec.ts` |

## Accès et périmètre

- **Droit** : `depenses.read`, module Dépenses actif — les mêmes que la liste des dépenses. Aucune
  permission dédiée.
- **Périmètre** : exactement celui de la liste des dépenses, `DepensePerimetre::appliquer`
  ([depenses-visibilite.md](depenses-visibilite.md)) : toutes les agences pour la vision toutes
  agences (dépenses sans agence comprises) ; sinon ses agences de rattachement, celles où son rôle
  valide, et les dépenses qu'il a lui-même saisies, quelle que soit leur agence.
- **Filtre agence** : les agences du filtre de la liste (`DepensePerimetre::sitesProposes`) ;
  « Toutes les agences » par défaut ; une agence demandée hors de cette liste est refusée avec un
  message. Le sélecteur n'est pas affiché si une seule agence est consultable.
- **Filtre période** : celui des autres tableaux de bord (`Periode::TABLEAU_DE_BORD`, dates libres
  comprises, bornes incluses), « Ce mois » par défaut. Cartes et graphiques suivent les mêmes
  filtres.

## Règles de calcul (décisions du 24/11/2026)

- **Date de référence** : `date_depense`.
- **Statuts lus** : validé, soumis, rejeté. Brouillons, annulées et dépenses supprimées sont exclus
  partout.
- **Payée = validée** : la validation passe aussitôt l'écriture de sortie de trésorerie
  (`DepenseObserver`) ; il n'existe pas de dépense validée non payée. Aucun statut de paiement
  propre au tableau de bord.
- **Avance récupérable** : dépense validée dont le type a une catégorie de
  `DepenseComptabilisationService::CATEGORIES_AVANCE_TIERS` (Véhicule, Propriétaire, Livreur,
  Parrain), comme le rapport par agence (`ChargesRapportService`) et la comptabilité. Toute autre
  dépense validée est une **charge entreprise**, y compris Salarié, Prestataire, Consultant et
  Client (option A : la classification comptable existante n'est pas modifiée pour le tableau de
  bord).
- **`depense_imputations` n'est pas lue** : une ligne par dépense validée hors Interne, du même
  montant, au statut figé `impute` (jamais mis à jour lors de la retenue). La lire n'apporterait
  rien et risquerait un double comptage ; « avoir une imputation » couvre aussi Client, Salarié et
  Prestataire, qui ne sont pas des avances.
- **Rejetée** : statut **actuel** « Rejeté ». Une dépense rejetée puis soumise de nouveau reste une
  seule ligne : elle compte une fois, d'après son statut actuel (en attente, puis payée si elle est
  validée). L'historique des rejets successifs n'est pas lu.

| Carte | Formule | Détail affiché |
|---|---|---|
| Total des dépenses | validées + soumises | évolution |
| Dépenses payées | validées | évolution |
| En attente de validation | soumises | nombre ; ancienneté de la plus ancienne |
| Charges entreprise | validées hors avances (= payées − avances) | évolution |
| Avances récupérables | validées d'une catégorie d'avance | évolution |
| Dépenses rejetées | rejetées | nombre ; taux de rejet |

**Égalités** (vérifiées par les tests) : total = payées + en attente ; payées = charges + avances.
Les rejetées n'entrent dans aucune des deux. Les six cartes sont des indicateurs complémentaires :
elles ne s'additionnent pas.

- **En attente** : une demande, jamais une dette (elle peut encore être rejetée). Filtrée sur la
  période comme les autres cartes. L'ancienneté se compte depuis la **date de la dépense** : la date
  de soumission n'est pas conservée (seulement dans le journal d'audit).
- **Taux de rejet** = rejetées / (validées + rejetées) × 100, au dixième ; « — » sans dépense
  tranchée sur la période.
- **Évolutions** : par rapport à la période précédente de même durée écoulée (`Periode::precedente`,
  comme Stat-Commissions) ; « — » sans période de comparaison (« Toutes les périodes ») ou si la
  période précédente est à zéro. Couleurs d'Apollo, comme Stat-Ventes et Stat-Commissions : hausse
  en vert, baisse en rose, gris sans tendance (décision du 24/11/2026).

## Graphiques

- **Évolution des dépenses payées** : barres empilées charges entreprise + avances récupérables,
  une barre par jour jusqu'à 31 jours, par mois au-delà (`TranchesPeriode`). L'infobulle donne les
  deux montants et les dépenses payées de la tranche.
- **Répartition par type de dépense** : dépenses validées par type, du plus gros au plus petit ;
  au-delà de 7 types, les suivants forment « Autres types ». Pourcentage du total des dépenses
  payées dans la légende, l'infobulle et sur la part (si elle atteint 5 %). La légende est en HTML
  sous le camembert, aux mesures de la légende Chart.js d'Apollo (police par défaut 12 px gras,
  pastille de 17 px, 28 px entre éléments, 40 px entre lignes) : elle passe à la ligne sans être
  coupée sur téléphone.
- **Comparaison des agences** : dépenses validées par agence de saisie (`depenses.site_id`), en
  barres horizontales classées du plus gros au plus petit, montant et pourcentage des dépenses
  payées au bout de chaque barre. Une ancienne dépense sans agence forme « Sans agence ».

Chaque dépense a un seul type et une seule agence : la somme des parts de chaque graphique est égale
aux dépenses payées. Les pourcentages s'affichent avec une décimale (« 56,7 % », « < 0,1 % » pour
une part non nulle plus petite), calculés sur les montants réels : ils peuvent totaliser 99,9 % ou
100,1 %, jamais ajustés (`resources/js/lib/pourcentages.ts`, commun aux tableaux de bord).

## Performances

Trois requêtes d'agrégation sur `depenses`, quel que soit le volume : la période par jour (cartes,
mini-courbes, graphique d'évolution), le total de la période de comparaison, et les dépenses
validées par type × agence (les deux répartitions). Aucune dépense n'est chargée ; les types
d'avance et les libellés viennent de deux petites lectures de `depense_types`, sans jointure ligne
à ligne.

Laissé libre, MySQL choisit l'index `(organization_id, depense_type_id)` et relit toutes les
dépenses de l'organisation : le service impose l'index `(organization_id, statut, date_depense)`
(`FORCE INDEX`, compris par MySQL et MariaDB ; SQLite garde son plan).

Mesures du 24/11/2026, MySQL 8.4 local (pool InnoDB de 128 Mo), base temporaire de 400 000
dépenses dont 200 000 pour l'organisation mesurée (≈ 62 000 par an, 20 agences, 40 types), médiane
de 7 appels du service :

| Scénario | Première version | Sans index couvrant | Avec index couvrant (migration du 25/11/2026) |
|---|---|---|---|
| Admin · Ce mois | 433 ms | 216 ms | 63 ms |
| Admin · Cette année | 11 117 ms | 2 215 ms | 911 ms |
| Admin · Toutes les périodes | 10 408 ms | 4 266 ms | 1 655 ms |
| Admin · Cette année · 1 agence | — | 1 742 ms | 403 ms |
| Manager (2 agences) · Cette année | — | 1 799 ms | 529 ms |

Le temps croît avec le nombre de dépenses de la période. **Index couvrant** : la migration
`2026_11_25_100000_optimiser_index_liste_et_statistiques_depenses` étend l'index imposé à
`(organization_id, statut, date_depense, deleted_at, site_id, user_id, depense_type_id, montant)`,
même nom : les trois lectures se font dans l'index seul (colonne de droite). Elle ajoute l'index de
tri de la liste des dépenses, sans lequel l'index couvrant n'est jamais déployé
([depenses-filtres.md](depenses-filtres.md#performances)). Sous MariaDB 11.8.9 : 46 ms → 30 ms
(Ce mois), 505 ms → 233 ms (Cette année), 2,6 s → 0,54 s (Toutes les périodes).
