# Filtres de la liste des dépenses

Écran : **Finance → Dépenses → Filtres** (`/backoffice/depenses`). Les mêmes filtres s'appliquent
à l'export Excel et à l'impression (`DepenseListingService::query()` est partagé par les trois).

## DEPFIL-001 — Propriétaire du véhicule → Véhicules

- Le filtre **Propriétaire du véhicule** liste tous les propriétaires de l'organisation qui
  possèdent au moins un véhicule. Recherche par nom, prénom ou téléphone (avec ou sans espaces).
- Il est **indépendant de la personne concernée** par la dépense : un propriétaire est proposé
  même s'il n'est le concerné d'aucune dépense, et une dépense dont il est le concerné (ex. une
  avance) ne remonte pas par ce filtre.
- Une fois le propriétaire choisi, le filtre **Véhicule** ne propose que **tous les véhicules
  rattachés à ce propriétaire** (relation `vehicules.proprietaire_id`, propriétaire actuel), y
  compris les véhicules inactifs et ceux sans dépense. Sans propriétaire, tous les véhicules de
  l'organisation sont proposés.
- Le filtre **Véhicule** accepte un ou plusieurs véhicules (`vehicule_ids[]`).
- Résultat : uniquement les dépenses imputées à un véhicule (`beneficiaire_type = vehicule`) :
  - propriétaire seul → dépenses de n'importe lequel de ses véhicules ;
  - véhicules cochés → dépenses de ces véhicules ;
  - les deux → dépenses des véhicules cochés appartenant à ce propriétaire.
- Un véhicule coché qui n'appartient pas au propriétaire choisi est retiré de la sélection avant
  l'application du filtre.
- Isolation : propriétaires et véhicules sont toujours restreints à l'organisation de
  l'utilisateur ; un identifiant d'une autre organisation ne renvoie aucune dépense.

Paramètres : `proprietaire_id`, `vehicule_ids[]`. L'ancien paramètre texte `vehicule` (nom ou
immatriculation) reste accepté par le backend pour les liens existants, mais n'est plus proposé
à l'écran.

Tests : `tests/Feature/DepenseTest.php` (section « Filtre Propriétaire → Véhicules »),
`resources/js/components/filters/__tests__/DataFilters.spec.ts` (« options dépendantes »).

## DEPFIL-002 — Tri et pagination

- Ordre de la liste, de l'export et de l'impression : date de la dépense décroissante, puis date de
  saisie décroissante, puis identifiant décroissant. L'identifiant (ULID, croissant avec la saisie)
  départage les dépenses de même date et de même seconde de saisie : leur ordre ne dépend plus du
  plan d'exécution, et une dépense à la frontière de deux pages n'apparaît jamais deux fois ni ne
  disparaît (24/11/2026).
- Pagination de 30 lignes. Le total de la pagination est celui des statistiques affichées au-dessus
  du tableau (même requête, mêmes filtres) : aucun second `COUNT`.

## Performances

Mesures du 24/11/2026 sur une base de test de 400 000 dépenses (200 000 pour l'organisation
mesurée), MySQL 8.4 et MariaDB 11.8.9 (moteur de production), médianes de 5 mesures, temps SQL
d'un affichage de la liste.

- **Total repris des statistiques** (DEPFIL-002) : une lecture complète de la période en moins à
  chaque affichage.
- **Index imposé avec un filtre de statut** (`DepenseListingService::imposeIndexStatut`) : avec un
  statut et sans filtre d'agence, de type, de catégorie ni de personne concernée, la liste impose
  l'index `(organization_id, statut, date_depense)` (`FORCE INDEX`, MySQL et MariaDB seulement).
  Laissé libre, MySQL choisissait un index qui ne filtre que l'organisation et relisait chaque
  dépense : « Validées · Cette année » passait de 8,6 s à 1,1 s, « Responsable · validées » de
  7,3 s à 1,0 s. MariaDB choisissait déjà ce plan : l'indication y est sans effet sur le plan. Les
  filtres d'agence, de type et de personne concernée ont leur propre index, que le moteur choisit
  mieux seul : l'index n'y est jamais imposé.
- Lignes, montants, compteurs et pagination identiques avant et après, sur 14 scénarios et les
  deux moteurs (`tests/Feature/Depenses/DepenseListePerformanceTest.php` pour les tests).
