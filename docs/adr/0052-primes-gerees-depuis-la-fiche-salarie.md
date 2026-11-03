# ADR 0052 — Primes gérées depuis la fiche salarié, indépendantes de la durée du contrat

- **Date** : 2026-11-03
- **Statut** : accepté
- **Périmètre** : RH, gestion individuelle des primes et avantages — cf.
  [primes-avantages.md](../primes-avantages.md), [rh-contrat-pdf.md](../rh-contrat-pdf.md).
  Modifie l'écran de l'ADR 0039 et une règle ajoutée le 01/11/2026 à l'ADR 0048.

## Contexte

Objectif : une application très simple, pour des utilisateurs peu habitués aux outils
informatiques. Les primes d'un salarié se géraient sur une page à part, *RH → Primes des salariés*,
alors que sa fiche affiche déjà ses contrats et son salaire de base. La prime était déjà rattachée
au salarié (`employe_id`, pas de `contrat_id`), et la paie partait déjà des contrats et des primes
séparément.

Une règle du 01/11/2026 faisait pourtant dépendre la prime du contrat : une prime mensuelle ajoutée
en créant un CDD s'arrêtait à la fin de ce CDD. En passant en CDI, il fallait la réattribuer.

## Décision

1. **La fiche salarié est le seul écran de gestion individuelle** : ajouter une prime, modifier une
   proposition, changer un montant, mettre fin, saisir les montants mensuels, valider, annuler,
   consulter l'historique. Les fenêtres existantes y ont été déplacées, sans nouveau formulaire.
2. **La page « Primes des salariés » est supprimée** : menu, route `GET`, écran, filtres. Les routes
   d'action, le service, les règles, les permissions, le journal, la paie et les données ne changent
   pas : ils servent à la fiche.
3. **Une prime survit à la fin d'un CDD.** Elle appartient au salarié : seuls la date de fin
   saisie, la fin d'application de son type, une fin d'effet validée ou le départ du salarié
   l'arrêtent. Une ponctuelle reste limitée à son mois.
4. **La fin d'un contrat n'est pas un départ.** Si un CDD se termine sans contrat suivant ni départ
   enregistré, la paie du mois suivant reste bloquée (`contrat_absent`, comportement existant) : les
   RH créent un contrat ou enregistrent le départ. Aucune prime n'est versée sans contrat.
5. **Retrouver les primes à valider** : la suppression de la page retirait la seule liste de tout
   ce qui attend une validation, alors qu'une proposition bloque la transmission du mois. *RH →
   Employés* porte désormais un bandeau (« N primes attendent une validation ») et un filtre
   *Primes : À valider* ; les anomalies de la transmission mènent à la fiche du salarié.
6. **Annulées et terminées restent consultables** sur la fiche, repliées sous « Voir les anciennes
   primes » : c'est désormais le seul écran qui les montre (AFF-012).

## Options écartées

- **Gérer les primes dans la page du contrat** : un salarié peut avoir plusieurs contrats, les
  anciens auraient affiché ses primes actuelles.
- **Ajouter un `contrat_id` à la prime** : inutile pour la paie et le PDF, et il aurait fallu une
  règle pour rattacher les primes au contrat suivant.
- **Suspendre une prime** : nouvelle règle de paie sans besoin établi. Une fin d'effet suivie d'une
  nouvelle attribution couvre le besoin.
- **Supprimer les permissions de la ressource** : elles sont toutes encore contrôlées, par le
  serveur et par la fiche.

## Conséquences

- **Aucune migration, aucune donnée modifiée, aucune permission retirée.**
- **Primes déjà créées avec une date de fin de CDD** : elles la gardent, l'historique n'est pas
  réécrit. Une date de fin ne pouvant qu'être avancée (AFF-008), une prime à prolonger se réattribue
  après sa fin.
- **Accès** : gérer les primes exige désormais aussi `rh-employes.read`, pour ouvrir la fiche. Les
  rôles système l'ont déjà ; un rôle personnalisé qui aurait les droits sur les primes sans celui-ci
  doit le recevoir.
- **Vue d'ensemble perdue** : il n'y a plus de liste de toutes les primes par agence, type ou
  période. Le détail mensuel par salarié reste dans *RH → Transmission de paie*. Un ancien lien vers
  `/backoffice/affectations-remuneration` répond 405.
