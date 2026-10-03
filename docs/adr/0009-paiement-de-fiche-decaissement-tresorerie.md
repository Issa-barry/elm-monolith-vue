# ADR 0009 — Payer une fiche = décaissement réel depuis un support de trésorerie

- **Date** : 2026-09-28
- **Statut** : accepté et livré le 2026-09-28
- **Périmètre** : paiement des fiches Livreur, Propriétaire, Site, Consultant (écrans Commissions et
  écran de la fiche), `PaymentCard`, trésorerie — cf. [commissions.md](../commissions.md),
  [encaissements.md](../encaissements.md). **Les fiches salariés sont hors périmètre** (point 7).

## Contexte

Un paiement de fiche enregistrait un mode générique (`especes`, `mobile_money` + nom libre du
wallet) et la comptabilité le rangeait sur un compte générique (571000, 561xxx) : aucune caisse ni
aucun compte réel ne baissait. Depuis l'ADR 0005, les encaissements, eux, visent un support réel et
les espèces entrent dans la caisse dédiée de l'agent : les soldes de trésorerie devenaient faux dès
qu'une commission était payée. L'écran de paiement était aussi différent de celui de l'encaissement.

## Décision

1. **Payer une fiche est une sortie de trésorerie réelle**, enregistrée sur la fiche
   (`paiement_fiche_paiements.compte_tresorerie_id`, `reference_paiement`) :
   - espèces → **caisse dédiée active du payeur** sur l'agence de trésorerie de la fiche ;
   - Mobile Money / virement / chèque → **support actif de cette agence** choisi par l'utilisateur.
2. **Mêmes moyens que l'encaissement** : liste et contrôle serveur rejouent
   `MoyensEncaissementResolver` (supports) et `CaisseAgentResolver` (caisse du payeur) — aucune règle
   parallèle. Référence obligatoire pour les moyens qui l'exigent (Mobile Money, virement).
3. **Agence de trésorerie** = agence de la fiche ; **fiche sans agence (consultant) → site
   central de trésorerie** (`SiteCentralTresorerieResolver`, ADR 0017 — anciennement « siège
   principal »). Jamais l'agence de l'utilisateur, jamais un choix manuel. Sans site central :
   paiement bloqué (« Aucun site central de trésorerie n'est configuré… »).
4. **Solde insuffisant = paiement refusé côté serveur**, sous verrou du support avec relecture du
   solde au grand livre (`TresorerieDisponibiliteService::garantirSoldeSuffisant()`, désormais
   partagé avec les mouvements de fonds). Refus = aucun effet : ni paiement, ni allocation aux
   commissions, ni écriture, ni notification. Le reste dû de la fiche est aussi relu sous verrou.
   L'écran affiche le solde du moyen choisi et désactive « Confirmer » s'il est insuffisant — simple
   confort, le contrôle serveur reste la garantie.
5. **Comptabilité** : la ligne de trésorerie vise le compte du support débité (sous-compte de la
   caisse dédiée pour les espèces), pièce rattachée à l'agence de trésorerie. Un paiement antérieur
   (sans support) garde la résolution par `compta_mappings` — jamais reclassé.
6. **Interface** : `PaymentCard` (celle de l'encaissement) en mode `decaissement`, pour les quatre
   écrans Commissions et l'écran de la fiche. Titre « Payer une commission livreur / propriétaire /
   site / consultant » ; informations Bénéficiaire, Période, Référence, Montant dû, Déjà payé ; bloc
   principal « Reste à payer ». `PaymentDialogCompact` reste inchangé pour ses autres usages.
7. **Fiche salarié : jamais payable par la fiche.** Refus serveur (« Les salaires se paient depuis
   Comptabilité > Paiement salaire. »), sans aucun effet ; pas de bouton Payer sur la fiche, qui reste
   consultable et imprimable. Raison (audit du 28/09/2026) : la fiche salarié est construite sur la
   même `PaieLigne` que le circuit Paie (`PaiePaiement`, comptabilisé par
   `PaieComptabilisationService`), sans verrou entre les deux — la payer ici permettait de payer deux
   fois le même salaire, sans écriture comptable. Les salariés ne sont volontairement pas ajoutés à
   `FicheComptabilisationService` : la paie passe la charge au paiement, un engagement à la
   validation de la fiche la compterait deux fois.

## Conséquences

- Un utilisateur sans caisse dédiée active sur l'agence ne peut payer qu'en Mobile Money, virement
  ou chèque (message identique à l'encaissement, adapté à « payer »).
- Le circuit Paie (`/comptabilite/salaires`) n'est pas modifié. Il a le même défaut que celui corrigé
  ici (mode générique, espèces hors caisse dédiée, pas de contrôle de solde) : son passage au
  décaissement réel est un **chantier séparé**, qui pourra réutiliser `garantirSoldeSuffisant()` et
  la résolution des moyens de `DecaissementFicheResolver`.
- Paiements de fiches salariés déjà enregistrés : aucune écriture comptable et ignorés par la
  `PaieLigne`. À recenser en production (`paiement_fiche_paiements` joint à `paiement_fiches` où
  `beneficiaire_type = 'salarie'`) pour détecter un éventuel double paiement ; aucune reprise
  automatique.
- Le paiement direct logistique historique (`CommissionPaymentService`) et le cashback ne sont pas
  concernés.
- L'ADR 0010 (lot 2) fera de la fiche validée, et non plus de la période, la condition de paiement :
  `FichePayableResolver` devra alors suivre `PeriodePayabilityChecker`.
