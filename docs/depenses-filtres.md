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
