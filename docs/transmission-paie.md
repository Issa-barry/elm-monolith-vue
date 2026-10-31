# Transmission de paie — RH → Comptabilité, figée et versionnée

Décisions : [ADR 0040](adr/0040-transmission-paie-figee-versionnee.md), révisé le 31/10/2026 par
[ADR 0047](adr/0047-validation-rh-met-a-disposition-comptabilite.md) (plus de transmission manuelle).
Lot 4 de la conception RH ↔
Comptabilité ([paie.md](paie.md)), livré le 29/10/2026. Écran : *RH → Transmission de paie*
(`/backoffice/transmissions-paie`).

Chaque mois, RH prépare les éléments de rémunération des salariés, les contrôle et les **valide** :
la version est alors **figée** et **aussitôt disponible pour la Comptabilité** (ADR 0047 — plus de
bouton « Transmettre »). Le brouillon est recalculé automatiquement à l'ouverture et avant la
validation ; la Comptabilité le voit en lecture seule, comme **provisoire**, sans pouvoir le reprendre
ni le payer. La Comptabilité ne paie que la version validée, jamais les tables RH : elle la
**reprend** dans *Comptabilité →
Paiement salaire* (lot 5, [ADR 0041](adr/0041-reprise-transmission-paie-comptabilite.md),
[paie.md](paie.md)).

## Trois sortes de montants

| Notion | Où | Sens |
|---|---|---|
| **Montant validé (RH)** | Colonne *Montant validé (RH)* | Ce que RH a validé : salaire de base du contrat, montant attribué à l'affectation, ou montant saisi et validé du mois |
| **Montant du mois (calculé)** | Colonne *Montant du mois* | Ce qui est dû pour la partie du mois : prorata, pourcentage du salaire de base (colonne *Calcul*) |
| **Montant figé** | Version validée ou transmise | Les montants du mois copiés dans la version au moment de sa validation. Ils ne changent plus, même si les données RH changent ensuite |

Un **brouillon** montre des montants calculés, recalculables à tout moment. Une version **validée**
ne montre que des montants figés ; son contenu est protégé par une empreinte (SHA-256) vérifiée à
chaque affichage et avant la transmission.

## Contenu d'une version

Une ligne par salarié, par élément et par partie du mois :
- **Salaire de base** : depuis chaque contrat en cours sur le mois (actif, ou terminé / rompu en
  cours de mois), au prorata des jours du contrat dans la période d'emploi. Ce n'est pas une prime.
- **Primes, indemnités, allocations, avantages en nature** : depuis les affectations **validées**
  (ADR 0039).

Chaque ligne garde : salarié (matricule, nom, agence, fonction au moment du calcul), contrat,
affectation, montant mensuel, type (code, libellé, catégorie, mode, périodicité, nature,
proratisable), versement (*versé* ou *valorisé*), du / au, jours, montant validé, base, taux,
montant du mois et la formule. Ce sont des copies : la version ne dépend d'aucune donnée vivante.

## Règles de calcul

Jours calendaires, comme le calcul de paie existant (`PaieCalculService`). Un **jour de droit** est
un jour où l'affectation, la période d'emploi (embauche, sortie) et la période d'application du
type se recouvrent, avant la date de désactivation du type.

- **TRANS-001** — Salaire de base : `salaire × jours du contrat / jours du mois` (entier si le
  contrat couvre tout le mois).
- **TRANS-002** — Montant fixe **proratisable** : `montant × jours de droit / jours du mois` ;
  **non proratisable** : montant entier dès un jour de droit. Un changement de montant en cours de
  mois donne deux parties (proratisable) ; non proratisable, le nouveau montant vaut pour le mois
  entier.
- **TRANS-003** — Pourcentage : `taux × salaire de base de la même partie du mois` (proratisable),
  ou `taux × salaire mensuel du contrat en vigueur au dernier jour de droit` (non proratisable).
- **TRANS-004** — Saisi chaque mois : le montant (ou le taux) **validé** du mois, sans prorata.
- **TRANS-005** — Ponctuel : montant entier, une seule fois, dans son mois.
- **TRANS-006** — Avantage en nature : calculé de même, **valorisé** : totalisé à part, hors total
  versé (hors net à payer).
- **TRANS-007** — Double garantie : un type désactivé ne produit plus rien à partir de sa date de
  désactivation, même si une affectation était restée ouverte.
- Arrondi à 2 décimales.

## Anomalies

| Anomalie | Niveau |
|---|---|
| Affectation à valider qui touche le mois | **Bloquante** |
| Fin d'effet à valider avant la fin du mois | **Bloquante** |
| Montant mensuel à valider | **Bloquante** |
| Primes ou avantages sur le mois, mais aucun contrat en cours | **Bloquante** |
| Contrat sans salaire de base | **Bloquante** |
| Deux contrats qui se chevauchent sur le mois | **Bloquante** |
| Pourcentage du salaire de base sans contrat sur la période | **Bloquante** |
| Élément saisi chaque mois sans montant pour le mois (rien n'est transmis pour lui) | Avertissement |
| Salarié hors de sa période d'emploi sur tout le mois | Avertissement |
| Salarié sans agence (charge non imputable) | Avertissement |

Un brouillon peut contenir des anomalies : elles s'affichent avec le salarié concerné. La
validation est **refusée** tant qu'il reste une anomalie bloquante.

## Cycle d'une version

- **TRANS-010** — *Préparer un mois* crée la **version 1** en brouillon. Un seul brouillon par mois
  à la fois. Préparation sérialisée par un verrou sur l'organisation ; deux préparations
  simultanées n'en créent qu'une (index unique organisation + mois + version).
- **TRANS-011** — *Recalculer* refait le brouillon à partir des données RH du moment ; il est
  aussi **recalculé automatiquement** quand un utilisateur qui peut préparer la paie ouvre l'écran,
  si les données RH ont changé (ADR 0047). Un lecteur sans ce droit voit « à recalculer ».
  *Abandonner* le clôt (son numéro reste dans l'historique, le suivant prend le numéro d'après).
- **TRANS-012** — *Valider et figer* : refusé s'il reste une anomalie bloquante. Un dernier recalcul
  a lieu avant : si les montants ont changé, le brouillon est mis à jour et la validation refusée
  (« vérifiez les nouveaux montants, puis validez ») — on ne fige jamais autre chose que ce qui a été
  consulté. La version validée prend le statut « Validée et figée par les RH » (`transmise`) : elle est
  **disponible pour la Comptabilité** sans autre action et remplace la version disponible jusque-là.
  Valider ne déclenche ni paiement ni écriture comptable. La version devient **figée** : ses lignes et ses
  totaux ne se modifient plus ni ne se suppriment (refus dans les modèles, pas seulement à
  l'écran). Le mois devient figé pour les affectations (AFF-009).
- **TRANS-013** — *Mettre à disposition de la Comptabilité* : ne subsiste que pour une version
  validée **avant** l'ADR 0047 (statut `validee`) ; une seule fois, si son contenu correspond à son
  empreinte. La Comptabilité refuse de lire des lignes dont le contenu ne correspond plus à
  l'empreinte.
- **TRANS-013 bis** — **Annuler la validation** (ADR 0047) : droit de validation, toutes agences,
  même organisation ; motif obligatoire. Seulement pour la version validée en vigueur, **pas encore
  reprise par la Comptabilité**, et tant que la Comptabilité n'a pas validé, payé ou clôturé le mois.
  La version est conservée telle quelle (statut « Validation annulée », lignes et empreinte
  intactes) et ne fige plus le mois côté RH ; la version qu'elle avait remplacée redevient celle de la
  Comptabilité ; un nouveau brouillon recalculé est ouvert avec le motif (« Validation de la version
  N annulée : … ») et devra être validé à nouveau. Journalisé (auteur, date, motif). Après reprise,
  paiement ou clôture : nouvelle version ou régularisation sur le mois suivant (TRANS-014, TRANS-015).
- **TRANS-014** — **Correction** : *Nouvelle version* (motif obligatoire) ouvre un brouillon
  recalculé, numéroté à la suite. La version précédente reste consultable telle quelle et reste
  celle lue par la Comptabilité tant que la nouvelle n'est pas transmise. Une version validée mais
  pas encore transmise est remplacée dès la validation de la suivante.
- **TRANS-015** — Une fois la paie du mois **validée par la Comptabilité** (période de paie
  « Validé RH », payée ou clôturée), le mois ne reçoit plus de nouvelle version (préparation,
  recalcul, validation et transmission refusés). Une correction se saisit alors côté RH sur le mois
  suivant (par exemple une prime ponctuelle de rappel) ; les dépenses validées après coup sont
  régularisées automatiquement par la Comptabilité (PAIE-005).
- **TRANS-016** — *Écarts avec la version précédente* (salarié, élément, avant, après, écart) et,
  sur la dernière version figée du mois, *changements dans les données RH depuis cette version*
  (ce qu'une nouvelle version contiendrait).
- **TRANS-017** — **Reprise par la Comptabilité** (lot 5, PAIE-001 à 004) :
  `SourceDonneesPaie::prendreEnCharge()` marque la version transmise comme reprise
  (`prise_en_charge_reference` = `paie_periode:<id>`), une seule fois : un second appel, même
  simultané, est refusé (vérifié sous MySQL). Une version remplacée garde la trace de sa reprise.
  La lecture des lignes par la Comptabilité vérifie l'empreinte.
- **TRANS-018** — Journal (module `rh_transmissions_paie`) : préparation, recalcul (totaux avant /
  après), abandon, validation (empreinte), transmission, remplacement, reprise par la Comptabilité.

## Droits et cloisonnement

| Action | Permission | Par défaut |
|---|---|---|
| Consulter les versions et leurs montants | `rh-transmissions-paie.read` | super_admin, admin_entreprise, comptable |
| Préparer, recalculer, abandonner un brouillon, ouvrir une nouvelle version | `rh-transmissions-paie.preparer` | super_admin, admin_entreprise |
| Valider (figer) | **`rh-transmissions-paie.valider`** | **super_admin seul** |
| Annuler la validation (ADR 0047) | **`rh-transmissions-paie.valider`** | **super_admin seul** |
| Mettre à disposition une version validée avant l'ADR 0047 | `rh-transmissions-paie.transmettre` (plus utilisée sinon, conservée pour les rôles existants) | super_admin seul |

- Les quatre droits sont séparés et délégables dans *Rôles* ; le serveur les vérifie
  (`TransmissionPaieService`), même par requête directe.
- Une version couvre **tous les salariés de l'organisation** : préparer, valider et transmettre
  exigent d'agir sur toutes les agences (administrateur, superviseur).
- Consultation : montants et anomalies limités aux salariés des agences consultables ; les totaux
  de l'organisation ne s'affichent qu'à qui voit toutes les agences.
- Organisation vérifiée à chaque action, super administrateur compris.

## Frontière avec la Comptabilité (lot 5)

- `App\Contracts\Rh\SourceDonneesPaie` (lié à `DonneesPaieRhService`) : `transmissionCourante()`,
  `lignes()` (refus si l'empreinte ne correspond plus), `prendreEnCharge()`, et
  `estimationVersements()` pour la prévision de trésorerie tant que rien n'est repris.
- La paie de la Comptabilité ne lit plus les contrats : `App\Services\Comptabilite\ReprisePaieService`
  copie la version transmise dans la période de paie (docs/paie.md, PAIE-001 à 005).

## Fichiers clés

- `app/Services/Rh/Paie/CalculElementsPaieService.php` (calcul, empreinte),
  `app/Services/Rh/Paie/TransmissionPaieService.php` (seul point d'écriture),
  `app/Services/Rh/Paie/CalendrierPaieService.php` (mois figés),
  `app/Services/Rh/Paie/DonneesPaieRhService.php`, `app/Contracts/Rh/SourceDonneesPaie.php`.
- `app/Models/TransmissionPaie.php`, `app/Models/TransmissionPaieLigne.php`,
  `app/Enums/StatutTransmissionPaie.php`, `app/Policies/TransmissionPaiePolicy.php`,
  `app/Http/Controllers/Rh/TransmissionPaieController.php`.
- `resources/js/pages/TransmissionsPaie/Index.vue`, `resources/js/pages/TransmissionsPaie/Show.vue`.
- Tests : `tests/Feature/TransmissionPaieTest.php`.
