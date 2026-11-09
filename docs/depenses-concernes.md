# Dépenses — concernés d'un type de dépense

Le champ **Concerné** d'un type de dépense (Dépenses → Types de dépense) fixe à qui la dépense est
rattachée et sur quoi elle est retenue. Source unique : `App\Enums\CategorieDepense` (libellés,
référentiel du bénéficiaire, imputation, message d'impact) ; colonne `depense_types.categorie`.

| Concerné | Bénéficiaire choisi à la saisie | `depenses.beneficiaire_type` | Retenue | Comptabilité à la validation |
|---|---|---|---|---|
| Véhicule | véhicule de l'organisation | `vehicule` | fiche Propriétaire (mois) du propriétaire du véhicule | avance 467130 |
| Propriétaire | propriétaire | `proprietaire` | fiche Propriétaire (mois) | avance 467130 |
| Livreur | livreur | `livreur` | fiche Livreur (quinzaine) | avance 467140 |
| Salarié | salarié actif | `employe` | paie du mois (module Paie) | circuit Paie |
| Interne | aucun | — | aucune | charge (compte du type ou 628800) |
| Prestataire | tout prestataire (consultant, machiniste, mécanicien…) | `prestataire` | fiche Consultant (mois) | non comptabilisée (cf. ci-dessous) |
| Client | client | `client` | cashback du client | charge |
| **Consultant** | prestataire **qualifié Consultant** uniquement | `prestataire` | fiche Consultant (mois) | comme Prestataire |
| **Parrain** | parrain de l'organisation | `parrain` | fiche Parrain (quinzaine), PARRAIN-026 | avance 467220 |

## Consultant et Parrain (03/11/2026)

- **Consultant** est un **raccourci** vers Prestataire : il ne propose que les prestataires qualifiés
  Consultant (critère unique `Prestataire::qualifieConsultant()`, lot 6b du référentiel Contacts —
  [contacts.md](contacts.md), CONTACT-039) et la dépense est enregistrée comme
  une dépense prestataire (`CategorieDepense::beneficiaireType()`). Elle est donc déduite de la même
  fiche Consultant et visible sur Commission consultant, sans second chemin. Le concerné Prestataire
  reste disponible pour tous les prestataires, consultants compris. Un prestataire non qualifié
  Consultant choisi avec le concerné « Consultant » est refusé par le serveur.
- **Parrain** : référentiel `parrains` de l'organisation (un parrain d'une autre organisation est
  refusé). Imputation `commission_parrain` à la quinzaine, retenue sur la fiche Parrain, avance
  467220 soldée à la validation de la fiche — cf. [parrainage-vehicule.md](parrainage-vehicule.md),
  PARRAIN-026.
- Les dépenses existantes et les anciens types ne changent pas : aucune migration de données, seul
  le mapping comptable `depense_avance_tiers_validee` / `avance_tiers_parrain` est ajouté
  (`2026_11_03_100000_bootstrap_mapping_depense_parrain`, idempotente).

## Saisie, liste et exports

- Saisie (Dépenses → Nouvelle dépense, Modifier) : Consultant ouvre la recherche des consultants
  (nom/entreprise, téléphone), Parrain celle des parrains (nom, téléphone, véhicule parrainé,
  immatriculation).
- Liste : filtre Concerné (catégorie du type), recherche et filtres « Concerné » / « Téléphone du
  concerné » trouvent aussi les parrains ; l'export Excel reprend le nom et le téléphone du parrain.
- Import de types de dépense : la colonne `concerne` accepte « Consultant » et « Parrain ».

## Visibilité dans l'espace client (05/11/2026)

Une dépense validée qui diminue le reste à payer d'un bénéficiaire est consultable par lui dans
l'espace client (page Dépenses, carte Dépenses de l'accueil, `GET /v1/mobile/depenses/mine`) :
Véhicule et Propriétaire pour le propriétaire, Livreur, Parrain, Consultant/Prestataire pour le
consultant rattaché. Salarié et Client n'ont pas de liste de dépenses dans l'espace client. Cf.
[api-espace-client-contract.md](api-espace-client-contract.md).

## Dépense validée après la validation de la période

Une dépense validée après la validation d'une période de paiement rouvre cette période si son
bénéficiaire y a déjà une fiche (ADR 0008, révision du 03/11/2026) — cf.
[commissions.md](commissions.md).

## Limite connue

Une dépense Prestataire ou Consultant validée n'est pas comptabilisée en avance (pas de débit
467200), alors que la fiche Consultant validée crédite 467200 du montant retenu : comportement
antérieur à ce chantier, inchangé ici.

Tests : `tests/Feature/DepenseConcerneConsultantParrainTest.php`,
`tests/Feature/Comptabilite/ParrainFichePaiementTest.php`,
`tests/Feature/Comptabilite/CommissionConsultantTest.php`.
