# Paie des salariés

Décision : [ADR 0037](adr/0037-paiement-des-salaires-par-la-comptabilite.md). État au 28/10/2026, après
le lot 0 (sécurisation du circuit existant) et le lot 2 (référentiel des primes et avantages,
[primes-avantages.md](primes-avantages.md), ADR 0038). L'attribution des primes aux salariés
(lot 3) et la transmission mensuelle figée vers la Comptabilité ne sont pas encore réalisées : la
paie ne lit pas encore le référentiel.

## Responsabilités

- **RH** : salariés, contrats (salaire de base), référentiel des types de primes et avantages.
  Écrans *RH → Employés*, *RH → Contrats* et *Paramètres → RH → Primes et avantages*.
- **Comptabilité** : validation de la période, paiement, écriture comptable. Écran
  *Comptabilité → Paiement salaire* (`/backoffice/comptabilite/salaires`).

L'ancien écran *Paie* rangé sous RH (`/backoffice/paie`) est conservé en l'état jusqu'à son retrait
(prévu au lot 5) ; ses paiements passent par le même service que la Comptabilité.

## Période de paie

- Une période par organisation et par mois (`paie_periodes`), créée à la première consultation du
  mois dans *Paiement salaire* : une ligne par salarié ayant un contrat actif sur le mois, salaire
  de base au prorata des jours du contrat, avances et retenues importées des dépenses validées.
  Statut : « Brouillon ».
- **Valider la période** (bouton de l'écran, permission `rh-paie.validate`) : statut « Validé RH ».
  Les montants affichés deviennent définitifs (plus de variable de paie modifiable) et les salaires
  deviennent payables. Aucun recalcul n'est fait à la validation. Refus : période sans ligne, période
  déjà validée, autre organisation (contrôle explicite, y compris pour le super administrateur).
  Trace : événement `validated` dans l'historique de la période.
- La validation ne peut pas encore être annulée depuis l'écran.

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
au calcul et à chaque (dé)validation d'une dépense).

| Effet du type de dépense (`type_paie`) | Paie |
|---|---|
| `avance`, `retenue`, `absence`, `autre_deduction` | Retenu sur le salaire (avance, retenue…) |
| Aucun (« hors paie ») | Jamais repris : remboursement de frais, indemnité payée en caisse… |
| `prime`, `autre_gain` | Interdit depuis le 26/10/2026 ; un ancien type configuré ainsi n'est plus repris |

- Une prime ou un autre gain vient du référentiel RH ([primes-avantages.md](primes-avantages.md) ;
  attribution aux salariés au lot 3), jamais d'une dépense.
- Réglage : *Dépenses → Types*, champ **Effet sur la paie**, affiché pour un type « Employé »
  seulement (refusé côté serveur pour les autres catégories). Exemple : AVANCE SUR SALAIRE-SALARIE →
  « Retenu sur le salaire : Avance sur salaire » ; REMBOURSEMENT-SALARIE → « Aucun — hors paie ».

## Exports

CSV et PDF de *Paiement salaire* : mêmes filtres que l'écran (mois, année, statut, agences) et même
périmètre d'agences.

## Contrats (source du salaire de base)

- Un seul contrat actif par employé, contrôlé à la création **et** à la modification (activation
  d'un ancien contrat, rattachement à un autre employé). Contrôle sous verrou de l'employé.
- L'employé d'un contrat appartient toujours à l'organisation du contrat (refus 404 sinon).
- Une modification sans statut conserve le statut actuel.

## Limites connues (lots suivants)

- Une dépense validée après la validation de la période modifie encore sa ligne de paie
  (`DepenseObserver::syncPaieLigne`) ; correction prévue avec les régularisations (lot 5).
- Les fiches « salariés » de *Comptabilité → Périodes* existent toujours, non payables.
- Le paiement n'est rattaché à aucun support de trésorerie (mode de paiement seul) ; lot 6.

Tests : `SalaireValidationPeriodeTest`, `SalairePaiementSecuriteTest`, `SalairePayerLigneTest`,
`SalaireEmployeDetailTest`, `PaieTest`, `ContratTest`, `DepenseComptabiliteTest` (dépenses → paie),
`DepenseTypeTest` (effet sur la paie).
