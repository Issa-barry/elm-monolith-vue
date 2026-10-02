# ADR 0016 — Remise au siège et position de trésorerie des agences

- **Date** : 2026-10-02
- **Statut** : proposé — règles 1 à 3 et comptes communs validés le 2026-10-02 ; lot 0.1 à 0.3
  livré le 2026-10-02 ; points de conception 4 à 7 à confirmer avant le lot 1
- **Périmètre** : trésorerie (financement des agences, mouvements de fonds, inter-agences),
  comptabilité générale — complète et amende [ADR 0012](0012-encaissement-inter-agences-et-reglement.md)
- **Liens** : [tresorerie-inter-agences.md](../tresorerie-inter-agences.md),
  `FinancementAgenceService`, `ObligationsAgenceService`, `DetteInterAgencesService`,
  `TresorerieDisponibiliteService`, `SiteCentralTresorerieResolver` (ADR 0017 : le « siège » est le
  site central de trésorerie, un rôle explicite du site, indépendant de son type)

## Contexte

Le fonctionnement réel de l'entreprise est centralisé : une agence garde de quoi payer ses dépenses
locales et remet le reste au site central de trésorerie (Matoto). Si elle n'a pas assez, le siège la finance.

Le code connaît déjà la moitié de ce circuit : `FinancementAgenceService` calcule
`à financer = max(0, total à régler − disponible − fonds en transit)`. Il manque l'autre moitié :
ce que l'agence doit **remettre**. Aujourd'hui une remise au siège est un « Transfert entre
agences » ordinaire, sans montant attendu ni suivi.

ADR 0012 a créé la dette inter-agences (commande de A encaissée par B → B doit à A) et la règle
« le financement déduira du disponible de B ce qu'il doit aux autres agences » (lot 3, non livré).
Construire les remises au siège à côté, sans les articuler, ferait compter deux fois le même argent :
Cba devrait 800 000 à Matoto au titre de l'inter-agences ET les mêmes 800 000 dans sa remise.

## Décision

### Règles métier (validées le 2026-10-02)

1. **Fonds encaissés pour une autre agence** : remis **à 100 %** au siège, encaissement par
   encaissement. Ils ne financent jamais les dépenses locales de l'agence qui les a encaissés. Leur
   remise au siège **solde aussi la dette inter-agences** correspondante : un encaissement ne fait
   l'objet que d'une seule sortie physique.
2. **Fonds propres** : l'agence réserve ses obligations restantes de la **prochaine échéance** —
   commissions livreurs, propriétaires, salaires (les contributeurs actuels
   d'`ObligationsAgenceService`). Le surplus est l'**excédent à remettre** au siège.
3. **Fonds propres insuffisants** : le siège finance la différence (`FinancementAgenceService`,
   inchangé dans son principe).

Un seul calcul, dans `FinancementAgenceService`, produit les trois montants :

```
disponible propre    = disponible agence − fonds dus à d'autres agences présents dans ses supports
réserve              = obligations restantes jusqu'à la prochaine échéance incluse
à financer           = max(0, réserve − disponible propre − fonds en transit vers l'agence)
excédent à remettre  = max(0, disponible propre − réserve)
remise obligatoire   = fonds encaissés pour d'autres agences, non encore engagés
total à remettre     = remise obligatoire + excédent à remettre
```

Exemple (Cba) : 800 000 encaissés pour Matoto, 200 000 propres, 700 000 de réserve
→ remise obligatoire 800 000, à financer 500 000 — jamais « 1 000 000 − 700 000 = 300 000 ».

Les autres dépenses (catégories de dépenses validées non payées) entreront plus tard dans la réserve
sous la forme d'un nouvel `ObligationContributor`, jamais d'une condition ad hoc.

### Comptes Mobile Money communs (fait métier donné le 2026-10-02)

Le compte Orange Money de l'organisation, et les autres comptes Mobile Money, sont **un seul compte
utilisé par toutes les agences**. Un paiement reçu dessus est **déjà centralisé** : l'agence qui a
encaissé n'a rien à remettre physiquement, ni au siège ni à l'agence de la commande.

D'où la règle générale : **ce qui compte n'est pas l'agence qui encaisse, mais l'agence qui détient
l'argent**, c'est-à-dire celle du support sur lequel il est arrivé. L'agence qui encaisse reste
toujours tracée (`site_encaissement_id`), quel que soit le support.

| Paiement | Détient l'argent | Remise physique |
|---|---|---|
| Espèces (caisse d'agent puis caisse de l'agence) | Agence qui encaisse | Oui, selon les règles 1 et 2 |
| Mobile Money sur un compte commun | Siège | Non |
| Virement sur un compte bancaire commun | Siège | Non |
| Compte propre à une agence | Cette agence | Oui |

Le code ne représente pas encore cette réalité : chaque agence a son propre support Mobile Money
(« Orange Money de Cba », « Mobile Money de Kouria », « Mobile Money de Matoto »), tous sur le même
compte comptable mais avec une écriture par agence. Conséquences actuelles :

- l'écran Inter-agences demande à Cba de « Régler » 800 000 à Matoto pour VTE-280926-001, payée en
  Orange Money : cet argent est déjà sur le compte commun ;
- le disponible d'une agence (`disponiblePourSite()`) compte un solde Mobile Money qu'elle ne
  détient pas ; le besoin de financement est donc sous-estimé ;
- le solde de « Orange Money de Cba » est fictif : seul le total de toutes les agences correspond au
  compte réel.

Décision (lots 0.1 à 0.3, livrés le 2026-10-02) :

- **Un compte appartient toujours à un site.** Il n'existe pas de propriétaire « Organisation » : la
  comptabilité est par site. Un compte commun est détenu par un site (`site_id`), par défaut le site
  central de trésorerie (Matoto), un autre si le compte est réellement tenu ailleurs ; il est utilisé
  par les agences cochées. `parent_id` des sites n'intervient pas (aucune portée métier).
- **Une agence peut avoir son propre compte ET utiliser le compte commun** pour le même opérateur.
  Aucun routage automatique : la fenêtre de paiement propose un moyen par compte (« Kulu — compte
  commun (Matoto) », avec son numéro) et **l'agent choisit le compte sur lequel le client a réellement
  payé**. Un Mobile Money n'est jamais présélectionné.
- **Trois informations distinctes sur l'encaissement** : l'agence qui encaisse
  (`site_encaissement_id`, traçabilité, rapports « Encaissée à ») ; le compte qui reçoit
  (`compte_tresorerie_id`) ; l'agence qui **détient** l'argent (`site_detenteur_id` = site du compte,
  figé à la création). Écritures, dette et règlements inter-agences suivent la détentrice :
  - commande de la détentrice payée sur son compte commun, quelle que soit l'agence qui encaisse :
    une seule pièce chez la détentrice, aucune dette ;
  - commande d'une autre agence A payée sur le compte commun détenu par S : pièces de liaison
    S → A comme en ADR 0012 (la détentrice doit à A), jamais une dette de l'agence qui a encaissé.
- **Encaissement seulement** : payer depuis un compte commun (paiement de fiche) n'est pas ouvert ;
  les décaissements ne voient que les comptes propres de l'agence (question 4 bis ci-dessous).

### Points de conception à confirmer

4. **Espèces encore dans les caisses d'agents.** `disponiblePourSite()` exclut les caisses dédiées
   (décision du 2026-09-19). Retirer du disponible des espèces dues qui sont encore chez l'agent
   le ferait baisser deux fois et ferait sur-financer l'agence. Proposition : ne déduire que les fonds
   dus **présents dans les supports de l'agence**. Pour les espèces, seulement la part qui dépasse
   le solde des caisses d'agents de l'agence (les espèces dues sont réputées rester d'abord chez les
   agents). Les paiements sur un support commun ne sont jamais dans le disponible de l'agence.
   La remise obligatoire reste de 100 % de la dette en espèces ou sur support propre.
4 bis. **Supports communs, reste à décider** : qui peut payer depuis un compte commun (site
   central seul, ou agences avec écriture de financement) ; reprise des supports Mobile Money par
   agence existants (nouvelle pièce de reclassement, aucune écriture existante modifiée) et sort des
   dettes inter-agences déjà ouvertes sur ces supports. Si le site central a aussi son propre compte
   du même opérateur, il lui faut un compte comptable distinct (sinon les deux soldes, même site et
   même compte, sont indiscernables — refusé à la création).
5. **Réserve = restant échu + prochaine échéance.** Une obligation d'une échéance passée encore
   impayée reste réservée : l'agence ne remet pas au siège l'argent d'une commission en retard.
   Prochaine échéance = P1 (jusqu'au 15) ou P2 (fin de mois) selon la date du calcul.
6. **Destination unique = site central de trésorerie.** Tout règlement de dette inter-agences part
   vers le site central (`SiteCentralTresorerieResolver::central()`, ADR 0017) :
   - commande du siège encaissée par B : inchangé (règlement B → siège, liaison 181000) ;
   - commande d'une agence tierce A encaissée par B : B remet au siège ; trois pièces de liaison
     (B : débit 181000 [A] / crédit trésorerie ; siège : débit trésorerie / crédit 181000 [A] ;
     A : débit 181000 [siège] / crédit 181000 [B]). La créance de A passe de B au siège ; A ne
     reçoit rien physiquement et reste financée par le siège selon la règle 3 ;
   - commande de A **encaissée par le siège** : rien à remettre, l'argent est déjà au siège. La dette
     prend le statut « Au siège » sans mouvement : la liaison comptable porte déjà « le siège doit à A ».
   Le règlement direct B → A (agence non siège), possible depuis ADR 0012, disparaît : **amende le
   point 5 d'ADR 0012** (la destination n'est plus l'agence de la commande mais le siège).
7. **Une action « Remettre au siège », deux mouvements.** La part obligatoire est un règlement
   inter-agences (encaissements liés, liaison 181000) ; l'excédent est un transfert ordinaire vers le
   siège (transit 588000, montant proposé = excédent, modifiable à la baisse). Les deux sont créés et
   envoyés dans une seule transaction, comme « Régler » (ADR 0012, point 8). La réception par le
   siège reste celle de l'écran Mouvements.

## Découpage

- **Lot 0 — supports communs** (préalable : sans lui, le disponible des agences reste faux) :
  - **0.1–0.2, livrés le 2026-10-02** : un support d'agence est « propre » ou « commun » ; un compte
    commun a une agence détentrice (`site_id`, choisie à la création, jamais modifiée) et des
    agences utilisatrices (`compta_support_tresorerie_agences`, détentrice comprise). Création et
    modification dans Trésorerie → Supports, filtre Nature « Compte commun ». Une caisse n'est
    jamais commune ; une agence n'utilise qu'un compte commun Mobile Money par compte comptable.
    **Aucun effet encore** sur les encaissements, les soldes ou la dette inter-agences ;
  - **0.3, livré le 2026-10-02** : numéro du compte (`numero`) affiché à l'encaissement ; site
    central proposé comme détentrice d'un compte commun ; un compte commun est proposé à
    l'encaissement dans ses agences utilisatrices, à côté de leurs comptes propres ;
    `encaissements_ventes.site_detenteur_id` (historique = agence d'encaissement, valeur exacte) ;
    écritures, dette, règlement, annulation exceptionnelle et « à reverser » du rapport d'activité
    suivent la détentrice ; fiche commande : « Compte commun de {détentrice} » ;
  - **0.4** : reprise des supports Mobile Money par agence (« Orange Money de Cba »…) vers le
    compte commun (pièce de reclassement, aucune écriture existante modifiée) — après accord.
- **Lot 1 — calcul** : `FinancementAgenceService` produit disponible propre, réserve, à financer,
  excédent, remise obligatoire ; écran Financement : position de chaque agence (besoin OU
  excédent, plus la remise obligatoire). Livre au passage le « financement déduit la dette » prévu
  au lot 3 d'ADR 0012. Aucun mouvement, aucune écriture.
- **Lot 2 — remise** : action « Remettre au siège » (point 7), destination forcée au siège,
  comptabilité tierce (point 6), statut « Au siège ».
- **Lot 3 — vue siège** : « Remises au siège » côté siège (à recevoir par agence, en cours, reçu,
  détail jusqu'aux commandes), Situation de trésorerie, E2E.

## Conséquences

- L'écran Inter-agences garde son rôle de traçabilité (« commande de A encaissée à B ») ; il ne
  déclenche plus de paiement vers une agence non siège.
- Aucune échéance de remise (« en retard ») tant qu'aucun délai de remise n'est fixé par le métier.
- Les remises « Transfert entre agences » déjà enregistrées ne sont pas reclassées.
