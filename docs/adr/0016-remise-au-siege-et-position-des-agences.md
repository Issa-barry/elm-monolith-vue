# ADR 0016 — Remise à la trésorerie principale et position de trésorerie des agences

- **Date** : 2026-10-02
- **Statut** : accepté le 2026-10-02 (règles métier) — aucun lot de calcul ni de remise développé ;
  le compte commun (lot 0) est abandonné, son code non commité est à retirer avant tout commit
- **Périmètre** : trésorerie (financement des agences, mouvements de fonds, inter-agences),
  comptabilité générale — complète et amende [ADR 0012](0012-encaissement-inter-agences-et-reglement.md)
- **Liens** : [ADR 0017](0017-type-de-site-et-site-central-de-tresorerie.md) (trésorerie principale
  = rôle `Site::is_central_tresorerie`, badge « Trésorerie principale », `SiteCentralTresorerieResolver`),
  [tresorerie-inter-agences.md](../tresorerie-inter-agences.md), `FinancementAgenceService`,
  `ObligationsAgenceService`, `DetteInterAgencesService`, `TresorerieDisponibiliteService`

## Contexte

Le fonctionnement de l'entreprise est centralisé : chaque agence encaisse sur ses propres supports
(caisse, Mobile Money, banque), garde de quoi payer ses obligations locales et remet le reste à la
**trésorerie principale** (aujourd'hui Matoto). Si elle n'a pas assez, la trésorerie principale la
finance.

Le code connaît déjà la moitié de ce circuit : `FinancementAgenceService` calcule
`à financer = max(0, total à régler − disponible − fonds en transit)`. Il manque l'autre moitié :
ce que l'agence doit **remettre**. Aujourd'hui une remise est un « Transfert entre agences »
ordinaire, sans montant attendu ni suivi.

ADR 0012 a créé la dette inter-agences (commande de A encaissée par B → B doit à A). Construire les
remises à côté sans les articuler ferait compter deux fois le même argent : Cba devrait 800 000 à
Matoto au titre de l'inter-agences ET les mêmes 800 000 dans sa remise.

## Décision (validée le 2026-10-02)

### 1. Supports : chaque agence a les siens

- Chaque agence possède ses propres supports de trésorerie, Mobile Money compris (un par opérateur,
  numéro du compte affiché à l'encaissement). Le **site détenteur** d'un support est l'agence
  concernée (`compta_supports_tresorerie.site_id`) ; il n'existe aucun propriétaire « Organisation ».
  Le compte Mobile Money de Matoto est simplement détenu par Matoto.
- L'argent encaissé reste dans la trésorerie de l'agence **jusqu'à sa remise** à la trésorerie
  principale. Quand l'agence a plusieurs comptes pour un opérateur, l'agent choisit celui sur lequel
  le client a payé (aucun routage automatique).
- **Pas de compte commun** utilisé directement par plusieurs agences (cf. « Abandonné »).

### 2. Fonds d'une autre agence : remis en totalité

Un encaissement d'une commande d'une autre agence (dette ADR 0012) n'appartient pas à l'agence qui
le détient : il est **exclu de son disponible**, ne paie jamais ses obligations et est **remis en
totalité** à la trésorerie principale, encaissement par encaissement. Cette remise **solde aussi la
dette inter-agences** : un encaissement ne fait l'objet que d'une seule sortie physique.

### 3. Fonds propres : l'agence garde ses obligations locales, remet le reste

L'agence conserve le montant de ses obligations locales — les postes actuels
d'`ObligationsAgenceService` :

| Obligation | Échéance |
|---|---|
| Commissions des livreurs | P1 (le 15) et P2 (fin de mois) |
| Paiements aux propriétaires | fin de mois |
| Salaires | fin de mois |

Montant conservé = **obligations de la prochaine échéance + obligations échues encore impayées**
(une commission en retard n'est jamais remise à la trésorerie principale). Les fiches de paiement
restent payées par l'agence sur sa propre trésorerie (ADR 0009). Le surplus est l'**excédent à
remettre**. Si les fonds propres ne suffisent pas, la trésorerie principale finance la différence.

D'autres obligations (dépenses validées non payées…) n'entreront dans le montant conservé que par un
nouvel `ObligationContributor`, jamais par une condition ad hoc.

### 4. Un seul calcul

`FinancementAgenceService` produit tous les montants, pour chaque agence autre que la trésorerie
principale :

```
disponible propre     = disponible agence − fonds d'autres agences présents dans ses supports
à conserver           = obligations de la prochaine échéance + obligations échues impayées
à financer            = max(0, à conserver − disponible propre − fonds en transit vers l'agence)
excédent à remettre   = max(0, disponible propre − à conserver)
remise obligatoire    = fonds d'autres agences non encore engagés dans une remise
total à remettre      = remise obligatoire + excédent à remettre
```

Exemples (Kankan, 1 500 000 à conserver, 1 000 000 appartenant à Cba) :

| Fonds propres | Remise obligatoire | Excédent | À financer | Total à remettre |
|---|---|---|---|---|
| 2 000 000 | 1 000 000 | 500 000 | 0 | 1 500 000 |
| 1 000 000 | 1 000 000 | 0 | 500 000 | 1 000 000 |

Dans le second cas, on ne calcule jamais « 2 000 000 − 1 500 000 = 500 000 » sur l'ensemble : le
million de Cba est remis, et Kankan est financée pour ses propres obligations.

Espèces encore dans les caisses d'agents : elles ne sont pas dans le disponible de l'agence tant
qu'elles n'ont pas été versées à la caisse de l'agence (décision du 2026-09-19). On ne déduit donc du
disponible que les fonds d'autres agences présents dans les **supports de l'agence** ; pour les
espèces, la part qui dépasse le solde des caisses d'agents de l'agence (les espèces dues sont
réputées rester d'abord chez les agents). La remise obligatoire reste de 100 % de la dette.

### 5. Destination : la trésorerie principale, toujours

Toute remise part vers le site `is_central_tresorerie` (`SiteCentralTresorerieResolver::central()`),
jamais choisi à la main :

- commande de la trésorerie principale encaissée par B : règlement B → trésorerie principale
  (liaison 181000, inchangé) ;
- commande d'une agence tierce A encaissée par B : B remet à la trésorerie principale T ; trois
  pièces de liaison — B : débit 181000 [A] / crédit trésorerie ; T : débit trésorerie / crédit
  181000 [A] ; A : débit 181000 [T] / crédit 181000 [B]. Les fonds de A sont physiquement chez T ;
  A en garde le droit comptablement (créance sur T) et reste financée par T selon la règle 3 ;
- commande de A encaissée par la trésorerie principale elle-même : rien à remettre ; la dette prend
  le statut « À la trésorerie principale » sans mouvement.

Le règlement direct B → A (agence qui n'est pas la trésorerie principale), possible depuis ADR 0012,
disparaît : **amende le point 5 d'ADR 0012**.

### 6. Frais de transfert : à la charge de l'agence qui remet

Les frais d'un transfert de remise (Mobile Money ou banque) sont une **charge de l'agence qui
remet**, distincte du montant remis : la trésorerie principale reçoit le montant remis en entier, et
les fonds d'une autre agence ne sont jamais diminués des frais.

Exemple : Cba remet 1 000 000, frais 10 000 → Matoto reçoit 1 000 000 ; le support de Cba diminue de
1 010 000 (1 000 000 remis + 10 000 de frais, charge de Cba). Les frais sont pris sur les fonds
propres de l'agence, jamais sur la remise obligatoire.

À construire : aujourd'hui un mouvement de fonds suppose montant envoyé = montant reçu et le plan
comptable n'a pas de compte de frais de transfert — compte de charge à paramétrer dans
`compta_mappings` (jamais un numéro codé en dur).

### 7. Une action « Remettre à la trésorerie principale »

Une seule action crée et envoie la remise, dans une transaction, comme « Régler » (ADR 0012, point
8) : la part obligatoire est un règlement inter-agences (encaissements liés, liaison 181000) ;
l'excédent, un transfert vers la trésorerie principale (transit 588000, montant proposé = excédent,
modifiable à la baisse) ; les frais, une écriture de charge chez l'agence. La réception reste celle
de l'écran Mouvements. Aucune échéance de remise (« en retard ») tant que le métier n'a pas fixé de
délai.

## Découpage

- **Préalable — retirer le compte commun** (code non commité du 2026-10-02) : option « Compte
  commun » de Supports, table `compta_support_tresorerie_agences`, colonne `commun`,
  `encaissements_ventes.site_detenteur_id` et ses usages (dette, écritures, règlement, annulation,
  rapport, fiche commande), paramètre `avecComptesCommuns`. **Conserver** le numéro du compte
  (`compta_supports_tresorerie.numero`) affiché à l'encaissement. Retrait fichier par fichier : ces
  fichiers portent aussi les changements de l'ADR 0017.
- **Lot 1 — calcul** : `FinancementAgenceService` produit disponible propre, à conserver, à
  financer, excédent, remise obligatoire ; écran Financement : position de chaque agence (besoin OU
  à remettre, plus la remise obligatoire). Livre au passage le « financement déduit la dette » prévu
  au lot 3 d'ADR 0012. Aucun mouvement, aucune écriture. **À valider avant le lot 2.**
- **Lot 2 — remise** : action « Remettre à la trésorerie principale » (point 7), destination
  automatique (point 5), écritures tierces, frais de transfert (point 6).
- **Lot 3 — vue trésorerie principale** : remises à recevoir par agence, en cours, reçues, détail
  jusqu'aux commandes ; Situation de trésorerie ; E2E.

## Abandonné : le compte commun (2026-10-02)

Un compte Mobile Money commun, détenu par un site et utilisé par plusieurs agences, a été développé
le 2026-10-02 (argent comptabilisé chez la détentrice, `site_detenteur_id` distinct de l'agence qui
encaisse), puis abandonné le même jour : le métier a confirmé que chaque agence a son propre compte et
reverse ses fonds à la trésorerie principale. Si le besoin revient : un compte appartient toujours à
un site ; l'agence qui encaisse (traçabilité) et l'agence qui détient l'argent (écritures, dette)
sont deux informations distinctes ; jamais de routage automatique.

## Conséquences

- L'écran Inter-agences garde son rôle de traçabilité (« commande de A encaissée à B ») ; il ne
  déclenche plus de paiement vers une agence qui n'est pas la trésorerie principale.
- Les remises « Transfert entre agences » déjà enregistrées ne sont pas reclassées.
- La trésorerie principale ne se remet rien à elle-même : ses propres encaissements sont déjà
  centralisés.
