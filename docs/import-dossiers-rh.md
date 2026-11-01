# Import de mise à jour des dossiers et contrats RH

Pour initialiser ou corriger en masse les dossiers des salariés **déjà existants** (date d'embauche,
service, fonction, contrat, salaire de base), sans ressaisie. Modèle : l'import de mise à jour des
véhicules (`docs/import-vehicules-maj.md`).

```text
Employés → Mettre à jour par fichier → Exporter pour mise à jour → modifier le fichier Excel
         → déposer → aperçu (avant → après) → confirmer (ou abandonner)
```

## Règles métier

- **IMPRH-001** — Le fichier pré-rempli (feuille `employes`, plus `references` et `mode_emploi`)
  contient une ligne par salarié des **agences d'écriture** de l'utilisateur. Il se réimporte tel
  quel : mêmes colonnes, même ordre. Le matricule est écrit en texte ; un matricule devenu nombre
  dans Excel (4213) retrouve ses zéros (004213).
- **IMPRH-002** — Chaque ligne est retrouvée par son **matricule**, dans l'organisation de
  l'utilisateur. Introuvable (ou d'une autre organisation : même message), hors des agences de
  l'utilisateur, ou présent sur plusieurs lignes : erreur. **Aucun salarié n'est jamais créé.**
- **IMPRH-003** — Colonnes lues, liste fermée : `date_embauche`, `service`, `fonction`,
  `contrat_type`, `contrat_date_debut`, `contrat_date_fin`, `salaire_base`. Les colonnes `nom`,
  `prenom`, `agence` (et toute autre) ne sont jamais écrites.
- **IMPRH-004** — **Cellule vide = valeur conservée.** Cellule renseignée = elle remplace la valeur
  actuelle (date d'embauche, service, fonction), avec l'écart affiché dans l'aperçu.
- **IMPRH-005** — Service et fonction : libellé ou code exact d'un élément **actif**. Inconnu :
  erreur avec suggestion ; désactivé : erreur. **Jamais de création de référentiel.** Une fonction
  change par `EmployeAffectationService` (historique d'affectation, motif `import_rh`, site inchangé).
- **IMPRH-006** — Contrat : un salarié **sans** contrat actif reçoit un contrat actif créé par
  `ContratService` (un seul contrat actif, sous verrou). Un salarié **avec** un contrat actif : les
  colonnes doivent lui correspondre (inchangé), sinon **erreur** — un contrat existant se modifie
  dans l'écran Contrats, jamais par l'import. CDD : fin obligatoire et ≥ début ; CDI : pas de fin.
  Salarié sorti : création refusée. Début antérieur à l'embauche : avertissement non bloquant.
- **IMPRH-006 bis** — **Sans PDG désigné** (signataire des contrats, ADR 0048) : aucun contrat
  n'est créé. La ligne n'est **pas** en erreur (une erreur bloquerait tout le fichier) : le dossier
  (embauche, service, fonction) est mis à jour, le contrat et son salaire sont écartés avec un
  avertissement qui dit où désigner le PDG. Une fois le PDG désigné, la même ligne réimportée
  crée le contrat. Un contrat importé n'a ni période d'essai ni heures par semaine : son PDF est
  « à compléter » jusqu'à ce que le contrat soit complété dans RH → Contrats → Modifier.
- **IMPRH-007** — Salaire de base, avec `rh-remunerations.read` uniquement : pris **seulement à la
  création du contrat**. Sur un contrat actif existant, il doit être identique au salaire du contrat
  (inchangé) ; absent ou différent : erreur — un contrat existant, salaire compris, se modifie dans
  l'écran Contrats (décision D3, revue du 27/10/2026). Sans la permission, la colonne est absente de
  l'export, ignorée à l'import (avertissement) et les montants sont masqués partout (aperçu,
  historique, fichier d'erreurs), y compris si la permission est retirée après l'import. Aucun
  message d'erreur ni journal ne cite un montant ; une erreur SQL n'est jamais journalisée avec sa
  requête (seulement son code).
- **IMPRH-008** — Aperçu sans écriture. Confirmation impossible tant qu'une ligne est en erreur.
  À la confirmation : passage atomique du statut (une double confirmation est refusée), **nouvelle
  analyse** du fichier, puis application **tout ou rien** dans une transaction. Réimporter un
  fichier déjà traité ne produit que des lignes « inchangé ».
- **IMPRH-009** — Le fichier déposé contient des salaires : il est stocké sur le disque privé et
  **supprimé** dès que l'import est terminé, échoué ou abandonné, ainsi qu'après une erreur imprévue
  au dépôt. Le rapport reste pour la traçabilité.
- **IMPRH-010** — Journal (`AuditLogService`) : une entrée par salarié et par contrat modifiés,
  méta `module = import_rh`, `import_id`. Salaire noté « renseigné », jamais le montant.
- **IMPRH-011** — Un lecteur limité à certaines agences ne voit, dans l'aperçu et le fichier
  d'erreurs, que les lignes des salariés de ses agences (les autres sont seulement comptées).

## Permissions

| Action | Permission |
|---|---|
| Historique et aperçus | `imports-rh.read` |
| Exporter, déposer, confirmer, abandonner | `imports-rh.create` + `rh-employes.update` |
| Créer un contrat par l'import | + `rh-contrats.create` (sinon la ligne est en erreur) |
| Colonne salaire et montants affichés | + `rh-remunerations.read` |

`imports-rh.*` : accordées par défaut à super_admin et admin_entreprise (migration de rattrapage),
délégables dans *Rôles*. Organisation et agences contrôlées explicitement (Gate::before du super
administrateur).

## Prérequis avant l'import réel

1. Lot 1 et cet import déployés (migrations `2026_10_27_*`), permissions rattrapées.
2. Services et fonctions RH créés dans *Paramètres → RH*.
3. Exporter le fichier, le compléter, déposer, **contrôler l'aperçu**, puis confirmer.

## Fichiers clés

- `app/Services/ImportRh/` — `ImportRhParser` (analyse, lecture seule), `ImportRhExecutor`
  (application), `ImportRhService` (cycle et suppression du fichier), `ImportRhPresenter` (masquage
  des salaires), exports (`ExportDossiersRhMajExport`, `ImportRhErreursExport`), `ColonnesImportRh`.
- `app/Services/Rh/ContratService.php` — règle « un seul contrat actif », partagée avec l'écran Contrats.
- `app/Services/ImportFlotte/Normalizers/ImportDateNormalizer.php`, `ImportMontantNormalizer.php`.
- `app/Http/Controllers/Rh/Imports/` — contrôleurs mono-action.
- Tests : `tests/Feature/ImportRhTest.php`, `tests/Unit/ImportNormalizersRhTest.php`.
