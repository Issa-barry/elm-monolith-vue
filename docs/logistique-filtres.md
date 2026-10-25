# Filtres des listes Transferts et Réceptions

Écrans : **Logistique → Transferts** (`/backoffice/logistique/transferts`) et
**Logistique → Réceptions** (`/backoffice/logistique/receptions`). Les deux listes partagent la
même page (`Logistique/Index.vue`) et le même contrôleur
(`TransfertLogistiqueController::buildIndex()`), donc les mêmes filtres.

Barre : **Site de départ → Site d'arrivée → Véhicule → Livreur → bouton Filtres**.
Panneau **Filtres** : Statut, Période.

## LOGFIL-001 — Période

- Bornes `date_debut` / `date_fin` (incluses), sur la **date de création du transfert** — celle
  de sa référence `TRF-JJMMAA-NNN`. Pas sur le départ prévu (facultatif, souvent vide).
- Une date mal formée (autre que `AAAA-MM-JJ`) est ignorée.

## LOGFIL-002 — Véhicule

- Texte libre `vehicule` : contenu dans le **nom** ou l'**immatriculation** du véhicule du
  transfert.

## LOGFIL-003 — Livreur

- Texte libre `livreur` : contenu dans le nom complet, le nom, le prénom ou le téléphone d'un
  livreur.
- Le livreur est cherché dans l'**équipe enregistrée sur le transfert** ; si le transfert n'a pas
  d'équipe, dans l'**équipe actuelle du véhicule**.
- Limite : c'est la composition **actuelle** de l'équipe qui est lue. Un livreur sorti de
  l'équipe depuis ne retrouve plus ses anciens transferts par ce filtre.

## Périmètre

Ces filtres ne font que restreindre le résultat : l'organisation et le périmètre d'agence
(destination verrouillée sur ses agences pour Réceptions, départ pour Transferts, sauf vision
toutes agences — ADR 0025) s'appliquent toujours avant.

Tests : `tests/Feature/TransfertLogistiqueIndexFilterTest.php`.
