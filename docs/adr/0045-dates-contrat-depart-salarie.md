# ADR 0045 — Date d'embauche reprise du contrat, départ par une action dédiée

- **Date** : 2026-10-31
- **Statut** : accepté (choix de l'utilisateur du 31/10/2026, options recommandées)
- **Périmètre** : RH → Employés, RH → Contrats, paie — cf. [rh-depart-salarie.md](../rh-depart-salarie.md),
  [rh-employes-identite.md](../rh-employes-identite.md)

## Contexte

Le formulaire de création d'un employé demandait la date d'embauche, la date de sortie et le motif
de sortie, alors que les dates de contrat vivent sur le contrat et qu'un nouvel employé n'est jamais
sorti. Ces dates du dossier bornent pourtant la période d'emploi lue par la paie, et en production
39 salariés ont une date d'embauche (souvent importée) sans aucun contrat saisi.

## Décision

1. **La date d'embauche reste au dossier**, mais n'est plus saisie à la création : le premier
   contrat créé la renseigne quand elle est vide ; une date déjà connue n'est jamais écrasée ; elle
   se corrige dans la fiche. Rejeté : la calculer depuis les contrats (perte de l'ancienneté des
   salariés sans contrat, paie et import à revoir).
2. **Le départ passe par une action dédiée** « Enregistrer le départ » (date effective + motif) :
   salarié « Sorti », contrat actif terminé à cette date, aucun mois de paie figé touché, date non
   future. « Annuler le départ » rétablit le salarié et le contrat tant que la paie du mois n'est pas
   figée. Le statut « Sorti » ne se choisit plus dans le formulaire.
3. Pour rétablir un contrat écourté, sa date de fin d'origine est conservée sur le contrat
   (`date_fin_avant_depart`, `termine_par_depart`) — seule migration du chantier.
4. **Contrats successifs** : déjà possibles (un seul actif) ; la saisie refuse désormais deux
   contrats couvrant les mêmes jours, ce que la paie bloquait déjà.

## Conséquences

- Paie inchangée : elle lit toujours la date d'embauche et la date de sortie du dossier, et les
  contrats couvrant le mois.
- Période d'essai et motif du CDD restent saisis à la génération du contrat (ADR 0044).
- Hors périmètre : réembauche d'un salarié sorti (nouveau contrat après départ), fin automatique
  des primes au départ, numéro CNSS employeur de l'organisation.
