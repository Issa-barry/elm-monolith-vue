# ADR 0039 — Affectations datées des primes et avantages aux salariés

- **Date** : 2026-10-29
- **Statut** : accepté — lot 3 livré le 2026-10-29 (non commité)
- **Périmètre** : RH, attribution des types du référentiel (ADR 0038) aux salariés — cf.
  [primes-avantages.md](../primes-avantages.md)

## Contexte

Le référentiel des types existe depuis le lot 2. Il faut attribuer un type à un salarié pour une
période, avec un montant ou un taux, sans jamais réécrire le passé, pour que la transmission
mensuelle (lot 4, ADR 0040) soit explicable. La conception validée le 26/10/2026 réserve la
validation au super administrateur par une permission délégable, et prévoit deux statuts de fin
pour un type : fermé (bénéficiaires maintenus) et inactif (fin de tout à une date).

## Décision

1. Table `affectations_remuneration` (salarié, type, début, fin, montant **ou** taux, statut
   `a_valider` / `validee` / `annulee`, demande de fin, auteurs) et table
   `affectation_remuneration_saisies` (montant du mois des éléments « saisis chaque mois », unique
   par affectation et par mois). L'état « à venir / en cours / terminée » se déduit des dates.
2. **L'historique n'est jamais réécrit** : une affectation validée ne se modifie plus. Un
   changement de montant crée une nouvelle affectation (`remplace_affectation_id`) qui succède à
   la précédente à sa date d'effet ; la précédente s'arrête la veille à la validation. Une fin
   d'effet se demande (date, motif) puis se valide. Plutôt qu'une table de « valeurs datées » par
   affectation : une seule notion, un seul contrôle de chevauchement, une ligne de transmission
   rattachée à une affectation au montant unique.
3. Pas de chevauchement du même type pour un salarié, sauf cumul autorisé par le type ; contrôle
   sous verrou du salarié avec une **lecture verrouillante** (MySQL REPEATABLE READ : une lecture
   ordinaire peut ignorer une affectation enregistrée entre-temps).
4. **Mois figés** (`CalendrierPaieService`) : une modification ne touche jamais un mois dont une
   version de transmission est validée, ou dont la Comptabilité a validé la paie.
5. Permissions : ressource `rh-affectations-remuneration` (admin_entreprise) et STANDALONE
   `rh-affectations-remuneration.valider` (super_admin seul, délégable), contrôlées dans
   `AffectationRemunerationService`. Écriture limitée aux agences de rattachement, lecture aux
   agences consultables.
6. **Désactivation datée d'un type** (adaptation du lot 2) : `types_remuneration.desactive_au` ;
   date obligatoire dès qu'une affectation validée est en cours ou à venir ; les affectations
   ouvertes s'arrêtent la veille, une à une, avec un motif ; celles qui n'auraient commencé
   qu'après et les propositions sont annulées. La transmission ignore de toute façon un type
   désactivé après cette date. `TypeRemuneration::estUtilise()` devient réel (toute affectation).
7. Décision du 29/10/2026 : **le salaire de base reste porté par le contrat**. Le type système
   `SALAIRE_BASE` et la reprise des salaires en affectations prévus par la conception sont
   abandonnés ; la gestion des contrats ne change pas.

## Choix faits sans décision explicite (à confirmer)

- Un élément « saisi chaque mois » sans montant pour un mois n'est pas transmis (avertissement,
  pas de blocage) ; un montant 0 est accepté.
- Annuler un changement de montant validé rétablit la fin d'origine de l'affectation précédente.
- La date d'effet d'une fin peut être le dernier jour du dernier mois figé (elle n'arrête alors
  que des versements futurs).

## Conséquences

- Deux tables et une colonne, aucune donnée existante modifiée.
- 235 → 244 permissions au catalogue avec le lot 4 (5 pour ce lot, 4 pour la transmission).
- Non développés : réattribution en masse après réactivation d'un type, attribution groupée,
  import Excel.
