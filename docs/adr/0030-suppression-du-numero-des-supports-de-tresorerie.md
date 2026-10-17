# ADR 0030 — Suppression du numéro des supports de trésorerie (donnée sensible)

- **Date** : 2026-10-16
- **Statut** : accepté et livré le 2026-10-16
- **Périmètre** : Comptabilité > Trésorerie > Supports, encaissement des ventes (PaymentCard),
  transfert Mobile Money vers l'agence
- **Amende** : [ADR 0027](0027-compte-mobile-money-dedie-agent.md) (numéro du portefeuille
  obligatoire, règle « un portefeuille = un seul compte », correction du numéro en brouillon),
  [ADR 0016](0016-remise-au-siege-et-position-des-agences.md) (numéro « conservé » et affiché à
  l'encaissement), [ADR 0028](0028-suppression-brouillon-et-desactivation-controlee-des-supports.md)
  (le numéro ne figure plus dans la trace d'audit d'une suppression)

## Contexte

Le propriétaire du produit ne veut plus collecter ni demander le numéro d'un compte Mobile Money —
appelé « référence Mobile Money » dans la demande — à la création ou à la modification d'une
caisse : c'est une donnée sensible. Dans l'application, ce numéro était la colonne
`compta_supports_tresorerie.numero` (depuis le 2026-10-02, ADR 0016), commune à tous les supports :

- **obligatoire** pour le compte Mobile Money d'un agent (« Numéro du portefeuille », ADR 0027) ;
- facultatif pour un Mobile Money d'agence ou une banque (« Numéro du compte ») ;
- affiché à l'encaissement (« N° … » sous chaque moyen), dans la fenêtre de transfert vers
  l'agence et dans la liste Supports ; écrit dans la trace d'audit d'une suppression de brouillon ;
- support de la règle « un portefeuille = un seul compte » (ADR 0027) : deux supports non désactivés
  d'un même opérateur ne pouvaient pas porter le même numéro (comparaison avec ou sans indicatif).

À ne pas confondre avec la **référence de transaction** Mobile Money saisie à l'encaissement
(ADR 0014), qui n'est pas concernée.

## Décisions (validées par le propriétaire du produit le 2026-10-16)

1. **Plus aucun numéro n'est demandé ni accepté** pour un support de trésorerie : création et
   modification, compte d'agent comme support d'agence, Mobile Money comme banque. Aucun champ de
   remplacement. Un numéro envoyé malgré tout est ignoré (jamais validé, jamais enregistré).
2. **La colonne `numero` est supprimée avec ses données**, banques comprises (migration
   `2026_10_16_100000_drop_numero_from_compta_supports_tresorerie_table`) — choix explicite du
   propriétaire du produit, informé que la colonne portait aussi le numéro bancaire. Irréversible :
   le rollback ne recrée qu'une colonne vide.
3. **La règle « un portefeuille = un seul compte » est abandonnée** : sans numéro, elle ne peut plus
   être contrôlée. Restent garanties toutes les autres règles des comptes Mobile Money d'agents :
   un seul compte actif ou en brouillon par (agent, agence, opérateur) (ADR 0027, 0028), sous-compte
   comptable propre, cycle de vie, étanchéité avec les caisses en espèces, transfert vers l'agence.
4. **Le numéro n'est plus exposé nulle part** : liste Supports, moyens d'encaissement (PaymentCard),
   fenêtre de transfert, trace d'audit.

## Conséquences

- À l'encaissement, l'agent distingue les comptes par leur libellé (opérateur, agence ou agent),
  plus par leur numéro. Le compte Mobile Money d'agence reste repérable par son libellé automatique
  « {Opérateur} de {Agence} », unique puisqu'un seul Mobile Money par compte comptable et par agence
  est admis.
- Deux comptes d'agents différents peuvent désormais représenter le même portefeuille réel sans que
  l'application le détecte : contrôle organisationnel, plus applicatif.
- Code retiré : `CompteTresorerie::normaliserNumero()` / `memeNumero()`,
  `CaisseAgentService::garantirNumeroLibre()` / `garantirNumeroNonPrisParUnAgent()` /
  `numeroCorrige()`, paramètre `$numero` de `creerCompteMobileMoney()`. La création concurrente
  reste sérialisée par les verrous de l'agent et du compte de l'opérateur ; le test de concurrence
  MySQL prouve désormais qu'un double clic ne crée qu'un compte par agent.

## Références

- Code : `App\Services\Tresorerie\CaisseAgentService`, `App\Models\CompteTresorerie`,
  `App\Http\Controllers\Comptabilite\CompteTresorerieController`,
  `App\Services\Tresorerie\MoyensEncaissementResolver`,
  `App\Services\Tresorerie\SupportTresorerieSuppressionService`,
  `resources/js/pages/Comptabilite/Tresorerie/Supports/Index.vue`,
  `resources/js/components/payment/PaymentCard.vue`, `moyensEncaissement.ts`.
- Tests : `CompteMobileMoneyAgentTest`, `CompteTresorerieMobileMoneyAgentControllerTest`,
  `CompteTresorerieControllerTest`, `EncaissementVenteTest`, `EncaissementMobileMoneyCompteAgentTest`,
  `CompteMobileMoneyAgentConcurrenceTest`, `Supports/__tests__/Index.spec.ts`, E2E
  `mobile-money-agent-flow.spec.ts`, `tresorerie-financement-flow.spec.ts`.
- Documentation : `docs/data-dictionary-compta.md`, `docs/encaissements.md`.
