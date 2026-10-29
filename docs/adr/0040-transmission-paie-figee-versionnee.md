# ADR 0040 — Transmission mensuelle RH → Comptabilité, figée et versionnée

- **Date** : 2026-10-29
- **Statut** : accepté — lot 4 livré le 2026-10-29 (non commité)
- **Périmètre** : RH et Comptabilité, éléments de rémunération du mois — cf.
  [transmission-paie.md](../transmission-paie.md)

## Contexte

La paie de la Comptabilité lit aujourd'hui les contrats directement, à la première consultation
du mois (risques R7, R10 et R12 de la conception du 26/10/2026) : une paie validée n'est pas
explicable après coup, et une donnée RH modifiée la change silencieusement. Les affectations du
lot 3 (ADR 0039) doivent arriver en Comptabilité sous une forme figée et traçable.

## Décision

1. Tables `transmissions_paie` (organisation, année, mois, **version**, statut, motif, version
   précédente, anomalies, totaux, empreinte, auteurs et dates de chaque étape, reprise par la
   Comptabilité) et `transmission_paie_lignes` (copie complète de chaque élément par salarié et par
   partie du mois, sans clé étrangère vers les données vivantes). Index unique organisation + mois
   + version.
2. Cycle `brouillon` → `validee` (figée) → `transmise` → `remplacee` ; brouillon `abandonnee`. Une
   correction ouvre toujours une **nouvelle version** avec un motif ; les précédentes restent
   consultables. La Comptabilité lit la version transmise tant qu'une suivante n'est pas transmise.
3. **Gel** : les modèles refusent toute modification et toute suppression d'une version figée et
   de ses lignes (seuls les changements de statut, la transmission et la reprise sont enregistrés)
   ; une **empreinte SHA-256** des lignes est enregistrée et vérifiée avant la transmission. La
   validation recalcule et refuse si les données ont changé depuis le dernier calcul : on ne fige
   que ce qui a été consulté.
4. Calcul RH (`CalculElementsPaieService`) : jours calendaires, prorata, pourcentage de la même
   partie du mois, montant mensuel validé, avantage en nature valorisé à part. Le salaire de base
   vient du contrat ; ce n'est pas une prime. Anomalies bloquantes et avertissements enregistrés
   avec la version.
5. Quatre droits séparés : `rh-transmissions-paie.read` (admin_entreprise, comptable), `.preparer`
   (admin_entreprise), `.valider` et `.transmettre` (super_admin seul) — délégables. Préparer,
   valider et transmettre exigent d'agir sur toutes les agences (la version couvre toute
   l'organisation) ; la consultation est limitée aux agences consultables.
6. Frontière `App\Contracts\Rh\SourceDonneesPaie` (précédent `ObligationContributor`) :
   `transmissionCourante()`, `lignes()`, `prendreEnCharge()`. La reprise par une mise à jour
   conditionnelle garantit qu'une version n'est traitée qu'une fois.
7. Une fois la paie du mois validée par la Comptabilité, le mois ne reçoit plus de nouvelle
   version (régularisations : lot 5). Une version validée fige aussi le mois pour les affectations
   (ADR 0039).

## Écarts avec la conception du 26/10/2026

- Validation et transmission sont deux étapes et deux droits distincts (demande du 29/10/2026), au
  lieu d'une seule action « Transmettre ».
- Pas encore de notification à la Comptabilité ni d'estimation pour les obligations de trésorerie
  (`estimation()`) : lot 5, avec la lecture effective de la transmission.

## Conséquences

- Deux tables, aucune donnée existante modifiée ; 4 permissions (244 au catalogue).
- La paie de la Comptabilité ne lit pas encore la transmission : le lot 5 remplacera la lecture
  directe des contrats par `SourceDonneesPaie` et appellera `prendreEnCharge()`.
