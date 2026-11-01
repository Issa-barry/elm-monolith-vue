# Paie des salariés

Décision : [ADR 0037](adr/0037-paiement-des-salaires-par-la-comptabilite.md). État au 29/10/2026, après
le lot 0 (sécurisation du circuit existant), le lot 2 (référentiel des primes et avantages,
ADR 0038), le lot 3 (affectations datées aux salariés, ADR 0039 — [primes-avantages.md](primes-avantages.md))
le lot 4 (transmission mensuelle figée et versionnée, ADR 0040 —
[transmission-paie.md](transmission-paie.md)) et le lot 5 (reprise de la transmission par la
Comptabilité, [ADR 0041](adr/0041-reprise-transmission-paie-comptabilite.md)). **La paie de la
Comptabilité ne lit plus les contrats** : ses lignes viennent uniquement de la version transmise par
les RH, reprise sans recalcul.

## Responsabilités

- **RH** : salariés, contrats (salaire de base), référentiel des types de primes et avantages,
  affectations aux salariés, transmission du mois. Écrans *RH → Employés*, *RH → Contrats*,
  *RH → Primes des salariés*, *RH → Transmission de paie* et *Paramètres → RH → Primes et avantages*.
- **Comptabilité** : reprise de la transmission, retenues (dépenses), validation de la période,
  paiement, écriture comptable. Écran
  *Comptabilité → Paiement salaire* (`/backoffice/comptabilite/salaires`). Une période validée ici
  fige aussi le mois côté RH : plus d'affectation ni de nouvelle version de transmission pour ce
  mois.

L'ancien écran *Paie* rangé sous RH (`/backoffice/paie`) est conservé (consultation et paiement,
par le même service que la Comptabilité) ; ses variables de paie sont limitées aux retenues depuis
le lot 5. Son retrait (ADR 0037) reste à décider.

## Flux du mois

1. **RH** prépare, valide (fige) et transmet la version du mois (*RH → Transmission de paie*).
2. **Comptabilité → Paiement salaire**, mois choisi : un bandeau indique l'état de la transmission
   (aucune, version N à reprendre, version N reprise, version plus récente à reprendre).
3. **Reprendre la transmission RH** : les lignes de paie sont créées depuis la version validée par
   les RH (disponible dès sa validation, ADR 0047). Avant, l'écran montre l'étape de la paie du mois
   (aucune paie, brouillon provisoire, à recalculer, prête à valider côté RH, validée par les RH,
   reprise, validée par la Comptabilité, payée, clôturée) et le brouillon RH en lecture seule, jamais
   repris ni payé.
4. **Valider la période** : les salaires deviennent payables ; le mois est figé côté RH.
5. **Payer** (inchangé).

## Période de paie

- Une période par organisation et par mois (`paie_periodes`), créée **vide** (« Brouillon ») à la
  première consultation du mois dans *Paiement salaire*. Aucune ligne n'est déduite des contrats.
- **PAIE-001 — Reprise** (bouton *Reprendre la transmission RH*, permission `rh-paie.update`,
  réservée à qui agit sur toutes les agences : la version couvre toute l'organisation). Une ligne
  par salarié de la version :
  - **salaire de base** = somme des lignes « contrat » (prorata déjà calculé par les RH) ;
  - **primes** = somme des autres éléments versés (primes, indemnités, allocations) ;
  - **avantages en nature** = somme des éléments valorisés, colonne à part, **jamais dans le brut
    ni le net** ;
  - **brut** = salaire de base + primes ; **net** = brut − retenues de la Comptabilité (dépenses
    validées, ci-dessous) ; jours = jours des contrats sur le mois.
  - Aucun recalcul : une donnée RH modifiée après la transmission n'a aucun effet. Le détail par
    élément (du / au, jours, calcul, montant) se consulte dans *Détail transmis par les RH*, lu dans
    la version figée. Statut de la période : « Calculé ».
- **PAIE-002 — Version reprise** : seulement la version transmise en vigueur du mois, celle
  affichée à l'écran. Refus explicites : brouillon ou version validée non transmise, version
  remplacée, version d'un autre mois ou d'une autre organisation, version déjà reprise (double
  clic, nouvelle tentative), contenu qui ne correspond plus à son empreinte, paie du mois déjà
  validée.
- **PAIE-003 — Une seule fois** : une version n'est reprise qu'une fois et n'alimente qu'une
  période. Reprise en une transaction (verrou sur l'organisation, le même que la transmission RH,
  puis sur la période) : en cas d'erreur, aucune ligne, aucune prise en charge, aucun journal. Après
  création des lignes, le nombre de salariés, le total versé et le total valorisé doivent être
  ceux de la version, sinon tout est annulé.
- **PAIE-004 — Nouvelle version avant validation** : la paie reste sur la version reprise, le
  bandeau demande de reprendre la nouvelle (validation refusée d'ici là). La reprise remplace les
  lignes ; la reprise de la version précédente reste tracée.
- **Valider la période** (bouton de l'écran, permission `rh-paie.validate`) : statut « Validé RH ».
  Les montants affichés deviennent définitifs (plus de variable de paie modifiable) et les salaires
  deviennent payables. Aucun recalcul n'est fait à la validation. Refus : période sans reprise ou
  dont la version a été remplacée entre-temps, période sans ligne, période déjà validée, autre
  organisation (contrôle explicite, y compris pour le super administrateur). Trace : événement
  `validated` (avec la version) dans l'historique de la période.
- La validation ne peut pas encore être annulée depuis l'écran.
- Journal de la reprise (module `salaires`) : version, nombre de salariés, totaux versé /
  avantages / net, version remplacée — pas de montant par salarié.

## Paiement

- Permission : **`comptabilite.payer`**, la même pour le bouton et pour le serveur, sur les trois
  chemins (ligne de paie, paiement global d'un salarié, ancienne route Paie RH). `rh-paie.pay` ne
  donne plus ce droit.
- Seuls les mois validés sont payables. La fiche d'un salarié propose le reste des seuls mois
  validés.
- **Agences** : consulter et exporter = agences consultables (vision 360° comprise, ADR 0025) ;
  payer = agences de rattachement (administrateur et superviseur : toutes). Un salarié sans agence
  n'est payable que par ces derniers.
- **Sécurité** (`App\Services\Comptabilite\PaiementSalaireService`) : transaction, ligne de paie
  verrouillée, reste à payer relu sous verrou à partir des paiements enregistrés. Deux paiements
  simultanés ne peuvent pas dépasser le net. Si l'écriture comptable échoue, rien n'est enregistré
  (y compris pour un paiement global réparti sur plusieurs mois).
- Paiement global d'un salarié : répartition du plus ancien mois au plus récent (FIFO), refusée si
  le montant touche un mois non validé.

## Dépenses et paie

Seule une dépense **validée**, dont le bénéficiaire est un salarié et dont le type porte une
**retenue**, entre dans la paie du mois de la dépense (`PaieCalculService::importerDepenses`, appelé
à la reprise et à chaque (dé)validation d'une dépense). Ce sont les seules retenues de la
Comptabilité ; elles s'appliquent au net repris de la transmission RH.

- **PAIE-005 — Non-rétroactivité** : une période validée (« Validé RH », payée, clôturée) n'est
  plus jamais modifiée par une dépense. Une dépense validée **après** la validation de son mois est
  reprise en **régularisation** dans le premier mois suivant encore ouvert, à la reprise de ce mois
  (libellé « … (régularisation MM/AAAA) »), une seule fois.
- Une dépense **dévalidée** après la validation de sa paie reste retenue dans le mois validé : pas
  de régularisation automatique (point ouvert, ADR 0041).

| Effet du type de dépense (`type_paie`) | Paie |
|---|---|
| `avance`, `retenue`, `absence`, `autre_deduction` | Retenu sur le salaire (avance, retenue…) |
| Aucun (« hors paie ») | Jamais repris : remboursement de frais, indemnité payée en caisse… |
| `prime`, `autre_gain` | Interdit depuis le 26/10/2026 ; un ancien type configuré ainsi n'est plus repris |

- Une prime ou un autre gain vient du référentiel RH et des affectations aux salariés
  ([primes-avantages.md](primes-avantages.md)), transmis chaque mois ([transmission-paie.md](transmission-paie.md)),
  jamais d'une dépense.
- Réglage : *Dépenses → Types*, champ **Effet sur la paie**, affiché pour un type « Employé »
  seulement (refusé côté serveur pour les autres catégories). Exemple : AVANCE SUR SALAIRE-SALARIE →
  « Retenu sur le salaire : Avance sur salaire » ; REMBOURSEMENT-SALARIE → « Aucun — hors paie ».

## Exports

CSV et PDF de *Paiement salaire* : mêmes filtres que l'écran (mois, année, statut, agences) et même
périmètre d'agences. Le CSV ajoute la colonne des avantages en nature (hors net).

## Prévision de trésorerie (financement des agences)

Besoin « salaires » d'un mois : reste à payer des lignes de paie reprises ; tant que rien n'est
repris, **estimation des RH** (version transmise, sinon calcul du moment), montants versés avant
retenues. La prévision n'écrit rien (elle ne crée plus de période ni de ligne).

## Contrats (source du salaire de base, via la transmission RH)

- Un seul contrat actif par employé, contrôlé à la création **et** à la modification (activation
  d'un ancien contrat, rattachement à un autre employé). Contrôle sous verrou de l'employé.
- L'employé d'un contrat appartient toujours à l'organisation du contrat (refus 404 sinon).
- Une modification sans statut conserve le statut actuel.

## Limites connues (lots suivants)

- Les fiches « salariés » de *Comptabilité → Périodes* existent toujours, non payables.
- Le paiement n'est rattaché à aucun support de trésorerie (mode de paiement seul) ; lot 6.
- Pas d'écriture d'engagement (charge / dette) à la validation ; lot 6.

Tests : `ReprisePaieTest` (reprise, lot 5), `SalaireValidationPeriodeTest`,
`SalairePaiementSecuriteTest`, `SalairePayerLigneTest`, `SalaireEmployeDetailTest`, `PaieTest`,
`ContratTest`, `DepenseComptabiliteTest` (dépenses → paie), `DepenseTypeTest` (effet sur la paie),
`TypeRemunerationTest`, `AffectationRemunerationTest`, `TransmissionPaieTest` (référentiel,
affectations, transmission).
