# ADR 0028 — Suppression d'un support en brouillon et désactivation contrôlée des supports

- **Date** : 2026-10-15
- **Statut** : accepté et livré le 2026-10-15
- **Périmètre** : Comptabilité > Trésorerie > Supports (caisses, banques, Mobile Money, supports
  dédiés aux agents)
- **Amende** : [ADR 0002](0002-cycle-de-vie-support-tresorerie.md) (« Pas de suppression d'un
  brouillon », « règles de désactivation inchangées », création de plusieurs brouillons dédiés)

## Contexte

Sur l'écran Supports en production (Matoto), une caisse dédiée active (571010) cohabitait avec
deux brouillons pour la même agente (571011 « (2) », 571012 « (3) »). Ces brouillons ne pouvaient
**jamais** être validés (une seule caisse dédiée active par agent et par agence), et rien ne
permettait de les retirer : l'ADR 0002 avait volontairement écarté la suppression (« un brouillon
inutile se valide puis se désactive »), ce qui est impossible ici.

Une proposition externe suggérait un système complet d'« archivage » des caisses. Confrontée à
l'existant, elle recouvrait surtout ce qui existe déjà :

| Proposition | Existant |
|---|---|
| Archiver une caisse | **Désactiver** (statut *Inactif*) : plus aucune opération, historique, compte comptable et solde consultables. |
| Bloquer l'archivage si solde ou transfert en cours | Déjà vrai pour les supports **dédiés** (CaisseAgentService). |
| Vue « caisses archivées » | Filtre **Statut** (Brouillon / Actif / Inactif) de la liste. |
| Restaurer avec contrôle d'unicité | **Réactiver**, avec l'unicité par (agent, agence[, opérateur]). |
| Pas de réactivation automatique au changement d'affectation | Aucun effet automatique. |

Il manquait réellement : la suppression d'un brouillon, le contrôle de désactivation des supports
**d'agence** (désactivables même avec un solde) et la trace de qui désactive.

## Décisions (validées par le propriétaire du produit le 2026-10-15)

1. **Seul un brouillon se supprime, définitivement.** Un brouillon n'a jamais été validé, donc
   jamais utilisable (garde du modèle) : il n'a servi à aucune opération. Un support validé — actif
   ou désactivé — ne se supprime **jamais** : il se désactive, son historique reste consultable.
   Par prudence, toute opération qui référencerait malgré tout le brouillon (mouvement de fonds,
   encaissement, paiement de fiche ou fournisseur, remboursement, solde d'ouverture) bloque aussi la
   suppression. Le **sous-compte propre** d'un support dédié (571xxx, sous-compte d'opérateur) est
   supprimé avec lui s'il n'a jamais servi (aucune écriture, aucune référence) ; un compte partagé
   du plan comptable (571000, 521000…) n'est jamais touché.
2. **Permission dédiée `tresorerie.supprimer_supports`**, distincte de la gestion
   (`tresorerie.gerer_soldes_ouverture`) et de la validation. Portée : organisation + agence de
   l'utilisateur (admins : toutes), comme la validation. Préréglages et migration de rattrapage :
   `admin_entreprise` et `comptable` (les rôles qui valident déjà les supports, ADR 0011). Pas de
   réservation « administrateurs uniquement » : la règle du projet passe toujours par une permission,
   jamais par le nom d'un rôle.
3. **Un seul support dédié « en cours » par agent et par agence** : la création d'une caisse dédiée
   est refusée tant que l'agent a, dans cette agence, une caisse dédiée **active ou en brouillon** ;
   de même pour un compte Mobile Money par opérateur. Le message nomme le brouillon existant, à
   valider ou supprimer. Un support désactivé ne bloque pas. La validation garde son contrôle
   d'unicité (doublons antérieurs encore en base).
4. **Désactivation d'un support d'agence = même règle qu'un support dédié** : refusée tant qu'un
   mouvement de fonds en part ou y arrive en transit (Envoyé ou Contesté), et tant que son solde au
   grand livre n'est pas nul (positif ou négatif). Exception : si un autre support **actif** de la
   même agence porte le même compte comptable, le grand livre ne les distingue pas et le solde reste
   affiché sur l'autre — rien n'est caché, le solde n'est pas contrôlé.
5. **Traçabilité** dans le journal d'audit (`audit_logs`) : suppression (`deleted` : auteur, date,
   fiche du support et motif facultatif — la ligne elle-même disparaît), désactivation et
   réactivation (`status_changed`). *Depuis l'ADR 0030 (2026-10-16), la fiche enregistrée ne
   contient plus de numéro de compte (colonne supprimée).*

## Décisions d'interprétation à connaître

- **Pas de nouvel état « Archivé »** : il doublonnerait `actif` (alternative déjà écartée par
  l'ADR 0002). Le vocabulaire reste Désactiver / Inactif / Réactiver.
- **Motif facultatif** à la suppression : un brouillon inutile ne justifie pas de friction ; le
  motif saisi est conservé dans la trace.
- **La réactivation n'est pas soumise au contrôle de solde** (rien ne peut être caché en remettant
  un support en service) ; celle d'un support dédié revérifie toujours ses unicités.
- **Le verrou de l'agent est pris avant toute autre lecture** à la création d'une caisse dédiée :
  sous MySQL (REPEATABLE READ), deux créations simultanées (double clic) ne passent plus toutes deux
  le contrôle.

## Conséquences

- Route `DELETE comptabilite/tresorerie/supports/{support}` (`CompteTresorerieController::destroy`),
  policy `CompteTresoreriePolicy::supprimer()`, service `SupportTresorerieSuppressionService`,
  contrôle `SupportAgenceDesactivationService`. Indicateur d'écran `peut_supprimer` (état + policy,
  le `Gate::before` du super admin étant neutralisé par le contrôle d'état explicite).
- Écran Supports : « Supprimer le brouillon » dans le menu ⋮ d'un brouillon (selon `peut_supprimer`,
  même sans la gestion des supports), fenêtre de confirmation rappelant support, agence, responsable
  et compte, motif facultatif, chargement anti double envoi.
- Migration de données `2026_10_15_100000_backfill_tresorerie_supprimer_supports_permission`
  (additive, idempotente). Les rôles personnalisés ne reçoivent pas la permission.
- Une organisation dont une caisse d'agence ou une banque détient encore de l'argent ne peut plus
  la désactiver : il faut d'abord transférer le solde vers un autre support (mouvement de fonds).
- Les doublons existants (ex. Matoto 571011, 571012) se suppriment depuis l'écran ; aucune reprise
  de données automatique.

## Références

- Code : `App\Services\Tresorerie\SupportTresorerieSuppressionService`,
  `App\Services\Tresorerie\SupportAgenceDesactivationService`,
  `App\Services\Tresorerie\CaisseAgentService`, `App\Policies\CompteTresoreriePolicy`,
  `App\Http\Controllers\Comptabilite\CompteTresorerieController`,
  `resources/js/pages/Comptabilite/Tresorerie/Supports/Index.vue`, `partials/presentation.ts`.
- Tests : `SupportTresorerieSuppressionControllerTest`, `SupportAgenceDesactivationTest`,
  `BackfillTresorerieSupprimerSupportsTest`, `SupportTresorerieValidationServiceTest`,
  `CompteMobileMoneyAgentTest`, `UserTest`, `Supports/__tests__/Index.spec.ts`,
  `partials/__tests__/presentation.spec.ts`.
- Documentation : `docs/data-dictionary-compta.md` (« Cycle de vie d'un support de trésorerie »).
