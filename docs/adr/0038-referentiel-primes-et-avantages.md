# ADR 0038 — Référentiel des primes et avantages

- **Date** : 2026-10-28
- **Statut** : accepté — lot 2 livré le 2026-10-28 (commit e0e1d281) ; complété par l'ADR 0039
  (désactivation datée, type « Salaire de base » abandonné)
- **Périmètre** : RH, types de primes et d'avantages — cf. [primes-avantages.md](../primes-avantages.md)

## Contexte

Les primes n'avaient pas de référentiel : une liste figée dans le code (`TypeVariablePaie`) ou un
libellé libre saisi chaque mois dans la paie. Depuis l'ADR 0037 (amendement du 26/10/2026), une
dépense ne peut plus créer de prime : les gains doivent venir d'un référentiel RH. La conception
validée le 26/10/2026 réserve la validation des primes au super administrateur, par une permission
délégable.

## Décision

1. Table `types_remuneration`, par organisation : code et libellé uniques, catégorie, mode de
   calcul, périodicité, nature du montant, valeur par défaut, période d'application, auteurs.
2. Cycle `a_valider` → `actif` → `ferme` / `inactif` → réactivation. Seul un type actif, dans sa
   période d'application, est attribuable.
3. Permission STANDALONE `rh-types-remuneration.valider`, accordée par défaut au seul
   super_admin. La ressource `rh-types-remuneration` (créer, lire, modifier, supprimer) est donnée
   à admin_entreprise. Contrôle dans `TypeRemunerationService`, en plus des contrôleurs.
4. Décisions de l'utilisateur du 28/10/2026 :
   - un type validé ne se modifie qu'avec le droit de validation (il ne repasse jamais « à
     valider ») ;
   - un type se supprime tant qu'il n'a jamais été attribué, sinon il se désactive.
5. Un avantage en nature est toujours valorisé (jamais versé) : le versement se déduit de la
   catégorie, il n'est pas saisi.

## Écarts avec la conception du 26/10/2026

- Le type système `SALAIRE_BASE` et la section « Traitement » (social, fiscal, compte) sont
  reportés : le premier au lot 3, avec la reprise du salaire des contrats ; la seconde au lot où la
  Comptabilité les renseigne. Sans attribution ni paie branchée, ils n'auraient aucun effet.
- La désactivation n'est pas encore datée et ne met fin à aucune attribution : il n'en existe pas
  avant le lot 3. **Mise à jour du 29/10/2026 (ADR 0039)** : désactivation datée livrée au lot 3 ;
  le type système `SALAIRE_BASE` est abandonné, le salaire de base restant porté par le contrat.

## Conséquences

- Nouvelle table, deux migrations (création, rattrapage des permissions), aucune donnée existante
  modifiée.
- 235 permissions au catalogue (230 + 5).
- Le lot 3 complète `TypeRemuneration::estUtilise()` (attributions) et s'appuie sur
  `scopeAttribuables()`.
