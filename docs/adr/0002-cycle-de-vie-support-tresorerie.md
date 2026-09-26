# ADR 0002 — Cycle de vie d'un support de trésorerie : brouillon → validé (actif) → désactivé

- **Date** : 2026-09-19
- **Statut** : accepté et livré le 2026-09-19
- **Périmètre** : Comptabilité > Trésorerie > Supports (caisses, banques, Mobile Money, caisses
  dédiées aux agents) — complète l'[ADR 0001](0001-caisse-dediee-agent-sous-compte.md)

## Contexte

Jusqu'ici, un support de trésorerie était utilisable dès sa création par un profil habilité
(`tresorerie.gerer_soldes_ouverture`). Seul le **solde d'ouverture** avait un état « brouillon /
validé », ce qui produisait sur l'écran Supports deux mentions « Validé » d'origines différentes
(le solde d'ouverture et, implicitement, le support « Actif »), sans qu'aucune validation du support
lui-même n'existe. Le propriétaire du produit a demandé un vrai workflow **Brouillon → Validé →
Actif** : un support (caisse, banque, wallet) ne doit servir qu'après avoir été contrôlé.

## Décision

1. **Un support est créé en brouillon** et reste **inutilisable** — encaissements, mouvements de
   fonds, versements, position de trésorerie, soldes d'ouverture — jusqu'à sa **validation** par un
   utilisateur habilité. La validation le met en service (**actif**) et trace **qui** et **quand**.
   Le support peut ensuite être désactivé puis réactivé (règles de désactivation inchangées).
2. **« Validé » est l'acte, « Actif » est l'état résultant.** Il n'y a pas d'état intermédiaire
   « validé mais pas encore actif » : la liste n'affiche qu'**un seul statut** par ligne (Brouillon,
   Actif, Inactif), ce qui répond à la remarque initiale (« Validé » ne doit pas se lire comme un
   second statut à côté d'« Actif »). Le solde d'ouverture, lui, n'apparaît plus qu'en alerte ambre
   quand une action est requise, et en détail dans « Modifier le support ».
3. **Une permission dédiée**, `tresorerie.valider_supports`, distincte de
   `tresorerie.gerer_soldes_ouverture` (créer / modifier) : l'organisation peut confier la
   validation à un autre profil que celui qui crée. Portée : organisation + agence de
   l'utilisateur (admins : toutes). Préréglages du seeder : `admin_entreprise` et `comptable`.
4. **Le statut est dérivé, pas stocké** : `valide_le` (+ `valide_par_id`) et l'`actif` existant.
   `actif` reste l'unique verrou d'usage lu par tout le code (une vingtaine de points : résolveur
   d'encaissements, mouvements, position, listes) — aucun n'a eu à changer. `valide_le` garantit
   seulement qu'un support jamais validé ne devient jamais actif (garde du modèle).
5. **Reprise de l'existant** : tout support déjà en base est réputé validé à sa date de création
   (`valide_par_id` NULL = repris). Rien ne change d'état ni d'usage à la mise en production.
6. **Caisse dédiée** : créée en brouillon aussi ; sa validation revérifie l'agent (actif, rattaché à
   l'agence) et l'unicité « une seule caisse dédiée active par (agent, site) ». Créer un brouillon
   reste refusé tant qu'une caisse active existe pour le même (agent, site).
7. **Routage des encaissements** : `CaisseAgentResolver` date désormais la **mise en service** de la
   caisse à sa validation (`valide_le`) et non à sa création. Sans cela, un rattrapage comptable
   aurait reclassé vers la caisse des encaissements enregistrés pendant qu'elle était en brouillon,
   violant la règle « jamais de reclassement de l'historique » (ADR 0001, phase 2).

## Décisions d'interprétation à connaître

- **Pas de règle « créateur ≠ validateur »** : imposer un second utilisateur aurait bloqué les
  organisations à un seul administrateur (cf. le débat sur la séparation envoi/réception de
  l'ADR 0001). La séparation des tâches s'obtient par l'attribution des permissions ; une règle
  stricte pourra être ajoutée plus tard si l'organisation le demande.
- **Pas d'état « validé non activé »** (voir point 2) : l'ajouter serait une extension simple
  (une colonne d'activation), mais ajouterait une étape par support sans besoin métier identifié.
- **Le solde d'ouverture ne conditionne pas la validation du support** : ce sont deux actes
  distincts, chacun avec son propre état. Un support validé sans solde d'ouverture reste
  signalé (alerte ambre) et rend la position du site « non fiable » côté Financement, comme avant.
- **Pas de suppression d'un brouillon** dans ce chantier : un brouillon inutile se valide puis se
  désactive (aucun effet comptable). À reprendre si l'encombrement devient un sujet.

## Alternatives écartées

- **Colonne `statut` stockée** (brouillon / valide / actif / inactif) : plus explicite, mais
  dupliquerait `actif` (lu partout, et écrit directement par de nombreux tests), avec un risque de
  dérive entre les deux colonnes et une migration de tous les écrivains.
- **Validation sans permission dédiée** (réutiliser `tresorerie.gerer_soldes_ouverture`) : moins de
  configuration, mais celui qui crée validerait toujours seul — pas de vrai contrôle.

## Conséquences

- **Migration** `2026_09_19_300000_add_validation_to_compta_supports_tresorerie_table` : ajoute
  `valide_le` et `valide_par_id`, puis reprend l'existant (non destructive, réversible).
- **Nouvelle permission sans backfill** : les rôles personnalisés existants ne l'ont pas tant
  qu'un administrateur ne la coche ; les rôles système la reçoivent au prochain passage du seeder.
  Tant qu'aucun profil ne la porte, seuls les super admins peuvent valider un nouveau support.
- Un support créé directement **actif** par du code (hors écran) est réputé validé — les deux seuls
  points de création applicatifs (`CompteTresorerieController::store`,
  `CaisseAgentService::creer`) créent explicitement en brouillon.
- La suppression d'un utilisateur responsable d'une caisse dédiée **en brouillon** est refusée,
  comme pour une caisse active (sinon la FK `nullOnDelete` la transformerait en support d'agence).

## Références

- Code : `App\Models\CompteTresorerie`, `App\Enums\StatutSupportTresorerie`,
  `App\Services\Tresorerie\SupportTresorerieValidationService`,
  `App\Services\Tresorerie\CaisseAgentService`, `App\Services\Tresorerie\CaisseAgentResolver`,
  `App\Services\Tresorerie\MouvementFondsService`,
  `App\Services\Tresorerie\SoldeOuvertureTresorerieService`,
  `App\Policies\CompteTresoreriePolicy`,
  `App\Http\Controllers\Comptabilite\CompteTresorerieController`.
- Tests : `SupportTresorerieValidationServiceTest`, `SupportTresorerieValidationControllerTest`,
  `CaisseAgentServiceTest`, `CaisseAgentEncaissementTest`, `FinancementAgenceServiceTest`,
  `CaisseAgentImpactTresorerieTest`, `UserTest`, `Supports/__tests__/Index.spec.ts`,
  `tresorerie-financement-flow.spec.ts` (E2E).
- Documentation : `docs/data-dictionary-compta.md` (« Cycle de vie d'un support de trésorerie »),
  `docs/encaissements.md` (« Comptabilisation : caisse dédiée de l'agent »).
