# ADR 0016 — Remise à la trésorerie principale et position de trésorerie des agences

- **Date** : 2026-10-02
- **Statut** : accepté le 2026-10-02 (règles métier) — compte commun retiré, lot 1 (calcul, écran
  Financement) et lot 3 (vue « Remises des agences ») livrés le 2026-10-03 ; lot 2 (bouton de
  remise, frais, écritures) non développé, **après validation du lot 1**
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

- **Préalable — retirer le compte commun, fait le 2026-10-03** : le code avait été commité et poussé
  sur `dev` (commit afa4c6e4), ses migrations ne sont donc pas supprimées ; la migration
  `2026_10_03_100000_retirer_compte_commun_des_supports_tresorerie` retire `commun`,
  `compta_support_tresorerie_agences` et `encaissements_ventes.site_detenteur_id` (elle s'arrête si
  un compte commun a réellement servi). Le code revient au comportement d'ADR 0012. **Conservé** : le
  numéro du compte (`compta_supports_tresorerie.numero`), affiché sous chaque moyen à l'encaissement.
- **Lot 1 — calcul, livré le 2026-10-03** : `FinancementAgenceService::calculerPourEcheance()` ajoute
  à chaque ligne `arrieres`, `a_conserver`, `fonds_autres_agences`, `disponible_propre`,
  `remise_obligatoire`, `excedent_a_remettre`, `total_a_remettre`, `est_tresorerie_principale` ;
  `a_financer` = max(0, à conserver − disponible propre − transit). Statuts : « À remettre »
  (`a_remettre`) quand rien n'est à financer mais qu'un montant est à remettre, « Trésorerie
  principale » pour le site central (ni remise ni financement). Impayés échus : mois antérieurs lus
  par `ObligationContributor::arrieres()` (périodes existantes seulement, aucune créée) et, en vue
  « Fin de mois », les restants de la 1re quinzaine. Écran Financement : colonnes « Autres agences »,
  « Impayés échus », « À conserver », « À financer », « À remettre » (détail en infobulle) ; cartes
  « À conserver », « À remettre à la trésorerie principale », « À financer par la trésorerie
  principale ». Livre le « financement déduit la dette » prévu au lot 3 d'ADR 0012. Aucun
  mouvement, aucune écriture. **À valider avant le lot 2.**
  Limites connues : la vue par défaut reste « Mois complet » (conserve tout le mois, plus prudent que
  la seule prochaine échéance) ; pour une période passée, le statut des dettes est celui
  d'aujourd'hui ; la part « espèces chez les agents » est une estimation (cf. point 4).
- **Lot 2 — remise** : action « Remettre à la trésorerie principale » (point 7), destination
  automatique (point 5), écritures tierces, frais de transfert (point 6). Une remise envoyée est
  « En transit » jusqu'à sa confirmation par la trésorerie principale. Les remises doivent être
  identifiables comme telles (et non comme un « Transfert entre agences » ordinaire) : c'est ce qui
  permet le suivi du lot 3.
- **Lot 3 — vue « Remises des agences »** (Comptabilité → Trésorerie), règles validées le
  2026-10-03 — voir ci-dessous ; **livrée le 2026-10-03**, avant le lot 2, à la demande du métier
  (vue dédiée au Trésor principal, distincte de Financement). Restent : Situation de trésorerie, E2E.

Ordre initial : lot 1 → validation métier → lot 2 → lot 3. Le lot 3 a été livré avant le lot 2 :
d'ici là, toute sortie d'une agence vers la trésorerie principale y compte comme une remise (cf.
« Livré »), et le lot 2 n'aura qu'à créer ces mouvements proprement.

### Lot 3 — vue « Remises à recevoir » (validée le 2026-10-03)

Vue de pilotage de la trésorerie principale : ce qu'elle attend, ce qui est arrivé, ce qui reste.
Une ligne par agence autre que la trésorerie principale.

**Montants mesurés, jamais un attendu figé.** Aucun « montant attendu » n'est enregistré :

| Montant | Source |
|---|---|
| À remettre | calcul du lot 1 **à l'instant présent** (`total_a_remettre`), quelle que soit la période choisie |
| En transit | remises envoyées, pas encore confirmées par la trésorerie principale |
| Reçu sur la période | remises confirmées pendant la période choisie |
| **Attendu** | À remettre + En transit + Reçu sur la période (déduit, jamais stocké) |

On ne calcule jamais « attendu − remis » : le calcul du lot 1 baisse déjà dès qu'une remise quitte
l'agence, la soustraire une deuxième fois compterait le même argent deux fois. Exemple : Kankan doit
1 500 000 et en envoie 1 000 000 → À remettre 500 000, En transit 1 000 000, Attendu 1 500 000. Si
l'agence encaisse encore pendant la période, l'attendu augmente, ce qui est juste.

**Période** : mois en cours par défaut, modifiable comme dans Financement. Elle ne concerne que
« Reçu sur la période » et l'historique ; « À remettre » reste toujours le calcul du moment.

**Cycle d'une remise** : À remettre → En transit → Reçu ; En transit → Contesté (puis retour, workflow
existant des mouvements de fonds). La **confirmation reste du côté de la trésorerie principale** :
action « Confirmer la réception » directement sur la page, avec le choix du support qui a réellement
reçu les fonds, comme aujourd'hui (`MouvementFondsService::recevoir()`, policy
`MouvementFondsPolicy::recevoir`, permission `tresorerie.recevoir`) ; « Contester » sous
`tresorerie.rejeter`. Aucune règle de réception nouvelle.

**Statut de l'agence**, dans cet ordre de priorité :

| Statut | Condition |
|---|---|
| Données incomplètes | position non fiable (lot 1) |
| Remise en cours | au moins une remise en transit |
| Partiellement remis | reçu sur la période > 0 et à remettre > 0 |
| Remis | reçu sur la période > 0 et à remettre = 0 |
| À remettre | à remettre > 0, rien reçu ni en transit |
| Rien à remettre | tout à 0 |

Pas de « En retard » tant que le métier n'a pas fixé d'échéance de remise. Filtre par statut (pour
voir d'un coup les agences qui n'ont encore rien remis) et filtre Agence (`site_ids[]`).

**Indicateurs** : total attendu, en transit, reçu sur la période, reste à recevoir (= à remettre),
nombre d'agences par statut.

**Détail d'une agence** : attendu, en transit, reçu, reste ; chaque remise (référence, date, support
d'origine et de réception, montant, frais, part « argent d'autres agences » / « excédent ») avec le
lien vers le mouvement.

**Livré le 2026-10-03** — page `/backoffice/comptabilite/tresorerie/remises` (« Remises des agences au
Trésor principal »), menu Comptabilité → Trésorerie → « Remises des agences » ; détail
`/remises/{agence}`. `RemisesAgencesService` (calculs), `RemisesAgencesController` (interface) :

- **Une remise** = un mouvement de fonds d'une agence vers la trésorerie principale, de nature
  « Transfert entre agences » ou « Règlement inter-agences », hors brouillon et annulé. Un
  financement (trésorerie principale → agence) va dans l'autre sens et n'est jamais compté.
- **Reste à recevoir** = `total_a_remettre` de `FinancementAgenceService` pour le mois en cours,
  vue « Mois complet » — le même montant que l'écran Financement par défaut.
- **Visibilité** (`tresorerie.read`) : toutes les agences pour un administrateur ou une personne
  affectée à la trésorerie principale (sa vue de pilotage) ; sinon ses seules agences. Le détail
  d'une agence non visible est refusé (403), celui de la trésorerie principale ou d'une autre
  organisation introuvable (404).
- **Confirmer la réception** : bouton par remise en transit, affiché selon l'indicateur serveur
  `peut_recevoir` (policy `recevoir` : `tresorerie.recevoir`, état, affectation au site qui reçoit) ;
  appelle la route existante `mouvements.recevoir` avec le support choisi parmi les supports actifs
  de la trésorerie principale. « Contester » reste dans l'écran Mouvements.
- **Pas encore** : frais et part « autres agences / excédent » par remise (lot 2 : une remise
  d'aujourd'hui ne les porte pas) ; le détail en montre la répartition au niveau de l'agence.

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
