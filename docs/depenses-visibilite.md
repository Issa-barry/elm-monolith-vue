# Dépenses — visibilité par agence

Qui voit quelles dépenses, sur quels écrans, et comment le filtre Agence s'y applique.
Décision : [ADR 0065](adr/0065-visibilite-des-depenses-par-agence.md). Code : `App\Support\Depenses\DepensePerimetre`
(seul point de calcul), branché sur `SiteScopeService` (agences de rattachement) et
`DroitCreationDepenseService` (droits de validation de Paramètres → Dépenses).

## Règles métier (IDs)

- **DEPVIS-001** — **Toutes les agences** de l'organisation, dépenses sans agence comprises, pour :
  - la permission du rôle **« Dépenses — consulter les dépenses de toutes les agences »**
    (`depenses.lecture_toutes_agences`, Rôles & Permissions → Dépenses → Périmètre de visibilité) ;
  - ou la vision de toutes les agences déjà existante (`User::voitToutesLesAgences()`) :
    administrateur (super_admin, admin_entreprise), superviseur (ADR 0034), permission « vision 360° »
    `sites.lecture_toutes_agences` (ADR 0025).
- **DEPVIS-002** — **Sinon**, l'utilisateur voit les dépenses :
  - de ses **agences de rattachement** (`user_sites`) ;
  - des agences où son rôle peut **valider** (Paramètres → Dépenses : `peut_valider` + permission
    `depenses.update`, périmètre « toutes les agences » ou « agences sélectionnées ») — il voit
    toujours ce qu'il doit valider ;
  - qu'il a **lui-même saisies**, quelle que soit leur agence (il peut toujours reprendre son brouillon).
- **DEPVIS-003** — La permission de visibilité est **indépendante** de « Lire » : sans
  `depenses.read`, aucun écran Dépenses ; elle n'ouvre aucune écriture (créer, modifier, valider
  restent gouvernés par leurs permissions et par Paramètres → Dépenses).
- **DEPVIS-004** — Filtre Agence : les droits déterminent les agences proposées (toutes les agences
  de l'organisation, ou celles de DEPVIS-002) ; l'utilisateur y choisit une ou plusieurs agences,
  vide = tout son périmètre. Le serveur croise toujours `site_ids[]` avec ce périmètre : une agence
  non autorisée est ignorée, jamais ajoutée (une sélection uniquement hors périmètre ne renvoie rien).
- **DEPVIS-005** — Toute dépense est rattachée à une agence : `site_id` obligatoire à la création et
  à la modification (backend et formulaires). Une ancienne dépense sans agence n'est visible qu'en
  vue toutes agences (DEPVIS-001) et par son auteur ; elle reçoit une agence à sa prochaine
  modification.

## Points d'entrée couverts

| Écran / route | Application |
|---|---|
| Liste `GET /backoffice/depenses` (lignes **et** statistiques) | `DepenseListingService::query()` |
| Export `GET depenses/export/excel`, impression `GET depenses/imprimer` | idem |
| Fiche, modification, soumission, suppression, historique | `DepensePolicy::view/update/delete` |
| Suggestions du filtre (concerné, téléphone, véhicule) | `SuggestionsDepenseController` |
| Onglet Dépenses de la fiche agent | `AgentDepensesService::pourAgent()` (périmètre du consulteur) |
| Dépenses des fiches véhicule et propriétaire | `VehiculeController::show`, `ProprietaireController::show` |

Les **rapports** (rapport d'activité, rapport par agence, « Ma situation », charges) gardent leur
propre périmètre (`RapportPerimetreResolver`, [rapports.md](rapports.md)) : inchangés.

## Paramétrage par défaut

Migration `2026_11_15_100000_backfill_depenses_lecture_toutes_agences_permission` : la permission
est accordée une fois aux rôles `super_admin`, `admin_entreprise`, `superviseur` et aux rôles de
trinôme `ADD` (assistant·e de direction). Ensuite, seule la case compte. Les rôles administrateur et
superviseur voient de toute façon toutes les agences (DEPVIS-001) : décocher la case sur ces rôles
ne les restreint pas.

## Régularisation des dépenses sans agence

Avant ou pendant le déploiement, compter puis lister les dépenses concernées (lecture seule) :

```sql
SELECT d.statut, COUNT(*) AS nombre, SUM(d.montant) AS montant
FROM depenses d
WHERE d.site_id IS NULL AND d.deleted_at IS NULL
GROUP BY d.statut;

SELECT d.id, d.date_depense, d.montant, d.statut, d.commentaire, d.user_id
FROM depenses d
WHERE d.site_id IS NULL AND d.deleted_at IS NULL
ORDER BY d.date_depense DESC;
```

Selon le statut :

- **Brouillon, rejetée, annulée** : modifier la dépense à l'écran (un administrateur choisit
  l'agence ; le champ est obligatoire).
- **Soumise** : la rejeter, puis la corriger et la soumettre à nouveau.
- **Validée** : la pièce comptable a été passée avec le même `site_id` vide
  (`DepenseComptabilisationService`). Changer l'agence en SQL désynchroniserait la comptabilité : ne
  pas le faire sans décision explicite (correction de la dépense **et** de sa pièce). D'ici là, elle
  reste visible en vue toutes agences (DEPVIS-005).

Une fois la liste vide en production, la colonne pourra passer en `NOT NULL` par une migration
dédiée, et la règle transitoire de DEPVIS-005 disparaîtra.
