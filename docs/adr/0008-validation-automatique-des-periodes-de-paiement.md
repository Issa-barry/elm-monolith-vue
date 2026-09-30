# ADR 0008 — Validation automatique des périodes de paiement et des commissions à bénéficiaire unique

- **Date** : 2026-09-27
- **Statut** : accepté, livré le 2026-09-27
- **Périmètre** : périodes de paiement Livreur, Propriétaire, Site et Consultant (pas Salarié), génération
  des commissions, écran Périodes — cf. [commissions.md](../commissions.md)

## Contexte

Une période de paiement ne devenait payable qu'après deux gestes : valider chaque commission
(véhicule par véhicule), puis cliquer « Valider la période de paiement ». Avec 15 véhicules par
quinzaine, le second clic était systématiquement oublié ou refait (écran Livreurs affichant
« Validée — période en attente » partout). Par ailleurs, les commissions Site et Consultant
n'étaient validables depuis aucun écran : leurs périodes restaient bloquées à « Calculée ».

## Décision

1. **Validation automatique de la période** : dès que toutes les commissions d'une période sont
   validées, elle passe à « Validée » — immédiatement, même si la période n'est pas terminée.
   `PeriodeValidationService` est le seul point de passage (bouton et automatique) : mêmes
   contrôles (commissions validées, équilibre véhicule par véhicule) et mêmes effets (activation
   `CREEE → IMPAYE`, comptabilisation des fiches). Déclenchée après toute validation de commission,
   après tout calcul de période et après toute génération. Une période sans commission (dépenses
   seules) n'est jamais validée automatiquement. Validation attribuée à l'utilisateur s'il a le
   droit de valider la période, sinon au système (`validated_by` vide).
2. **Validation automatique des commissions à bénéficiaire unique** : les parts propriétaire,
   site et consultant sont validées dès leur génération (`validated_at`, `validated_by` vide).
   Leur statut reste `CREEE` : payables seulement à la validation de la période. Les parts
   livreur restent en validation manuelle (constat des absences et remplacements). Le cashback
   est exclu (sans période, le valider le rendrait versable immédiatement).
3. **Réouverture automatique** : une commission générée dans une période déjà validée (commande
   encaissée après la validation) fait repasser la période à « Calculée » — contrepassation des
   pièces « fiche validée », recalcul des fiches, puis revalidation automatique si tout est validé.
   ~~Refusée dès qu'un paiement existe sur la période~~ — **révisé le 30/09/2026** : depuis
   l'ADR 0010 (lot 1), une fiche ayant reçu un paiement n'est plus jamais supprimée ni recalculée ;
   la réouverture est donc aussi faite sur une période déjà payée, la commission tardive allant sur
   une fiche complémentaire (ADR 0010, point 4). Seule une période clôturée n'est jamais rouverte.
   La réouverture couvre aussi le cas inverse : une fiche portant une commission **annulée ou
   supprimée** depuis la validation (retour de livraison, annulation de commande) ; l'annulation des
   commissions d'une commande relance donc le traitement des périodes concernées.
4. **Rattrapage** : `php artisan commissions:valider-beneficiaire-unique [--dry-run]` applique la
   règle 2 aux commissions existantes, puis laisse les périodes complètes se valider.
5. **Garde-fou retour/annulation révisé** (`CommissionTriggerService::aDesCommissionsFigees`) :
   une commission n'est « figée » que si elle a fait l'objet d'une décision **humaine** (validée par
   un utilisateur, ajustée, versée, payée) ou si elle figure dans une période déjà payée en partie ou
   clôturée. Sans cette révision, la validation système des parts propriétaire/site/consultant (et
   la validation automatique de leur période) aurait rendu impossible tout retour ou annulation
   exceptionnelle sur presque toutes les commandes.

## Conséquences

- Plus de second clic : « Valider la période de paiement » n'est actif que s'il reste une
  validation manuelle à faire.
- Une période en cours peut être validée puis rouverte plusieurs fois au fil des nouvelles
  commissions ; l'historique d'audit trace chaque passage (validation automatique, réouverture).
- ~~Limite connue : une période validée et déjà partiellement payée ne peut pas intégrer une
  commission tardive.~~ Levée le 30/09/2026 : fiche complémentaire de la même période (décision
  utilisateur, ADR 0010 point 4 appliqué à ce cas).
- ~~La relance de génération refuse d'ajouter une cible manquante dans une période validée.~~
  Alignée le 30/09/2026 : seule une période clôturée bloque encore la complétion (COMM-018).
- Correctif associé : `PeriodeCalculatorService::recalculerPeriodesConcernees()` refusait le type de
  date transmis par le générateur (`Carbon\Carbon`) : le recalcul après complétion échouait sur
  une erreur de type. L'appel est désormais aussi isolé (journalisé) pour ne jamais faire échouer
  la génération.
