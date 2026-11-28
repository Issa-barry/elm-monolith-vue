# Tableau de bord des commissions

> **Statut** : en place (22/11/2026) · Menu **Tableau de bord → Stat-Commissions**
> (`/backoffice/tableau-de-bord/commissions`, route `dashboard.commissions`).

Vue consolidée et **strictement consultative** des commissions : elle lit les parts déjà générées
(`commission_enveloppe_parts`) et ne déclenche jamais le moteur de commissions (aucune génération,
aucun recalcul d'enveloppe ou de répartition).

| Élément | Fichier |
|---|---|
| Contrôleur | `app/Http/Controllers/Divers/IndexDashboardCommissionsController.php` |
| Agrégations | `app/Services/Commission/TableauDeBordCommissionsService.php` |
| Page | `resources/js/pages/DashboardCommissions.vue` |
| Cartes, graphique, répartition | `resources/js/components/dashboard/commissions/` |
| Filtre de période (partagé avec Stat-Ventes) | `resources/js/components/periodes/SelecteurPeriode.vue`, `app/Support/Periodes/TranchesPeriode.php` |
| Filtre agence | `resources/js/components/filters/SelecteurAgence.vue`, `resources/js/lib/periodes.ts` |
| Tests | `tests/Feature/DashboardCommissionsTest.php`, `tests/Unit/Periodes/PeriodeTest.php`, `tests/Unit/Periodes/TranchesPeriodeTest.php`, `tests/Feature/Periodes/RegistreFiltresPeriodeTest.php`, `resources/js/components/dashboard/commissions/__tests__/`, `resources/js/components/periodes/__tests__/` |

## Accès et périmètre

- **Activation** : le tableau doit être activé pour l'organisation (Paramètres → Tableaux de
  bord, désactivé par défaut), sinon il quitte le menu et sa route est refusée, avant tout calcul
  ([tableaux-de-bord-activation.md](tableaux-de-bord-activation.md), ADR 0071).
- **Droit** : `commissions.read` ou `comptabilite.read` (`User::canReadCommissions()`), module
  Comptabilité actif — les mêmes que les écrans Comptabilité → Commissions.
- **Organisation** : seules les commissions de l'organisation de l'utilisateur (`commission_enveloppes.organization_id`).
- **Agences** (ADR 0061) : un utilisateur qui voit toutes les agences (administrateur, superviseur,
  vision 360°) voit tout. Sinon, par type de bénéficiaire, le même périmètre que l'écran de ce type
  (`AgencePayeuseCommissionService::perimetresLecture()`) :

| Bénéficiaire | Agence qui ouvre la lecture |
|---|---|
| Livreur, propriétaire, parrain, consultant | agence de l'opération : site actuel du véhicule, sinon site de la vente / agence de départ du transfert (`LecturePartsCommission::joindreAgence()`, même règle que `CommissionSiteResponsableFilter`) |
| Site | le site bénéficiaire lui-même |
| Tout type | aucune restriction pour l'utilisateur rattaché à l'agence qui paie **toutes** les fiches de ce type |

  Un utilisateur sans agence ne voit rien. Les types sans écran (employé) ne sont visibles que d'un
  utilisateur qui voit toutes les agences.

### Filtre agence (23/11/2026)

- Sélecteur en tête de page, à gauche du filtre de période (`components/filters/SelecteurAgence.vue`,
  même style) : **Toutes les agences** (par défaut) ou une agence. Paramètre `site_id`.
- **Toutes les agences** = les agences du périmètre de l'utilisateur, pas forcément toute
  l'organisation : un administrateur voit toute l'organisation, un responsable de Matoto et Coyah
  voit Matoto et Coyah (tableau ci-dessus).
- Agences proposées (`TableauDeBordCommissionsService::agencesProposees()`) : toutes celles de
  l'organisation pour un utilisateur qui voit toutes les agences, sinon les siennes. Le sélecteur
  n'apparaît qu'à partir de deux agences.
- **Contrôle serveur à chaque requête** : un `site_id` hors de cette liste (autre agence, autre
  organisation, identifiant inconnu) est refusé avec le message « Agence inconnue ou hors de votre
  périmètre. », jamais ignoré ni cru sur parole.
- Une agence choisie restreint chaque type de bénéficiaire à l'intersection de son périmètre et de
  cette agence, avec la même règle d'agence que le tableau ci-dessus (agence de l'opération, ou site
  bénéficiaire). Les types sans agence (employé) n'y figurent pas. Le filtre s'applique dans les
  deux requêtes SQL, sans requête supplémentaire.
- Il pilote les quatre cartes, les mini-courbes, les deux graphiques et la **période de
  comparaison** (même agence pour les deux périodes). Il se combine avec la période : chacun des deux
  sélecteurs conserve l'autre en rechargeant la page.
- La tête de page indique le périmètre affiché : « Agence : Matoto », « Toutes vos agences : … » ou
  « Toutes les agences ».

## Période et comparaison

- **Un seul filtre pilote toute la page** (23/11/2026) : le sélecteur en tête de page,
  `SelecteurPeriode.vue`, le même que sur Stat-Ventes. Les quatre cartes, les mini-courbes, le
  graphique d'évolution et la répartition portent toujours sur la même période ; aucun graphique n'a
  de période propre. Le sélecteur d'Apollo dans l'en-tête du graphique est retiré, sa hauteur
  (33 px rendus) est conservée.
- Moteur commun (ADR 0057, [periodes.md](periodes.md)) : `Periode::TABLEAU_DE_BORD` — périodes
  rapides, trimestres, semestres, « Toutes les périodes » et **Période personnalisée** (du … au …,
  bornes incluses) ; défaut **Ce mois**.
- **Période personnalisée** (23/11/2026) : la ligne de filtres ne contient que les deux listes
  (agence, période). Choisir « Période personnalisée » ouvre une fenêtre contextuelle (Popover
  PrimeVue) sous la liste : date de début, date de fin (côte à côte sur bureau, l'une sous l'autre sur
  mobile) et « Appliquer ». Les cartes et graphiques ne bougent pas. Les deux dates sont obligatoires
  et la fin doit suivre le début (« Appliquer » bloqué sinon) ; une période refusée par le serveur
  affiche son message dans la fenêtre, qui reste ouverte avec la saisie. Fermée sans appliquer, la
  fenêtre ne change rien. Une fois appliquée, la liste affiche « 01/11/2026 – 30/11/2026 » ;
  rechoisir « Période personnalisée » rouvre la fenêtre pour modifier les dates.
- Le libellé de la période appliquée s'affiche en tête de page (« Ce mois (du 01/11/2026 au
  30/11/2026) », « Du 01/10/2026 au 23/11/2026 »).
- Une part appartient à la période de sa commission : `commission_enveloppes.earned_at`, comme dans
  les listes de commissions. Les quatre cartes utilisent cette même date (décision du 23/11/2026) :
  leur état (en attente, à payer, payé) est l'état actuel des commissions générées sur la période,
  ce qui garde la partition exacte et le taux de paiement juste. Les versements faits pendant une
  période, quelle que soit la date des commissions, relèvent de la Trésorerie.
- **Évolution en %** : comparée à `Periode::precedente()` — la période précédente de même durée,
  ramenée à la même durée écoulée si la période est en cours (« Ce mois » au 22 → du 1er au 22 du
  mois précédent). Dates libres : les jours qui précèdent, autant que la période choisie (du 02/11
  au 05/11 → du 29/10 au 01/11). La période affichée est en tête de page ; la période de comparaison est dans la bulle d'information (icône « i ») à côté.
- **Aucune tendance inventée** : sans période de comparaison (« Toutes les périodes ») ou si la
  période de comparaison vaut 0, la carte affiche « — » en gris, sans flèche.

## Les quatre indicateurs

Partition exacte du total, la même que les écrans Comptabilité → Commissions
(`CommissionKpiBuckets`, décision du 20/08/2026 « visible ne veut pas dire payable ») :
**générées = en attente de validation + à payer + payées**. Montant dû d'une part :
`COALESCE(montant_actuel, montant_net)` (montant ajusté par un responsable, sinon net).

| Carte | Définition | Formule | Statuts inclus | Statuts exclus |
|---|---|---|---|---|
| Commissions générées | Toutes les commissions de la période | Σ montant dû | créée, impayée, partiellement payée, payée | annulée |
| En attente de validation | Commissions dont la période de paiement n'est pas validée : visibles, pas encore payables | Σ montant dû | créée | les autres |
| À payer | Reste à payer des commissions validées | Σ max(0, montant dû − versé) | impayée, partiellement payée | créée, payée, annulée |
| Payées | Déjà versé sur les commissions de la période | Σ montant versé | toutes sauf annulée | annulée |

- Période : `earned_at` de la commission dans la période choisie ; périmètre : § Accès.
- « Payées » compte ce qui a été versé **sur les commissions de la période**, quelle que soit la date
  du versement (pas les décaissements faits pendant la période : ceux-ci sont en Trésorerie).
- Le cercle de la carte « Payées » (Knob d'Apollo) donne le **taux de paiement** : payées ÷ générées,
  au dixième (« 77,3 % ») ; « — » sans commission.
- Montants affichés en abrégé (53,4 M, 1,2 Md) suivis de « GNF » ; le montant exact apparaît au
  survol. Les mini-courbes tracent le montant de l'indicateur à chaque jour (ou mois) de la période.

## Graphique « Évolution des commissions »

Barres **Générées** et **Payées** par tranche : un jour par barre jusqu'à 31 jours, un mois par barre
au-delà ; jusqu'à aujourd'hui pour une période en cours ; du premier au dernier mois qui a des
commissions pour « Toutes les périodes ». Les tranches sont calculées par le serveur
(`TranchesPeriode`, partagé avec Stat-Ventes).

## Graphique « Répartition des commissions »

Total généré de la période **par type de bénéficiaire** (Livreurs, Propriétaires, Sites, Consultants,
Parrains — libellés d'`AgencePayeuseCommissionService::LIBELLES`), du plus gros au plus petit. Choix
de la dimension : c'est la seule portée par chaque part (`beneficiaire_type`) sans jointure
supplémentaire, et elle répond à « qui touche les commissions ». La répartition par processus existe
déjà côté ventes (« Ventes par processus » du tableau de bord des ventes) et reste disponible dans les
listes de commissions (filtre Processus).

Chaque type affiche sa **part du total de la période**, avec une décimale (« < 0,1 % » pour une
part non nulle plus petite), dans la légende (« Livreurs (45,3 %) »), dans l'infobulle (montant puis
pourcentage) et au centre de la part elle-même lorsqu'elle atteint 5 % du total (en dessous, la part
est trop étroite : le pourcentage reste dans la légende et l'infobulle). Calcul fait dans le
navigateur à partir des montants renvoyés par le serveur ; les arrondis peuvent ne pas totaliser
exactement 100,0 %. Cet ajout aux pourcentages est le seul écart avec le camembert d'Apollo.

## Reprise du template Apollo

Référence : `_template/apollo-vue-6.2.0`, tableau de bord **Ecommerce** (`views/dashboards/Ecommerce.vue`)
— c'est lui qui porte les quatre cartes à flèches et pourcentages, « Revenue Overview » et « Sales by
Category ». Le tableau de bord Banking n'a ni ces cartes ni ces graphiques.

| Apollo | ELM | Adaptation |
|---|---|---|
| `StatsEcommerceWidget` (Sales, Revenue, Visitors, Stock) | `CommissionsStatsWidget` | libellés, montants GNF, évolutions réelles, mini-courbes tracées avec les montants (mêmes viewBox 258×96 et 115×41, traits 2 px et 1 px), Knob = taux de paiement |
| `RevenueOverviewWidget` | `CommissionsEvolutionWidget` | titre, séries Générées/Payées ; sélecteur retiré (le filtre de la page est la seule période), hauteur de l'en-tête conservée |
| `SalesByCategoryWidget` | `CommissionsRepartitionWidget` | titre, types de bénéficiaires, état vide |
| `RecentSalesWidget`, `TopProductsWidget` | — | non repris (demande du 22/11/2026) |

- Grille d'Apollo : 12 colonnes, cartes `col-span-12 md:col-span-6 xl:col-span-3`, graphique
  `xl:col-span-9`, répartition `xl:col-span-3`.
- Apollo fixe `html { font-size: 14px }`, ELM garde 16 px : chaque taille Apollo en rem est reprise en
  px (rem × 14), comme `KpiCard.vue` — carte : padding 28 px, rayon 12 px, ombre
  `0 4px 30px rgba(221,224,255,.54)` (aucune en sombre) ; titre de carte 15,75 px / 600 ; chiffre
  31,5 px / 700 ; titre de graphique 17,5 px / 600 ; gouttière 28 px. Constantes
  dans `components/dashboard/commissions/apollo.ts`.
- Police Poppins (classe `font-apollo`, graisses 400 à 700), couleurs des tokens PrimeVue (primaire,
  surfaces, `--p-green-500` en hausse, `--p-pink-500` en baisse) : le rendu suit le thème et le mode
  sombre de l'organisation, comme Apollo.
- Sur mobile, les cartes et graphiques s'empilent (grille d'Apollo), sans défilement horizontal.

## Performance

L'écran fait **deux requêtes d'agrégation** quel que soit le volume (aucune lecture part par part,
aucun N+1) : la période affichée regroupée par jour de commission et par type de bénéficiaire (les
mois sont formés en PHP), puis le total de la période de comparaison. Un utilisateur limité à ses
agences ajoute la lecture de ses agences et de la politique des agences payeuses.

- **Index** `comm_enveloppes_org_earned_at_idx` (`commission_enveloppes (organization_id, earned_at)`,
  migration du 22/11/2026) : sans lui, MySQL lisait toutes les commissions de l'organisation par
  l'index (organization_id, statut). Sur une période bornée, la requête l'impose par une indication
  d'optimiseur (`/*+ INDEX(e …) */`, commentaire ignoré par SQLite et MariaDB), car MySQL ne le
  choisit pas toujours seul.
- **Périmètre en jointures** (vente/transfert → véhicule) plutôt qu'en sous-requêtes corrélées par
  part.
- **Pas de cache** : les montants changent à chaque génération, validation, paiement, ajustement,
  annulation ou régularisation, souvent par des écritures silencieuses (`saveQuietly`, mises à jour
  groupées) qu'aucun événement ne signale ; un cache afficherait des chiffres périmés sans règle
  d'invalidation fiable.

### Mesures du 22/11/2026

Base MySQL 8.4 dédiée (`elm_perf_dashboard_commissions`, poste de développement Windows, mémoire
tampon InnoDB 128 Mo), deux organisations générées sur 15 mois : **191 394 parts** (environ 6 fois le
volume actuel, test de charge) et **42 520 parts** (ordre de grandeur de la production). Médiane de
5 appels HTTP complets. « Avant » = l'écran « en préparation ». Le rendu d'une page complète coûte
environ 2 s sur ce poste, avant comme après (PHP sous Windows) : le temps SQL est le chiffre utile.

| Scénario | Volume production : SQL | Volume production : requêtes | Test de charge : SQL |
|---|---|---|---|
| Avant (écran vide) | 23 ms | 15 (0 sur les commissions) | 33 ms |
| Ce mois, administrateur | 229 ms | 17 (2 sur les commissions) | 965 ms |
| Mois dernier, administrateur | 330 ms | 17 (2) | 1 193 ms |
| Cette année, administrateur | 1 234 ms | 17 (2) | 4 034 ms |
| Toutes les périodes, administrateur | 1 073 ms | 16 (1) | 4 352 ms |
| Ce mois, responsable d'agence | 352 ms | 20 (2) | 1 466 ms |
| Cette année, responsable d'agence | 1 827 ms | 20 (2) | 5 816 ms |

- Nombre de requêtes constant quel que soit le volume (test `test_le_nombre_de_requetes_ne_depend_pas_du_volume_de_commissions`), aucun N+1.
- Données renvoyées : 13 à 16 Ko au changement de période, 31 Ko au premier chargement (page complète).
- Requêtes répétées : 2 viennent du gabarit commun (déjà présentes avant) ; pour un responsable
  d'agence, l'agence centrale est relue une fois par type de bénéficiaire
  (`AgencePayeuseCommissionService::siteImpose()`, quelques millisecondes), volontairement non mise
  en mémoire : ce service sert aussi au paiement des fiches et l'agence centrale peut être redésignée
  pendant la requête.
- Avant optimisation (première version : une requête avec `OR` des deux périodes, périmètre en
  sous-requêtes corrélées, sans index), test de charge : 146 394 commissions lues pour 26 329 utiles
  (plan MySQL) et 2,2 s (Ce mois, administrateur) à 10,7 s (Cette année, responsable) — mesures
  prises pendant une autre suite de tests, donc un peu majorées.

**Limite connue** : le coût est proportionnel au nombre de parts de la période (jointure part →
commission sur des identifiants ULID `char(26)` en utf8mb4). Les vues courtes restent sous 500 ms au
volume actuel ; « Cette année » et « Toutes les périodes » dépassent la seconde sur ce poste. Les
pistes au-delà demandent une décision : agrégats quotidiens tenus par le moteur de commissions
(modifie le moteur), cache des vues longues avec une règle d'ancienneté affichée, ou mémoire tampon
MySQL plus grande sur le serveur.
