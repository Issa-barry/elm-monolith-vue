# ADR 0027 — Compte Mobile Money dédié à l'agent et transfert vers l'agence

- **Date** : 2026-10-13
- **Statut** : accepté le 2026-10-13 (règles métier) — **lot 1 livré le 2026-10-13, lot 2 livré le
  2026-10-14** ; lots 3 et 4 non développés, chacun après validation du précédent
- **Périmètre** : encaissements de vente, supports de trésorerie, mouvements de fonds, financement
  et remises des agences, rapport d'activité
- **Amendera, à la livraison du lot concerné** : [ADR 0005](0005-moyens-de-paiement-derives-des-supports.md)
  point 2 (pour le Mobile Money), [ADR 0001](0001-caisse-dediee-agent-sous-compte.md) points 3 et 4
- **Complète** : [ADR 0016](0016-remise-au-siege-et-position-des-agences.md) (points 4 à 6),
  [ADR 0014](0014-unicite-des-references-mobile-money.md),
  [ADR 0012](0012-encaissement-inter-agences-et-reglement.md)

## Contexte

Depuis l'ADR 0005, un paiement Mobile Money est encaissé sur un compte **de l'agence**
(`MoyensEncaissementResolver` ne propose que des supports sans agent) : l'application ne sait pas
quel agent a reçu l'argent. Les espèces, elles, vont dans la caisse dédiée de l'agent qui encaisse
(ADR 0001), puis sont versées à l'agence.

Le métier demande le même circuit pour le Mobile Money : chaque agent encaisse sur **son** compte
Mobile Money professionnel, puis transfère les fonds au Mobile Money de son agence ou, s'il y est
autorisé, à la trésorerie principale.

Constats de l'audit du 2026-10-13 :

- le code des caisses dédiées suppose partout « support d'agent = caisse en espèces »
  (`CaisseAgentResolver::caisseActive()`, `CaisseAgentService::garantirUneSeuleCaisseActive()`,
  `FinancementAgenceService::soldeCaissesAgentsParSite()`, `FicheCaisseService`,
  `RapportActiviteService`) ;
- la liste des moyens d'encaissement sert aussi aux paiements sortants
  (`DecaissementSupportResolver`, `PrecommandeService::supportRemboursement()`) ;
- un support d'agent ne peut ni recevoir ni émettre un mouvement entre agences
  (`MouvementFondsService::refuserCaisseDediee()`), et son seul mouvement sortant est le versement
  en espèces vers une caisse de l'agence ;
- un mouvement de fonds suppose montant envoyé = montant reçu ; aucun frais n'est saisi (ADR 0016
  point 6, non développé) ; sa référence externe est libre et facultative ;
- aucune intégration avec un opérateur n'existe : l'application constate des transferts faits sur
  le téléphone, elle ne les exécute pas.

## Décision

### 1. Un compte Mobile Money dédié par agent, agence et opérateur

- Un compte Mobile Money dédié est un support de trésorerie de type Mobile Money rattaché à un
  agent (`agent_id`), une agence et un opérateur. Le **numéro** du portefeuille est obligatoire.
- **Un seul compte actif par (agent, agence, opérateur).** La caisse en espèces de l'agent reste un
  support distinct : la règle « une seule caisse dédiée active par (agent, agence) » de l'ADR 0001
  ne vaut plus que pour les caisses.
- **Un portefeuille réel = un seul compte.** Le numéro d'un compte d'agent ne peut être porté par
  aucun autre support Mobile Money non désactivé du même opérateur dans l'organisation. Un
  portefeuille est donc rattaché à une seule agence.
- Chaque compte a son **propre sous-compte comptable**, créé automatiquement sous le compte de son
  opérateur ; son solde est celui de ce sous-compte au grand livre (même principe que l'ADR 0001).
- Même cycle de vie que les caisses dédiées (ADR 0002) : brouillon, validation, actif, inactif. Il
  démarre à 0, sans solde d'ouverture, et ne se désactive ni avec un solde ni avec un transfert en
  cours.
- Il s'alimente uniquement par les encaissements de son titulaire. Il n'entre pas dans le
  disponible de l'agence (Financement) tant que l'argent n'a pas été transféré et reçu ; il figure
  dans la Situation de trésorerie.

### 2. Encaissement : jamais sur le Mobile Money de l'agence

- Pour un encaissement de vente ou un acompte de précommande, le Mobile Money proposé et accepté
  est **uniquement** un compte dédié actif de l'utilisateur qui encaisse, dans l'agence
  d'encaissement. Les comptes Mobile Money de l'agence ne sont plus proposés ni acceptés.
- Règle valable pour **tous les rôles**, sans contournement, garantie côté serveur. Sans compte
  actif pour l'opérateur, l'encaissement est refusé.
- **Le portefeuille n'encaisse que dans son agence de rattachement.** Un agent de CBA qui encaisse
  une commande de Matoto l'encaisse « à CBA » (ADR 0012) : son portefeuille de CBA reçoit l'argent
  et la dette CBA → Matoto est tracée. Aucune notion « agence détentrice ≠ agence d'encaissement »
  n'est réintroduite (abandonnée avec le compte commun, ADR 0016).
- Inchangés : espèces (caisse dédiée de l'agent), virement et chèque (banque de l'agence).
- **Paiements sortants inchangés** : fiches de commission, factures fournisseurs et remboursements
  clients continuent de partir des supports de l'agence. Les deux listes (encaissement,
  décaissement) deviennent distinctes.
- L'historique n'est jamais reclassé.

### 3. Transfert de l'agent vers le Mobile Money de son agence

- Mouvement de fonds d'une **nature distincte**, même circuit que le versement de caisse : créé et
  envoyé en une action, Envoyé → Reçu, ou Contesté → Retour confirmé ; écritures via le compte de
  transit 588000.
- Origine : compte Mobile Money dédié actif. Destination : compte Mobile Money **de l'agence**,
  actif, de la même agence et du **même opérateur**, fixé à l'envoi.
- **Référence de transaction obligatoire**, normalisée et unique dans l'organisation.
- Solde du compte de l'agent contrôlé au grand livre sous verrou, sur le montant **plus les frais**.
- Droits : ceux du versement de caisse (ADR 0001) — initier avec `tresorerie.verser`, confirmer
  avec `tresorerie.recevoir`, contester avec `tresorerie.rejeter`.
- Ce transfert **ne solde jamais une dette inter-agences**.
- L'application constate un transfert réel : elle ne l'exécute pas et ne le présente jamais comme
  vérifié auprès de l'opérateur. La confirmation de réception est le contrôle.

### 4. Frais de transfert : saisis à chaque transfert

- Les frais sont **obligatoires à chaque transfert** d'un compte d'agent : la saisie est explicite,
  0 est accepté, le champ n'est jamais laissé vide.
- Une seule formulation, celle de l'ADR 0016 point 6 :

  ```
  montant du transfert = ce que le destinataire reçoit
  frais                = en sus, charge de l'agence de l'agent
  sortie du compte     = montant du transfert + frais
  ```

  Exemple : 495 000 transférés, 5 000 de frais → 500 000 sortent du portefeuille de l'agent,
  l'agence reçoit 495 000.
- Les fonds d'une autre agence ne sont jamais diminués des frais : la dette reste due en entier.
- Le compte de charge est paramétré dans `compta_mappings`, jamais un numéro codé en dur.
- Un transfert contesté puis retourné rend le montant au compte de l'agent ; les frais déjà
  facturés restent une charge.

### 5. Transfert direct vers la trésorerie principale

- Autorisé **uniquement par une permission dédiée** : sans elle, l'agent transfère à son agence.
- C'est une **remise de l'agence de l'agent** (ADR 0016) : destination = site central de
  trésorerie, jamais choisi à la main ; support de réception choisi par la trésorerie principale à
  la confirmation ; visible dans « Remises des agences ».
- La part qui correspond à des encaissements d'une autre agence **solde la dette inter-agences à
  la confirmation de réception**, jamais à l'envoi (ADR 0016 points 2 et 5).
- Référence et frais : mêmes règles qu'au point 3 et au point 4.

## Découpage

L'ordre place le transfert **avant** la bascule de l'encaissement : dans l'ordre inverse, l'argent
encaissé sur les comptes des agents n'aurait aucune sortie enregistrable.

- **Lot 1 — comptes Mobile Money des agents** : modèle, création, validation, écran Supports,
  sous-compte, unicité du numéro. Rend le code des caisses en espèces étanche au nouveau type
  (routage des espèces, versement, rapport d'activité, estimation « espèces chez les agents »).
  Aucun effet sur l'encaissement.
  **Livré le 2026-10-13**, sans migration (`agent_id`, `type`, `operateur_mobile_money` et
  `numero` existaient déjà ; `compta_comptes.numero` accepte 20 caractères) :
  - `CaisseAgentService::creerCompteMobileMoney()` ; sous-compte = numéro du compte de l'opérateur
    suivi de 3 chiffres (561100001…), le compte de l'opérateur étant lu dans `compta_mappings` ;
  - unicité du numéro contrôlée par l'application sous verrou du compte de l'opérateur (aucun index) :
    comparaison sans espaces ni ponctuation, **même portefeuille reconnu avec ou sans indicatif**
    (règle générique, aucun indicatif codé en dur), brouillons et actifs comptés ; un compte de
    l'agence ne peut pas non plus reprendre le numéro du compte d'un agent ;
  - créations simultanées : la transaction prend ses verrous avant toute lecture ordinaire, sinon
    MySQL (`REPEATABLE READ`) lui ferait relire un état périmé après l'attente du verrou ; prouvé
    par `CompteMobileMoneyAgentConcurrenceTest` (processus et connexions MySQL distincts) ;
  - le numéro se corrige tant que le compte est en brouillon, plus après sa validation ;
  - étanchéité : `CompteTresorerie::caissesDediees()` / `isCaisseDediee()` remplacent `dediees()` /
    `isDediee()` partout où il s'agit des espèces d'un agent (`CaisseAgentResolver`, versement,
    approvisionnement, policy, estimation du Financement, onglet Caisse du rapport, diagnostic) ;
  - détail et tableau des effets : [data-dictionary-compta.md](../data-dictionary-compta.md),
    « Compte Mobile Money dédié à un agent ».
- **Lot 2 — transfert agent → agence** (point 3) avec les frais (point 4).
  **Livré le 2026-10-14** :
  - nature `transfert_mobile_money`, `MouvementFondsService::transfererMobileMoneyAgent()`, bouton
    « Transférer à l'agence » de l'écran Supports ;
  - colonnes `mouvements_fonds.frais` et `cle_reference_transfert` (index unique) ; compte de
    charge 631800 et mapping `frais_transfert_fonds` ajoutés au plan par défaut et aux
    organisations existantes ;
  - les frais ont leur propre pièce comptable : un retour de fonds rend le montant, pas les frais ;
  - la référence d'un transfert ne peut pas être celle d'un encaissement Mobile Money déjà
    enregistré. Le contrôle inverse (un encaissement reprenant la référence d'un transfert) n'est
    pas fait : il toucherait le circuit d'encaissement, à voir au lot 3 ;
  - détail : [data-dictionary-compta.md](../data-dictionary-compta.md), « Transfert vers le
    Mobile Money de l'agence ».
- **Préparation en production** : créer et valider les comptes des agents.
- **Lot 3 — bascule de l'encaissement** (point 2) : séparation des listes encaissement /
  décaissement, adaptation du calcul de remise (ADR 0016 point 4 : distinguer, par mode, ce qui est
  encore chez les agents), tests et jeu de données E2E.
- **Lot 4 — transfert direct** (point 5) et rapports : fiche du compte Mobile Money, « Ma
  situation », rapport d'activité.

## Conséquences

- Un agent sans compte Mobile Money actif ne peut plus encaisser en Mobile Money dès le lot 3 :
  la préparation en production est un préalable, pas une option.
- Un agent affecté à deux agences n'encaisse en Mobile Money que dans l'agence de son
  portefeuille ; pour une commande de l'autre agence, il lui faut
  `factures.encaisser_autre_agence` (ADR 0012).
- Le disponible des agences baisse du montant détenu par les agents jusqu'à leur transfert, comme
  pour les espèces.
- Un client qui paie directement sur le numéro de l'agence ne peut plus être enregistré depuis les
  écrans de vente.
- **Non couvert, volontairement** : intégration avec un opérateur ; approvisionnement d'un compte
  Mobile Money d'agent depuis l'agence ; paiement sortant depuis le compte d'un agent ; transfert
  entre opérateurs ; utilisation d'un portefeuille hors de son agence de rattachement.

## Points à préciser au lot concerné

- Lot 3 : refuser un encaissement Mobile Money dont la référence est déjà celle d'un transfert
  (sens inverse du contrôle livré au lot 2).
- Lot 4 : nom de la permission et rôles qui la reçoivent (ADR 0011) ; rattachement des
  encaissements d'une autre agence au transfert direct.
