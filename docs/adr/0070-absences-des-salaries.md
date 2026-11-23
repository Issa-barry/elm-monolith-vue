# ADR 0070 — Absences des salariés, distinctes des congés

- **Date** : 2026-11-23
- **Statut** : accepté le 2026-11-23 (demande de l'utilisateur)
- **Détail fonctionnel** : [docs/rh-absences.md](../rh-absences.md)

## Contexte

Le module RH ne permettait pas d'enregistrer les absences des salariés. Le besoin exprimé est une
première version simple : RH et responsables d'agence saisissent, consultent, modifient et annulent
des absences, avec un motif, des dates, un commentaire et un justificatif. Les congés payés
(droits acquis, jours pris, solde, demandes et validations) et l'incidence sur la paie viendront
plus tard.

## Décision

1. **Une table `absences` propre, sans notion de congé.** Les futurs congés auront leurs propres
   tables (droits, demandes) et pourront s'appuyer sur les absences sans en changer les règles. Ni
   paie, ni transmission de paie, ni contrats ne lisent les absences dans cette version.
2. **Motifs paramétrables par organisation** (`motifs_absence`), sur le modèle des fonctions et
   services RH : sept motifs créés par défaut, renommables, désactivables, jamais supprimés. Le
   terme est « Motif », jamais « Type d'absence ».
3. **Durée en jours calendaires, dates incluses**, calculée et jamais stockée : aucune saisie
   manuelle, aucune incohérence possible entre dates et durée. Jours ouvrés et ouvrables seront
   l'affaire des congés.
4. **Une seule absence enregistrée par jour et par salarié**, quel que soit le motif : chevauchement
   et doublon refusés par le serveur, sous verrou du salarié, avec un lien vers l'absence existante.
5. **Pas de circuit de validation** : un utilisateur habilité enregistre directement.
6. **Annulation logique** : statut `annulee`, auteur, date et raison ; jamais de suppression.
   Création, modifications et annulation sont journalisées dans `audit_logs` (module `rh_absences`).
   Ce journal reste dans le module RH : il est **exclu de l'Historique des actions de la
   Comptabilité** (décision de l'utilisateur du 23/11/2026), car le motif et le commentaire peuvent
   être des données de santé. Une future incidence sur la paie sera visible de la Comptabilité par
   les éléments de paie, sans motif ni commentaire.
7. **Permissions dédiées hors matrice CRUD** : `rh-absences.read`, `.create`, `.update`,
   `.annuler` (pas de `.delete`). Périmètre d'agence commun à la fiche salarié
   (`PerimetreEmploye`) : consulter ses agences ou toutes avec la vision 360° ; écrire sur ses
   agences de rattachement, toutes pour un administrateur ou un superviseur. Valeurs de départ :
   super_admin, admin_entreprise et manager.
8. **Justificatif sur un disque privé dédié** (`absences_rh`), servi seulement par un contrôleur
   après contrôle des droits — même principe que les pièces d'identité.

## Conséquences

- Les comptes « responsable d'agence » doivent être rattachés à leur agence (`user_sites`) pour
  saisir ; la vision 360° seule ne donne que la lecture.
- L'agence prise en compte est l'agence actuelle du salarié : après une mutation, ses absences
  passées suivent le salarié dans sa nouvelle agence.
- Contrairement aux journaux des primes et de la transmission de paie, celui des absences
  n'apparaît pas dans l'**Historique des actions** de la Comptabilité (exclusion par type
  d'entité dans `HistoriqueActionsController`, liste comme historique d'une entité).
- Les tests tournent sous SQLite, où les colonnes `DATE` sont lues avec l'heure : les comparaisons
  de dates utilisent des bornes semi-ouvertes (comme `Periode::appliquer`), justes sous MySQL comme
  sous SQLite.
