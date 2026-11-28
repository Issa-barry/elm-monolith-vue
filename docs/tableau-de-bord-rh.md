# Tableau de bord RH

> **Statut** : en place (26/11/2026, lot R1) · Menu **Tableau de bord → Stat-RH**
> (`/backoffice/tableau-de-bord/rh`, route `dashboard.rh`).

Vue **strictement consultative** des ressources humaines : elle lit les salariés (`employes`),
leurs contrats (`contrats`) et leurs absences (`absences`) sans rien modifier. Elle ne crée aucune
règle RH : statuts, dates, contrats et absences sont ceux des écrans RH
([rh-employes-identite.md](rh-employes-identite.md), [rh-depart-salarie.md](rh-depart-salarie.md),
[rh-absences.md](rh-absences.md)).

| Élément | Fichier |
|---|---|
| Contrôleur | `app/Http/Controllers/Divers/IndexDashboardRhController.php` |
| Agrégations | `app/Services/Rh/TableauDeBordRhService.php` |
| Page | `resources/js/pages/DashboardRh.vue` |
| Cartes et tableaux | `resources/js/components/dashboard/rh/` |
| Filtres (partagés avec les autres tableaux de bord) | `SelecteurPeriode.vue`, `SelecteurAgence.vue` |
| Tests | `tests/Feature/DashboardRhTest.php`, `tests/Feature/Periodes/RegistreFiltresPeriodeTest.php`, `resources/js/components/dashboard/rh/__tests__/`, `resources/js/pages/__tests__/DashboardRh.spec.ts` |

## Lots

| Lot | Contenu | État |
|---|---|---|
| R1 | Effectifs, contrats, absences, alertes | **En place** |
| R2 | Paie : validé, payé, reste à payer ; périodes **mensuelles** seulement (la quinzaine ne concerne que les commissions) | À faire, après deux ou trois mois de paie validée et payée |
| R3 | Congés, présences, retards | Après le développement de ces modules |

## Accès et périmètre

- **Activation** : le tableau doit être activé pour l'organisation (Paramètres → Tableaux de
  bord, désactivé par défaut), sinon il quitte le menu et sa route est refusée, avant tout calcul
  ([tableaux-de-bord-activation.md](tableaux-de-bord-activation.md), ADR 0071).
- **Module** RH actif.
- **Accès** : au moins une des permissions `rh-employes.read`, `rh-contrats.read`,
  `rh-absences.read`. Aucune permission dédiée.
- **Un bloc par liste nominative** (décision du 26/11/2026) : chaque bloc reprend la permission de
  la liste qui montre les mêmes salariés nom par nom. Sans la permission, le bloc n'est **ni calculé
  ni envoyé** au navigateur.

  | Bloc | Permission |
  |---|---|
  | Effectifs, effectif par agence, dossiers à compléter | `rh-employes.read` |
  | Contrats, fins de CDD | `rh-contrats.read` (lien vers la fiche salarié avec `rh-employes.read` en plus) |
  | Absences, absences par motif | `rh-absences.read` ; détail du taux (jours attendus, salariés pris en compte et exclus) avec `rh-employes.read` en plus, car il révèle l'effectif — sans elle, seul le taux est envoyé |

- **Pas de masquage des petits effectifs** (décision du 26/11/2026) : tous les agrégats sont
  affichés, même pour une agence d'un ou deux salariés ; la permission de la liste nominative
  correspondante protège déjà le détail.
- **Périmètre** : celui des listes RH, sur l'**agence actuelle** du salarié (`employes.site_id`) :
  toutes les agences avec la vision de toutes les agences, sinon les agences de rattachement
  (`SiteScopeService`). Un salarié sans agence n'est compté qu'avec la vision de toutes les agences.
  Les dossiers supprimés ne sont jamais comptés. Toujours limité à l'organisation.
- **Filtre agence** : les agences du filtre des listes RH ; « Toutes les agences » par défaut ; une
  agence hors périmètre est refusée avec un message. Le sélecteur n'est pas affiché avec une seule
  agence.
- **Filtre période** : celui des autres tableaux de bord (`Periode::TABLEAU_DE_BORD`, dates libres
  comprises), « Ce mois » par défaut. Il s'applique aux entrées, aux sorties et aux absences ;
  effectifs et contrats sont toujours ceux du jour.

## Cartes

Quatre cartes par bloc, sur une ligne chacune. Présentation commune à Stat-Stock et Stat-RH (`components/dashboard/shared/LignesIndicateursApollo.vue`,
27/11/2026) : carte `.card` d'Apollo, quatre par ligne, titre de section en tête de chaque ligne ;
icône de l'indicateur dans l'angle du titre, chiffre, détail court sur une ligne, en ambre pour une
alerte à traiter (standard A8, rien n'est bloqué). Ici : la carte « Fins de CDD à traiter » dès qu'une fin est à traiter.

**Effectifs** (à ce jour ; entrées et sorties de la période)

| Carte | Formule |
|---|---|
| Salariés actifs | statut Actif ; détail : présents = actifs + suspendus |
| Salariés suspendus | statut Suspendu (toujours dans l'effectif) |
| Entrées | date d'embauche dans la période ; détail : dossiers présents sans date d'embauche |
| Sorties | statut Sorti et date de sortie effective dans la période |

Un dossier sans date d'embauche n'est jamais compté en entrée.

**Contrats** (à ce jour)

| Carte | Formule |
|---|---|
| CDI en cours / CDD en cours | salariés actifs ou suspendus dont le contrat **actif** est un CDI / un CDD (un seul contrat actif à la fois) |
| Sans contrat en cours | salariés **actifs** sans contrat au statut Actif (aucun contrat, ou contrats terminés ou rompus) |
| Fins de CDD à traiter | CDD actifs dont la fin prévue est dépassée ou atteinte dans les **30 jours** ; détail : dépassées, à venir |

**Absences** (jours écoulés de la période)

| Carte | Formule |
|---|---|
| Jours d'absence | jours calendaires des absences enregistrées, dates incluses, comptés sur la période **jusqu'à aujourd'hui** et dans la période d'emploi connue du salarié ; détail : nombre d'absences, de salariés absents |
| Taux d'absentéisme | jours d'absence ÷ jours de présence attendus × 100, une décimale, sur les **seuls salariés dont la période d'emploi est connue** ; détail : salariés pris en compte et exclus ; « — » pour « Toutes les périodes », une période pas encore commencée ou sans salarié pris en compte (jamais « 0 % » par défaut) |
| Absences injustifiées | jours au **seul** motif « Absence injustifiée » (code figé `absence_injustifiee`) ; détail : jours et absences |
| Absents aujourd'hui | salariés dont une absence enregistrée couvre aujourd'hui ; détail : absences à venir sur la période |

## Règles de calcul (décisions du 26/11/2026)

- **Absences** : enregistrées seulement ; les absences annulées ne comptent jamais. Une absence
  compte dès qu'elle touche la période, pour ses seuls jours compris dans la période (une absence
  du 28 octobre au 3 novembre compte 3 jours en novembre), comme la liste des absences.
- **Jours calendaires** partout, comme la durée d'une absence : week-ends et jours fériés compris,
  ni jours ouvrés ni ouvrables.
- **Jours écoulés** : la période est arrêtée à aujourd'hui. Avec « Ce mois », une absence prévue la
  semaine prochaine n'est pas encore comptée (elle figure dans « absences à venir »).
- **Période d'emploi** : de la date d'embauche à la date de sortie (incluses). Les jours d'absence
  sont toujours limités aux bornes connues : une absence saisie avant une date d'embauche corrigée
  ensuite, ou après la sortie, ne compte que pour ses jours compris dans la période d'emploi.
- **Taux d'absentéisme** (décision validée le 26/11/2026, revue de R1) : seuls les salariés dont
  la période d'emploi est connue sont **retenus** :
  - **exclus** du numérateur comme du dénominateur : les salariés sans date d'embauche et les
    anciennes fiches « Sorti » sans date de sortie. Ils sont comptés dans le détail du taux et
    signalés dans « Dossiers à compléter » ;
  - un salarié sorti avant la période n'est ni retenu ni exclu ;
  - un salarié **suspendu** reste retenu, faute de dates de suspension ;
  - jours de présence attendus : pour chaque salarié retenu, jours écoulés de la période compris
    dans sa période d'emploi ;
  - sans salarié retenu (aucune date d'embauche renseignée, comme en production au 26/11/2026),
    le taux affiche « — », jamais « 0 % ». Un « 0,0 % » n'apparaît que pour des salariés retenus
    sans aucune absence.
  - La présomption « en poste sur toute la période » de la première version a été abandonnée :
    depuis le 31/10/2026, la date d'embauche n'est renseignée qu'au premier contrat, et un nouveau
    salarié sans contrat aurait été compté présent avant son arrivée (taux sous-estimé).
- **Absence injustifiée** : uniquement le motif « Absence injustifiée », jamais déduite d'une
  absence sans justificatif.
- **Fin de CDD** (seuil validé le 26/11/2026, fixe, sans paramétrage) : la fin prévue ne fait
  jamais sortir le salarié ([rh-depart-salarie.md](rh-depart-salarie.md)) ; c'est un rappel à
  traiter (renouvellement, nouveau contrat ou départ). Seuls les CDD au statut **Actif** sont lus :

  | Fin prévue | Affichage |
  |---|---|
  | Au-delà de 30 jours | aucune alerte |
  | Dans 30 à 1 jours | « Se termine dans N jours » (ambre, `expire_bientot`) |
  | Aujourd'hui | « Se termine aujourd'hui » (ambre, `expire_bientot`) |
  | Passée | « Fin dépassée » (orange, `echeance_depassee`) |

  Jamais en rouge : rien n'est bloqué (standard A8). Un CDD terminé ou rompu n'est jamais signalé ;
  un CDD renouvelé ne l'est plus, car la règle « un seul contrat actif » impose de terminer l'ancien
  avant d'activer le nouveau, et une prolongation de la date de fin fait sortir l'alerte du seuil.

## Tableaux

- **Effectif par agence** (`rh-employes.read`) : actifs et suspendus à ce jour, entrées et sorties
  de la période, du plus gros au plus petit effectif ; ligne « Sans agence » pour les salariés sans
  agence. Une agence sans présent, sans entrée ni sortie sur la période n'apparaît pas.
- **Dossiers à compléter** (`rh-employes.read`) : salariés présents sans date d'embauche, sans
  fonction, sans service, sans agence ; anciennes fiches « Sorti » sans date de sortie. Ces données
  se complètent dans la fiche salarié ou par l'import RH
  ([import-dossiers-rh.md](import-dossiers-rh.md)) ; sans date d'embauche, le salarié n'est compté
  ni en entrée ni dans le taux d'absentéisme.
- **Absences par motif** (`rh-absences.read`) : absences, jours et part des jours de chaque motif
  présent sur la période, du plus grand nombre de jours au plus petit. Le motif et le commentaire
  pouvant être des données de santé, aucun commentaire n'est affiché et aucun nom n'est lié aux
  motifs.
- **Fins de CDD** (`rh-contrats.read`) : salarié, matricule, agence, fin prévue, les plus urgentes
  d'abord ; lien vers la fiche salarié avec `rh-employes.read`.

## Performances

Les salariés du périmètre sont lus une fois (quelques colonnes), puis effectifs, entrées, sorties,
dossiers et jours attendus sont calculés en mémoire ; s'y ajoutent les contrats actifs et trois
requêtes d'absences. Au 26/11/2026, la production compte 39 salariés : le coût est négligeable.

## Limites connues

- Effectifs et contrats sont ceux du jour : pas d'effectif à une date passée (l'historique des
  affectations n'est pas lu).
- **Agence actuelle** : toutes les statistiques, absences passées comprises, sont rattachées à
  l'agence actuelle du salarié (`employes.site_id`), comme la liste des absences. Un salarié muté
  pendant la période est compté entièrement dans sa nouvelle agence et disparaît de l'ancienne ;
  les totaux « Toutes les agences » ne changent pas, et le taux reste cohérent (numérateur et
  dénominateur suivent la même agence). La page l'indique sous les filtres. L'historique
  `employe_affectations` n'est pas lu : il est daté au moment de la saisie (sans date d'effet) et ne
  couvre pas les salariés antérieurs au 21/08/2026. Une attribution historique demanderait une date
  d'effet sur les affectations (migration, hors R1).
- Données de production au 26/11/2026 : aucune date d'embauche ni aucun service renseignés, aucun
  contrat ni aucune absence. Le tableau de bord se remplira avec la saisie (import RH, contrats,
  module Absences).
