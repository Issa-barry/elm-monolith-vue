# Absences des salariés

> **Statut** : en place (première version) · **Dernière vérification** : 23/11/2026, d'après
> `app/Services/Rh/AbsenceService.php` et `tests/Feature/AbsenceTest.php`
>
> Décision : [ADR 0070](adr/0070-absences-des-salaries.md).

RH et les responsables d'agence enregistrent, consultent, modifient et annulent les absences des
salariés. Menu **RH → Absences** (liste) et bloc **Absences** de la fiche salarié.

**Hors périmètre de cette version** : congés payés, droits acquis, jours pris, solde, circuit de
demande et de validation, incidence sur la paie. Une absence ne modifie ni la paie, ni la
transmission de paie, ni les contrats, et ne déclenche aucune retenue.

## 1. Saisie

Champs, dans cet ordre :

| Champ | Règle |
|---|---|
| Salarié | Obligatoire. Liste avec recherche (nom, prénom, matricule) des salariés des agences de rattachement de l'utilisateur (toutes pour un administrateur ou un superviseur). Depuis la fiche salarié, il est imposé. |
| Motif | Obligatoire. Motif actif du référentiel de l'organisation (§ 4). Le terme est « Motif » partout, jamais « Type d'absence ». |
| Date de début | Obligatoire (calendrier). |
| Date de fin | Obligatoire (calendrier), postérieure ou égale à la date de début. Pré-remplie avec la date de début (absence d'un jour). |
| Commentaire | Facultatif, texte libre (2 000 caractères au plus), distinct du motif. |
| Justificatif | Facultatif. PDF ou image (JPG, PNG, WEBP), 5 Mo au plus. |

Pas de circuit de validation : une absence saisie par un utilisateur habilité est enregistrée
directement (statut **Enregistrée**).

## 2. Durée

Jours **calendaires**, dates de début et de fin **incluses** : du 10 au 12 novembre = 3 jours ; un
seul jour = 1 jour. Week-ends et jours fériés comptent. Pas de jours ouvrés ni ouvrables.

La durée n'est jamais saisie ni stockée : le formulaire en affiche un aperçu, le serveur la
recalcule à partir des dates (`Absence::dureeEnJours()`). Une durée envoyée par le navigateur est
ignorée.

## 3. Contrôles (serveur)

Tous dans `AbsenceService`, quel que soit l'écran :

- **Salarié** existant dans l'organisation, dans le périmètre d'écriture de l'utilisateur
  (`PerimetreEmploye::peutModifier`).
- **En poste sur la période** : pas avant la date d'embauche, pas après la date de sortie. Un
  salarié sorti reste saisissable pour une absence passée pendant son emploi. Le statut
  « Suspendu », sans dates, ne bloque pas.
- **Dates** : fin ≥ début.
- **Chevauchement** : un salarié n'a qu'une absence enregistrée par jour, quel que soit le motif.
  Une nouvelle absence (ou une modification) qui partage au moins un jour avec une autre absence
  enregistrée est refusée avec un message explicite (motif et dates de l'absence existante) et un
  bouton **Voir l'absence existante**. Des absences contiguës (12 puis 13) sont acceptées.
- **Doublon** (même motif, mêmes dates) : refusé avec un message dédié.
- Une absence **annulée** ne bloque plus rien.
- Contrôle fait sous verrou du salarié : deux saisies simultanées ne passent pas toutes les deux.

## 4. Motifs

Référentiel par organisation, **Paramètres → RH → Motifs d'absence** (permission
`rh-referentiels.gerer`, comme les fonctions et services RH). Sept motifs créés pour chaque
organisation : Maladie, Rendez-vous médical, Absence autorisée, Absence injustifiée, Motif familial,
Motif personnel, Autre.

- Créer, renommer (libellé unique, sans tenir compte de la casse), désactiver, réactiver.
- Jamais de suppression : un motif désactivé n'est plus proposé à la saisie ; les absences qui le
  portent le gardent, et il reste accepté à la modification de ces absences.

## 5. Modification, annulation, historique

- **Modifier** : motif, dates, commentaire, justificatif (remplacer ou retirer). Le salarié ne se
  modifie pas : une absence saisie sur le mauvais salarié s'annule puis se ressaisit. Mêmes
  contrôles qu'à la saisie (l'absence ne se chevauche pas elle-même).
- **Annuler** : raison facultative. L'absence n'est **jamais supprimée** : elle passe au statut
  **Annulée**, avec l'auteur, la date et la raison. Une absence annulée n'est plus modifiable.
- **Historique** : création, modifications (valeurs avant/après) et annulation sont journalisées
  (`audit_logs`, module `rh_absences`, bouton **Historique** du détail). Auteur et date de saisie,
  dernier modificateur et annulation sont aussi conservés sur l'absence.
- **Confidentialité** : ce journal n'est lisible que dans le module RH. L'**Historique des
  actions** de la Comptabilité ne montre jamais les absences (ni liste, ni filtre « module », ni
  historique d'une entité) : le motif (ex. « Maladie ») et le commentaire peuvent être des données
  de santé. Quand les absences auront une incidence sur la paie, la Comptabilité en verra
  l'incidence financière dans les éléments de paie, sans le motif ni le commentaire.

## 6. Liste (RH → Absences)

Standard A2 : en-tête `ListPageActions`, bouton **Filtres** (`DataFilters` en `trigger-only`),
bouton **Nouvelle absence** ; pagination serveur de 30 lignes.

Colonnes : salarié, matricule, agence, motif (trombone si justificatif), début, fin, durée, statut,
commentaire (tronqué, complet dans le détail), auteur et date de saisie, actions (voir, modifier,
annuler — affichées seulement si le serveur les accepte). Sur mobile, une carte par absence.

Filtres : **Agence** (agence actuelle du salarié, `site_ids[]`) → **Statut** → **Salarié** (nom,
prénom ou matricule) → **Période** → **Motif** → **Auteur de la saisie**.

- Statut : par défaut toutes les absences **hors annulées** ; *En cours*, *À venir*, *Terminées*
  (déduits des dates par rapport au jour) ou *Annulées*.
- Période : moteur commun ([periodes.md](periodes.md)) ; une absence figure dans la période dès
  qu'elle la **touche** (une absence du 18 au 25 apparaît dans « Cette semaine » du 16 au 22).

Le détail (clic sur une ligne) affiche le commentaire complet, le justificatif, la traçabilité et
les actions.

## 7. Fiche salarié

Bloc **Absences** de la colonne principale : absences du salarié, annulées comprises (atténuées),
les plus récentes d'abord, cinq affichées puis « Afficher … de plus ». Motif, dates, durée, statut,
commentaire ; clic → détail et justificatif. Bouton **Nouvelle absence** (salarié imposé) si
l'utilisateur peut lui saisir une absence. Bloc absent sans `rh-absences.read`.

## 8. Permissions et périmètre

| Permission | Effet |
|---|---|
| `rh-absences.read` | Liste, fiche, détail, historique, justificatif |
| `rh-absences.create` | Saisir une absence |
| `rh-absences.update` | Modifier une absence enregistrée |
| `rh-absences.annuler` | Annuler une absence |

Pas de `rh-absences.delete` : une absence ne se supprime pas. Les quatre permissions sont réglables
dans **/backoffice/roles → RH & Paie → Absences**. Valeurs de départ (migration
`2026_11_23_100300`) : super_admin, admin_entreprise (RH) et manager (responsable d'agence).

Périmètre d'agence, commun à la fiche salarié, aux contrats et aux primes
([ADR 0025](adr/0025-vision-360-consultation-de-toutes-les-agences.md), [ADR 0034](adr/0034-superviseur-agit-sur-toutes-les-agences.md)) :

- **Consulter** : salariés de ses agences de rattachement, ou de toutes avec la vision de toutes
  les agences.
- **Saisir, modifier, annuler** : salariés de ses agences de rattachement ; toutes pour un
  administrateur ou un superviseur. La vision 360° n'ouvre pas l'écriture.
- L'agence prise en compte est l'agence **actuelle** du salarié (`employes.site_id`).

Tout est revérifié par le serveur ; l'écran n'affiche que les actions acceptées.

## 9. Justificatif

Disque privé `absences_rh` (`storage/app/private/absences-rh/{organisation}/{salarié}/{absence}/`),
nom de fichier aléatoire, type détecté sur le contenu. Jamais d'URL publique : le fichier est servi
par `GET /backoffice/absences/{absence}/justificatif` après contrôle de `rh-absences.read`, de
l'organisation et de l'agence. Un justificatif remplacé ou retiré est supprimé du disque une fois la
modification enregistrée ; l'historique garde le nom du fichier.

## 10. Données

| Table | Contenu |
|---|---|
| `motifs_absence` | `organization_id`, `code` (unique par organisation, figé), `libelle`, `is_active`, `ordre` |
| `absences` | `organization_id`, `employe_id`, `motif_absence_id`, `date_debut`, `date_fin`, `commentaire`, `justificatif_*`, `statut` (`enregistree`, `annulee`), `cree_par`, `modifie_par`, `annule_par`, `annule_le`, `motif_annulation`, horodatages |

## 11. Évolutions prévues (non développées)

Congés payés, droits acquis, jours pris et solde ; demandes et validations ; incidence sur la paie.
Ces notions viendront à côté des absences (ADR 0070), sans changer leurs règles.

À ne pas confondre : la **variable de paie « Absence »** (`TypeVariablePaie::ABSENCE`, retenue en
montant saisie à la main dans la paie, colonne `total_absences`) existe indépendamment de ce module.
Elle n'est ni alimentée ni modifiée par les absences enregistrées ici, et elle ne porte ni motif
ni commentaire.
