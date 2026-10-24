# Encaissements de vente

> **Agence d'encaissement** (ADR 0012, 29/09/2026) : un encaissement porte l'agence qui a réellement
> reçu l'argent (`site_encaissement_id`) = **l'agence de l'utilisateur qui encaisse**, jamais celle
> de la facture par défaut. Encaisser une commande d'une autre agence exige la permission
> `factures.encaisser_autre_agence` ; un utilisateur affecté à aucune agence ne peut pas encaisser. Partout ci-dessous, « l'agence de la facture » s'entend alors
> de l'agence d'encaissement pour les moyens proposés, la caisse dédiée et la pièce comptable ; la
> vente et la créance restent à l'agence de la commande. Détail :
> [tresorerie-inter-agences.md](tresorerie-inter-agences.md).

## Modes de paiement — `App\Enums\ModePaiement` (4 valeurs stables, ne pas étendre)

`especes`, `mobile_money`, `virement`, `cheque`. **Ne jamais y ajouter une valeur par opérateur**
(Orange Money, Kulu...) — cet enum est partagé bien au-delà des encaissements de vente :

- Comptabilisation : `PlanComptableBootstrapService` seede des comptes de trésorerie (571000
  Caisse, 521000 Banque, 561000 Mobile Money générique + un compte par wallet : 561100 Orange
  Money, 561200 MTN MoMo, 561300 Djomy, 561400 Kulu, 561500 PayCard, 561600 Soutra Money) et des lignes `compta_mappings` explicitement autour de ces 4 valeurs.
  `CompteMappingResolver` résout le compte via une chaîne de repli sur le motif `"mode:detail"`
  (ex: `"mobile_money:orange"` → compte dédié si configuré → `"mobile_money"` générique → compte
  par défaut de l'événement). `TypeSupportTresorerie::fromMoyenPaiement()` classe le support
  (Caisse/Banque/Mobile Money) à partir de ces mêmes valeurs.
- Paiements sans rapport avec les ventes : commissions (vente/site/propriétaire/consultant),
  cashback, paiement de fiches (livreur/propriétaire), salaires — tous consomment
  `ModePaiement::options()` via `resources/js/components/PaymentDialogCompact.vue`.

**Conséquence directe** : stocker `mode_paiement = "orange_money"` sur un encaissement ferait
échouer la résolution du compte de trésorerie dédié (`CompteMappingResolver` ne reconnaît pas
cette valeur, aucun `":"` à dépouiller) et retomberait sur le compte de trésorerie **par défaut de
l'événement — la Caisse (571000)** : de l'argent réellement reçu en Mobile Money serait compté
comme de l'espèce en caisse. C'est l'erreur qu'a faite une première implémentation de ce chantier
(2026-09-15, corrigée le jour même) — la piste "un seul enum fusionné" doit être écartée pour
cette raison, indépendamment de toute préférence UX.

## Moyens de paiement = supports de trésorerie (décision du 24/09/2026, Mobile Money : ADR 0027)

**Règle** : un moyen de paiement n'est proposé — et accepté — que si un **support de trésorerie
actif** peut le recevoir : la caisse ou le compte Mobile Money **de l'utilisateur qui encaisse**,
ou la banque de l'agence d'encaissement. Aucune liste fixe d'opérateurs : un opérateur
sans support dans l'agence n'apparaît pas. Chaque support représente un compte financier réel.

| Moyen | D'où vient-il ? | Compte débité |
|---|---|---|
| Espèces | Caisse dédiée **active** de l'utilisateur connecté sur le site de la facture (règle propre à l'utilisateur, cf. plus bas) | Sous-compte de SA caisse (571001, 571002...) |
| Mobile Money | Un **compte Mobile Money actif de l'utilisateur** dans l'agence d'encaissement, par opérateur (depuis le 14/10/2026, ADR 0027). **Jamais un compte de l'agence**, sans repli sur lui | Sous-compte du compte de l'agent (561100001, 561100002...) |
| Virement / Chèque | Un support **Banque** actif de l'agence (chaque banque ouvre les deux) | Compte du support (521000...) |

- **Chaque Mobile Money est un compte à part.** Un support Mobile Money porte obligatoirement son
  opérateur (`compta_supports_tresorerie.operateur_mobile_money`, parmi
  `OperateurMobileMoney::avecWallet()` — `autre` exclu). Contrôles de `CompteTresorerieController` :
  le compte ne peut pas être réservé à un autre opérateur (`compta_mappings`
  `mobile_money:<detail>`, jamais un numéro codé en dur), et deux supports Mobile Money d'une même
  agence ne partagent jamais un compte (le solde se calcule par agence + compte : ils seraient
  indiscernables). Libellé automatique : « {Opérateur} de {Agence} » (ex. « Kulu de Matoto »).
- **Plan comptable par défaut** : un compte par opérateur saisissable — 561100 Orange Money,
  561200 MTN MoMo, 561400 Kulu, 561500 PayCard, 561600 Soutra Money (561000 reste le générique,
  561300 Djomy un exemple sans opérateur saisissable). La migration
  `2026_09_24_200000_add_operateur_mobile_money_to_compta_supports_tresorerie_table` crée les comptes
  manquants des organisations existantes et déduit l'opérateur des supports existants depuis leur
  compte (« Mobile Money de Matoto » sur 561100 → Orange Money). Un support sur un compte sans
  opérateur connu (561000) reste **sans opérateur** : il n'est proposé à aucun encaissement tant que
  son opérateur n'est pas renseigné dans Trésorerie > Supports (badge « Mobile Money » sans nom
  d'opérateur).
- **Ajouter un opérateur dans une agence** = créer son support (ex. « Kulu de Matoto », compte
  561400), le valider : Kulu apparaît alors dans la liste des moyens de cette agence, sans changement
  de code.

**Aucun numéro de compte affiché** (depuis le 16/10/2026, ADR
[0030](adr/0030-suppression-du-numero-des-supports-de-tresorerie.md)) : le numéro des supports
(`compta_supports_tresorerie.numero`, ajouté le 02/10/2026) a été supprimé avec ses données, c'est
une donnée sensible. Chaque moyen se distingue par son libellé ; quand plusieurs comptes existent
pour un opérateur, l'agent choisit celui sur lequel le client a réellement payé — jamais de routage
automatique ni de Mobile Money présélectionné.

**Source unique** : `App\Services\Tresorerie\MoyensEncaissementResolver` construit la liste de
l'encaisseur (`pourSitePourEncaisseur()`/`parSitePourEncaisseur()`), exposée par les écrans sous
`moyens_encaissement`, et `SaisieEncaissementVente` la rejoue (`supportPourEncaisseur()`) : le
`compte_tresorerie_id` envoyé doit y figurer avec le même mode, sinon 422 sur `compte_tresorerie_id`
(compte de l'agence, d'un autre agent, d'une autre agence ou organisation, inactif, brouillon, type
incohérent, Mobile Money sans opérateur). **L'opérateur enregistré est celui du support**, jamais une
valeur envoyée par le navigateur.

### Mobile Money : sur le compte de l'agent, jamais sur celui de l'agence (ADR 0027, 14/10/2026)

- L'encaissement Mobile Money d'une vente ou d'un acompte de précommande crédite **le compte Mobile
  Money de l'utilisateur qui encaisse**, pour l'opérateur choisi, dans l'agence d'encaissement. Il
  ne crédite **jamais** directement un compte Mobile Money de l'agence, même envoyé de force.
- **Sans compte actif pour l'opérateur, l'encaissement est refusé** (`compte_tresorerie_id` :
  « Vous n'avez pas de compte Mobile Money actif pour cet opérateur dans cette agence… »), pour
  tous les rôles. La fenêtre de paiement ne propose que les opérateurs pour lesquels l'utilisateur a
  un compte, et affiche en ambre « Mobile Money indisponible : vous n'avez aucun compte Mobile Money
  actif à {agence}. Demandez à votre responsable de vous en créer un. » quand il n'en a aucun —
  y compris quand les espèces sont le seul mode possible (révisé le 15/10/2026 : l'ancienne simple
  mention « Espèces uniquement. » ne disait pas pourquoi l'opérateur manquait). Quand **aucun**
  mode n'est utilisable (ni caisse, ni compte Mobile Money, ni banque de l'agence), l'encaissement
  est bloqué et l'alerte passe en **rouge** : « Encaissement bloqué à {agence} : aucune caisse ni
  aucun compte bancaire actif dans l'agence, et aucun compte Mobile Money actif pour vous. Demandez
  à votre responsable d'activer un moyen d'encaissement. » En paiement
  sortant (fiches, factures d'achat), rien ne change hormis le rouge du cas bloquant.
- Un portefeuille n'encaisse que dans son agence de rattachement : pour une commande d'une autre
  agence, l'agent encaisse « à son agence » (ADR 0012), la dette inter-agences étant tracée comme
  avant — elle ne dépend pas du support qui reçoit l'argent.
- L'argent rejoint ensuite le Mobile Money de l'agence par un **transfert** (Trésorerie → Supports →
  « Transférer à l'agence », cf. `data-dictionary-compta.md`).
- **Paiements sortants inchangés** : fiches, factures fournisseurs, remboursements partent des
  supports de l'agence (`parSite()`/`pourSite()`/`supportPour()`, `DecaissementSupportResolver`).
- La référence d'un encaissement ne peut pas être celle d'un transfert déjà enregistré.
- **Historique non reclassé** : les encaissements Mobile Money enregistrés avant le 14/10/2026 sur
  un compte de l'agence y restent ; leur éventuelle régularisation est une décision distincte.

### Opérateur Mobile Money — `App\Enums\OperateurMobileMoney` (champ séparé)

`encaissements_ventes.operateur_mobile_money` reste un champ séparé de `mode_paiement` (valeurs :
`orange_money`, `kulu`, `soutra_money`, `momo`, `paycard`, `autre`), pour deux raisons cumulées :
1. Le marché guinéen compte plusieurs fintechs Mobile Money — en faire des valeurs de
   `mode_paiement` obligerait à modifier cet enum partagé à chaque nouvel entrant.
2. Voir la section précédente : la comptabilisation attend `mode_paiement` ∈ {especes,
   mobile_money, virement, cheque}.

### Comptabilisation

`encaissements_ventes.compte_tresorerie_id` mémorise le support choisi. `VenteComptabilisationService`
débite **le compte de ce support** (journal résolu par le moyen de paiement, option `journal_role`),
jamais un compte déduit du seul opérateur. Les espèces suivent la caisse dédiée (section suivante).

Un encaissement **sans support** (historique antérieur au 24/09/2026, rattrapage comptable, création
hors écran) garde l'ancienne résolution par `compta_mappings` — `"mobile_money:<detail>"`, où
`<detail>` vient de `OperateurMobileMoney::detailComptable()` :

| Opérateur | Clé `compta_mappings` | Compte par défaut |
|---|---|---|
| `orange_money` | `mobile_money:orange` | 561100 |
| `momo` | `mobile_money:mtn` | 561200 |
| `kulu` / `paycard` / `soutra_money` | `mobile_money:<valeur>` | 561400 / 561500 / 561600 |
| `autre` | `mobile_money` | 561000 générique |

**Historique** : les encaissements déjà comptabilisés ne sont jamais reclassés (ADR 0001). Les
encaissements Kulu passés avant ce changement restent sur 561000, invisibles dans les supports ;
`encaissements:diagnostiquer-destination` les liste (« Compte sans support » / « Mobile Money sur
compte générique »). Leur régularisation éventuelle est une décision à part, jamais automatique.

Cf. [ADR 0005](adr/0005-moyens-de-paiement-derives-des-supports.md).

## Référence de paiement obligatoire

Champ `reference_paiement` (nullable, string 190) sur `encaissements_ventes`, requis côté backend
(`SaisieEncaissementVente`) pour le **Mobile Money** seulement. Le champ `note` reste un
commentaire libre, distinct de la référence.

**Banque : aucune référence saisie** (décision du 17/10/2026, la saisie d'une référence bancaire
étant jugée risquée). Pour un virement comme pour un chèque, la fenêtre de paiement ne demande
plus de référence (`reference_requise = false`, `MoyensEncaissementResolver`) et le serveur ne
l'exige plus — à l'encaissement d'une vente ou d'un acompte, au remboursement d'une précommande,
au paiement d'une fiche de commission et au paiement d'une facture d'achat. Espèces : jamais de
référence. *Avant le 17/10/2026, le virement exigeait une référence.*

Contrôle dans le rapport d'activité ([rapports.md](rapports.md), RAP-007) : un Mobile Money saisi
depuis le **14/09/2026** sans référence est signalé ; plus ancien, il est « antérieur à
l'obligation » ; une même référence utilisée plusieurs fois dans l'organisation (doublon
historique, tous opérateurs confondus) est signalée comme déjà utilisée.

## Unicité des références Mobile Money (01/10/2026, ADR 0014)

**Une référence de transaction Mobile Money ne sert qu'une fois dans l'organisation**, quels que
soient la vente, l'agence, l'agent ou l'**opérateur** (`123456` chez Orange Money bloque `123456`
chez Kulu). Les autres modes ne sont pas concernés : Espèces sans référence, Virement et Chèque
peuvent réutiliser une référence.

- **Normalisation** : espaces de bord et casse ignorés (`om123`, `OM123`, ` OM123 ` sont la même
  référence). La référence d'un nouvel encaissement Mobile Money est enregistrée normalisée
  (majuscules, sans espaces de bord).
- **Message** : « Référence déjà utilisée — facture VTE-011026-001 » sur le champ
  `reference_paiement`, jamais l'erreur SQL. Le numéro seul est aussi renvoyé dans
  `reference_paiement_facture` : PaymentCard l'affiche en puce copiable en un clic. Sans facture
  identifiable, repli sur « Cette référence Mobile Money a déjà été utilisée. ». La facture est
  nommée même si elle est hors du périmètre d'agences de l'utilisateur (même organisation).
- **Deux niveaux de protection** : contrôle dans `StoreEncaissementVenteController` (message),
  puis index unique sur `encaissements_ventes.cle_reference_mobile_money`
  (`organisation|RÉFÉRENCE`), calculée par le modèle `EncaissementVente` pour tout appelant. Deux
  saisies simultanées de la même référence : la seconde est refusée par la base, sa transaction
  est annulée (aucune pièce comptable) et l'utilisateur reçoit le même message.
- **Suppression** : un encaissement supprimé (suppression, annulation exceptionnelle) libère sa
  référence.
- **Isolation** : l'unicité est par organisation — une organisation ne voit jamais, même
  indirectement, les références d'une autre.
- **Historique** : les doublons antérieurs à la règle ne sont ni modifiés ni supprimés. Le plus
  ancien encaissement de chaque groupe porte la clé (la référence reste bloquée), les suivants
  restent sans clé. `php artisan encaissements:doublons-reference-mobile-money
  [--organization=ID]` les liste, en lecture seule ; leur régularisation est une décision humaine.

## Une seule liste déroulante côté UI — `resources/js/components/payment/PaymentCard.vue`

L'utilisateur ne voit jamais de sélection en deux temps ("Mobile Money" puis "Opérateur") ni de
choix de caisse : une seule liste "Mode de paiement" propose **Espèces** puis les moyens reçus du
backend (`moyens` ← `moyens_encaissement`), par exemple à Matoto : Espèces, Orange Money,
Virement bancaire — UBA, Chèque — UBA. Deux wallets du même opérateur dans une agence sont
distingués par le libellé du support. Choisir un moyen envoie `mode_paiement` +
`compte_tresorerie_id` (logique d'affichage dans `components/payment/moyensEncaissement.ts`).
Aucun support Mobile Money ni bancaire actif : seules les espèces sont proposées, avec un message
d'information (bleu).

`PaymentCard` est utilisé par les quatre écrans qui permettent d'encaisser un paiement de vente
(consolidation du 2026-09-15, remplaçant 4 pop-up dupliquées identifiées le même jour) :

- `resources/js/pages/Ventes/Show.vue`
- `resources/js/pages/Ventes/Index.vue` (avec `info-rows` pour afficher Commande/Montant total)
- `resources/js/pages/Distributions/Show.vue` (même contrôleur backend que Ventes/Show.vue,
  `ShowCommandeVenteController`, `nature_operation` différente)
- `resources/js/pages/Factures/Index.vue`

Toute future page d'encaissement de vente doit réutiliser ce composant plutôt que recréer un
dialog ad hoc, et lui passer `moyens_encaissement` (sinon seules les espèces sont proposées).

**`resources/js/components/PaymentDialogCompact.vue` est un composant différent**, partagé par des
paiements sans rapport (commissions, salaires, paie, cashback, paiement de fiches — cf. liste
ci-dessus). Ne pas le confondre avec `PaymentCard` : la règle « moyens = supports de l'agence » ne
s'y applique pas encore (paiements sortants, hors périmètre du 24/09/2026).

## Comptabilisation : caisse dédiée de l'agent (espèces)

Décision du 2026-09-19 (ADR [0001](adr/0001-caisse-dediee-agent-sous-compte.md), phase 2). Chaque
encaissement génère une pièce `encaissement_vente_recu` (débit trésorerie / crédit client 411,
`VenteComptabilisationService::comptabiliserEncaissementVente()`). La trésorerie débitée est, **par
défaut**, celle du moyen de paiement (`compta_mappings` : espèces → 571000, virement/chèque → 521000,
Mobile Money → 561xxx). Elle devient le **sous-compte de la caisse dédiée de l'agent** quand
`App\Services\Tresorerie\CaisseAgentResolver` désigne une caisse — **toutes** ces conditions :

| Condition | Pourquoi |
|---|---|
| `mode_paiement = especes` | Seul l'argent physiquement détenu par l'agent alimente sa caisse. Mobile Money, virement et chèque gardent leurs supports habituels. |
| L'auteur de l'encaissement (`created_by`) a une caisse dédiée **active** (donc validée — un brouillon ne reçoit rien) | Sans caisse, un **nouvel** encaissement en espèces est refusé (cf. « Espèces : caisse dédiée obligatoire »). Seuls les encaissements déjà enregistrés, ou sans auteur, suivent encore le comportement historique (571000). |
| La caisse est celle du **site de la facture** | La pièce est déjà rattachée au site de la facture ; l'agent a au plus une caisse active par site, donc jamais d'ambiguïté. |
| Date de l'encaissement **≥** date de **mise en service** de la caisse (sa validation, `valide_le`) | Pas de reclassement d'un encaissement antidaté. |
| L'encaissement a été enregistré **après** la mise en service de la caisse | Filet pour les rattrapages comptables (`ComptabiliteRattrapageCommand`), qui repassent sur d'anciens encaissements : l'historique n'est jamais reclassé — y compris ceux enregistrés pendant que la caisse était encore en brouillon. |

- **Crédit** : toujours le compte client 411 — le produit est déjà constaté à la facturation.
- **Journal** : celui d'un encaissement en espèces (« Caisse ») — la ligne client n'en porte pas
  volontairement. Le moteur `EcritureComptableService` accepte pour cela l'option de ligne
  `journal_role` (compte imposé, journal tiré du mapping de ce rôle) ; sans effet pour les autres
  appelants.
- **Suppression d'un encaissement** : la contrepassation reprend les comptes de la pièce d'origine,
  donc l'extourne vise le même sous-compte. Un encaissement reçu pour une autre agence a deux pièces,
  toutes deux contrepassées ; s'il est déjà engagé dans un règlement inter-agences, sa suppression
  est refusée (ADR 0012). Depuis le 24/09/2026, la route
  `DELETE /encaissements/{id}` exige la permission `ventes.annuler_exceptionnel` (auparavant : aucune
  permission, seulement l'organisation). Pour défaire une commande saisie par erreur, utiliser
  l'annulation exceptionnelle (cf. [annulation-exceptionnelle.md](annulation-exceptionnelle.md)),
  qui ajoute les garde-fous caisse/commission/cashback absents de la suppression unitaire.
- **Situation vs Financement** : l'argent ainsi encaissé apparaît dans la Situation (solde de la
  caisse de l'agent) mais n'entre pas dans le « disponible » du Financement tant qu'il n'est pas
  versé à la caisse de l'agence (phase 3).
- Aucune colonne n'a été ajoutée à `encaissements_ventes` : la caisse destinataire se lit dans la
  pièce comptable (Journal financier, filtre par compte).

## Espèces : caisse dédiée obligatoire

**Plusieurs agents d'une même agence, chacun sa caisse** (ex. Matoto : caisse agence 571000,
caisse Moussa 571001, caisse Ousmane 571002) : chacun voit la même option unique « Espèces », jamais
la liste des caisses ni un choix de caisse. L'argent va **toujours** dans la caisse dédiée active de
**l'utilisateur connecté** sur le site de la facture (`CaisseAgentResolver` : une seule caisse
active par agent et par site, donc jamais d'ambiguïté) — Moussa ne peut pas encaisser dans la caisse
d'Ousmane, ni dans la caisse de l'agence. Sans caisse active, « Espèces » reste affiché mais
désactivé avec son message (décision du 23/09/2026, conservée). Le même moyen « Espèces » vise donc
un compte différent selon l'utilisateur ; les autres moyens, eux, dépendent de l'agence.

Décision du 2026-09-23 (ADR [0001](adr/0001-caisse-dediee-agent-sous-compte.md)). Avant elle, un agent
sans caisse dédiée pouvait encaisser en espèces : l'argent retombait sur le compte partagé 571000 et
personne n'en était responsable. **Un encaissement en espèces exige désormais une caisse dédiée
active de l'auteur sur le site de la facture.**

| Point | Règle |
|---|---|
| Périmètre | `mode_paiement = especes` uniquement. Mobile Money, virement et chèque ne touchent jamais la caisse de l'agent et restent possibles sans caisse. |
| Qui | Tous les rôles, administrateurs et super admin compris : aucun contournement par le rôle (la caisse est celle de l'auteur de l'encaissement, `created_by`). |
| Garde serveur | `CaisseAgentResolver::garantirCaissePourEspeces()`, appelée par `StoreEncaissementVenteController` avant toute écriture. Erreur sur `mode_paiement` (aucune caisse active sur le site de la facture : brouillon, désactivée, autre site ou caisse d'un autre agent) ou sur `date_encaissement` (date antérieure à la mise en service de la caisse). |
| Cohérence | La garde réutilise les conditions du routage comptable : « accepté » ⇔ « comptabilisé dans une caisse dédiée ». Les deux ne peuvent pas diverger. |
| Interface | Le backend expose `peut_encaisser_especes` (fiche vente/distribution : `commande.peut_encaisser_especes` ; listes Ventes et Factures : par ligne). `PaymentCard` reçoit `:especes-disponibles` : l'option Espèces est désactivée, un message ambre permanent l'explique, et aucun autre mode n'est présélectionné à la place. |
| Message | « Vous ne disposez pas d'une caisse active … Contactez votre responsable pour qu'il vous en crée une. » |

- **Créer la caisse** reste une action manuelle : écran Trésorerie → Supports (création en
  brouillon puis validation, permission `tresorerie.valider_supports`, cf. ADR 0002).
- **Historique** : aucun encaissement déjà enregistré n'est reclassé. Le diagnostic des encaissements
  existants est en lecture seule (`php artisan encaissements:diagnostiquer-destination`, options
  `--organization`, `--depuis`, `--detail`, `--tout`, `--csv=`). Il lit la pièce comptable de chaque
  encaissement (compte de trésorerie réellement débité) et range chacun dans **une** catégorie, la
  première qui s'applique :

  | Catégorie | Signification | Statut |
  |---|---|---|
  | Aucun mouvement comptable / écriture contrepassée | Pas de pièce `encaissement_vente_recu` (rattrapage : `comptabilite:rattraper`) | À traiter |
  | Destination non identifiable | Pièce sans ligne de trésorerie débitée | À traiter |
  | Compte incohérent avec le moyen de paiement | Ex. espèces débitées sur un compte Mobile Money (attendu : espèces 571, Mobile Money 561, virement/chèque 521) | À traiter |
  | Montant comptabilisé ≠ montant encaissé | Autre anomalie | À traiter |
  | Espèces hors caisse dédiée | Espèces débitées sur un compte 571 partagé — cas d'avant la règle du 23/09/2026 ou encaissé avant la mise en service de la caisse | À traiter |
  | Compte sans support de trésorerie | Le solde existe au grand livre mais reste invisible dans Situation/Financement (les supports se lisent par compte **et** par site) | À traiter |
  | Mobile Money sur compte générique | Débité sur 561000 : un support existe mais l'opérateur n'est pas distingué | À traiter |
  | Caisse dédiée de l'agent / support identifié | Destination claire et cohérente | Conforme |

  La sortie donne les totaux, les ventilations par agent, agence et moyen de paiement (avec le compte
  réellement débité), et la liste des agents à équiper d'une caisse. Toute régularisation (contrepassation,
  réaffectation) est une décision métier explicite, jamais automatique : elle doit être tracée
  (auteur, date, ancienne et nouvelle destination, motif).

## Réutilisation pour les sorties : paiement des fiches (ADR 0009)

`PaymentCard` sert aussi à **payer** une fiche de commission (jamais une fiche salarié : les salaires
se paient depuis Comptabilité > Paiement salaire), en
mode `sens="decaissement"` : mêmes moyens (`MoyensEncaissementResolver` sur l'agence de la fiche, ou le
site central de trésorerie pour une fiche sans agence, ADR 0017), même caisse dédiée pour les espèces (celle du payeur),
même règle de référence. En plus : solde disponible du moyen choisi (`solde_disponible`,
`soldeEspeces`) et « Confirmer » désactivé s'il est insuffisant ; le refus réel est serveur, sous
verrou (`TresorerieDisponibiliteService::garantirSoldeSuffisant()`, partagé avec les mouvements de
fonds). Détail : [commissions.md](commissions.md) et
[ADR 0009](adr/0009-paiement-de-fiche-decaissement-tresorerie.md). Le mode `encaissement` (défaut)
est inchangé.

## Backend comme source de vérité

La validation (référence/opérateur obligatoires selon le mode) est portée exclusivement par
`StoreEncaissementVenteController` (validation inline, pas de FormRequest dédié) — point de
création unique (`$facture->encaissements()->create()`), quel que soit l'écran appelant. Chaque
frontend reproduit ces règles pour l'UX (champs conditionnels, bouton désactivé) mais n'est jamais
la seule protection.

### Jamais plus que le restant dû, même en saisies simultanées (24/10/2026)

Incident VTE-221026-080 (22/10/2026) : deux encaissements espèces de 450 000 GNF enregistrés dans la
même seconde pour une facture de 450 000 GNF (encaissé 900 000). Le plafond `montant ≤ montant_restant`
n'était lu qu'à la validation, hors transaction : deux requêtes concurrentes (double envoi, deux
onglets, relance réseau) voyaient le même restant et passaient toutes les deux.

Désormais `StoreEncaissementVenteController` verrouille la facture (`lockForUpdate`) au début de la
transaction et relit l'encaissé par une lecture verrouillante avant de créer l'encaissement : la
seconde saisie attend la première, voit le restant à jour et est refusée sur le champ `montant`
(« Cette facture est déjà soldée : cet encaissement a déjà été enregistré. » si le restant est nul).
Côté écran, `PaymentCard` ignore tout nouvel envoi tant que `processing` est vrai. Test :
`EncaissementVenteTest::test_saisie_concurrente_ne_depasse_jamais_le_restant_du`. Vérifié aussi
sous MySQL avec deux processus réels : l'ancien code enregistrait 2 × 450 000 pour 450 000 quand la
seconde requête écrivait juste après la première ; le nouveau refuse la seconde.

### Suppression d'un encaissement : tout ou rien (24/10/2026)

`DestroyEncaissementVenteController` englobe la trace d'audit, la suppression, le recalcul du
statut de la facture et la contrepassation des écritures (hook `EncaissementVente::deleted`) dans
une seule transaction : si la contrepassation échoue, rien n'est supprimé et l'utilisateur reçoit
« Encaissement non supprimé : … ». Avant, l'encaissement disparaissait alors que son écriture
comptait encore en caisse. Test : `test_echec_de_contrepassation_annule_la_suppression`.

## Acompte de précommande (ADR 0019)

Cf. [precommandes.md](precommandes.md). L'acompte versé à la création d'une précommande est un
`EncaissementVente` marqué `est_acompte = true`, saisi dans la même `PaymentCard` et soumis aux mêmes
contrôles que tout encaissement — un seul circuit, factorisé dans
`App\Services\Ventes\SaisieEncaissementVente` (agence, support actif, référence obligatoire et unique,
caisse dédiée pour les espèces). Différences :

- il est reçu **avant** la remise : c'est la seule exception à « aucun encaissement avant chargement
  validé » ; il est toujours reçu par l'agence de la précommande (décision D11) ;
- il est crédité en **avance client (419100)**, pas sur le compte client (411) ;
- il ne fait **jamais** passer la facture (encore « Créée ») à « Partiel » ou « Payée » : ni
  commission, ni cashback, ni clôture ;
- obligatoire ou non, et à quel taux minimum : **Paramètres → Ventes → Précommandes** (D6) ;
- des **acomptes complémentaires** sont possibles tant que la précommande n'est pas remise (même
  route `encaissements.store`, bouton « Ajouter un acompte » de la fiche), toujours reçus par l'agence
  de la précommande ;
- à la remise, les acomptes sont imputés sur la facture (419100 → 411000) et le reste dû s'encaisse
  normalement. Un trop-perçu se rembourse (décaissement ADR 0009, `remboursements_ventes`) et bloque
  la clôture tant qu'il n'est pas rendu ; le statut et le reste à payer sont calculés **net des
  remboursements** (`factures_ventes.montant_rembourse`).

## Retour de livraison avant encaissement

Cf. [retour-commande.md](retour-commande.md). Tant qu'aucun encaissement n'a eu lieu, un utilisateur
habilité (`ventes.enregistrer_retour`) peut enregistrer le retour de tout ou partie de la marchandise
chargée : la facture est recalculée sur la quantité **livrée** (chargée − retournée), donc le restant
dû et le plafond d'un encaissement (`montant ≤ montant_restant`) suivent automatiquement. Un retour
**total** passe la commande en `retournee` et **annule** sa facture : plus aucun encaissement n'est
possible (`StoreEncaissementVenteController` refuse une facture annulée), et `isEncaissable()` exclut
ce statut. Un retour est refusé dès le premier encaissement (commande `livree`, ou montant encaissé
non nul) — il n'existe pas de mécanisme d'avoir/remboursement pour un retour **après** encaissement.

## Historique affiché

Chaque contrôleur qui expose une liste d'encaissements (`ShowCommandeVenteController`,
`IndexCommandeVenteController`, `IndexFactureVenteController`) inclut `operateur_mobile_money_label`
et `reference_paiement` dans le payload, affichés dans l'historique correspondant (ex: "Mobile
Money (Orange Money)" + colonne Référence).

## Statut affiché de la commande après encaissement complet

Une vente directe reste au statut brut `facturation` (libellé « À encaisser ») jusqu'à sa clôture
automatique, qui attend aussi le versement des commissions (`CommandeVente::cloturerSiComplete()`).
Entre l'encaissement complet et ce versement, la facture est « Payée » alors que la commande
affichait encore « À encaisser » — contradiction corrigée le 19/09/2026.

- `CommandeVente::statutAffichage()` (source unique) renvoie `{value, label}` : pour une commande
  `facturation` dont la facture est `payee`, `commissions_a_verser` / « Commissions à verser »
  (point bleu, comme l'étape « Commissions » de la frise) ; sinon le statut brut et son libellé
  habituel. Une facture non soldée, annulée ou absente garde « À encaisser ».
- Exposé sous `statut_affichage` par `ShowCommandeVenteController` (fiche Ventes et Distributions),
  `IndexCommandeVenteController` (liste desktop et mobile) et la colonne « Statut » de l'export
  `VenteListExport`.
- **Inchangés** : le statut brut `statut`, `statut_label` (API client, mobile, scan), le workflow
  et la règle de clôture. Le filtre « Statut commande » de la liste et de l'export reste basé sur
  le statut brut : filtrer sur `facturation` inclut donc aussi les ventes « Commissions à verser ».
