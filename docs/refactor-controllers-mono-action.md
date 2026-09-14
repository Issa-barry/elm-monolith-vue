# Réorganisation des contrôleurs — contrôleur mono-action (`__invoke`)

## Objectif

Réorganiser progressivement **tous** les contrôleurs applicatifs du dépôt (web + API) vers un
contrôleur par action HTTP (`__invoke()`), y compris pour le CRUD, regroupés par domaine métier.
Aucun changement de comportement observable (routes, permissions, isolation organisation/agence,
validations, réponses, transactions, effets de bord) pendant cette migration — c'est un
refactoring pur, pas une correction fonctionnelle. Voir l'historique de cadrage dans la
conversation ayant précédé ce document pour le détail des décisions (architecture cible,
exclusions du pilote, ordre des contrôles à préserver par action).

## Correction du 12/09/2026 — relecture du bilan des lots 1 à 6

Une relecture du dépôt (après le commit `045f0ae6`, réalisé par l'outillage d'auto-commit de
l'utilisateur, pas par cette session) a révélé plusieurs erreurs dans le bilan précédemment
communiqué, corrigées ci-dessous et dans le reste de ce document :
- Le nombre de nouveaux contrôleurs est **25**, pas 34 (somme exacte par lot : 3+6+4+3+2+7).
- **3 domaines** sont entièrement clos (Api/Mobile, Api/Client, Sites & Organisation), pas 2.
- `ProduitController` a **10 actions HTTP restantes**, pas 11 (le chiffre précédent incluait le
  constructeur).
- L'inventaire global (51 conformes / 99 à traiter) contenait un bug de classification — corrigé
  en détail dans la section Inventaire ci-dessous (chiffres réels : 54 conformes / 86
  multi-actions à traiter au départ, sur 153 contrôleurs).
- Les vérifications frontend (typecheck, ESLint, Vitest, `lint:standards`, Prettier) et une
  vérification réelle en navigateur du parcours Variantes n'avaient pas été exécutées avant ce
  bilan — faites maintenant, résultats consignés au Lot 1.
- Les « 316 tests » cités dans un bilan précédent couvrent une régression PHPUnit ciblée sur les
  fichiers touchés, pas une garantie d'absence de régression sur l'ensemble du projet — reformulé
  partout où cette ambiguïté existait.

## Convention cible

- Un contrôleur par action HTTP, `__invoke()`, y compris `Index/Create/Store/Show/Edit/Update/Destroy`.
- Regroupement en dossiers par domaine métier (`app/Http/Controllers/<Domaine>/...`).
- Le contrôleur ne porte que le traitement HTTP ; la logique métier reste dans les
  Services/Actions/Policies existants — réutilisés, jamais dupliqués.
- Pas de Form Request systématique, pas de couche Repository/Interface, pas de contrôleurs qui
  s'appellent entre eux, pas de trait servant uniquement à cacher de la logique déplacée.
- Toute extraction de règle métier (au-delà du simple déplacement HTTP) est une étape séparée,
  justifiée par un bénéfice concret, jamais mêlée à un changement de comportement.

## Méthodologie par lot (répétée pour chaque domaine)

1. Auditer les actions du domaine : routes, dépendances (Services/Policies/Models), contrats
   (Inertia/JSON), consommateurs (frontend, Wayfinder, OpenAPI si `routes/api.php`).
2. Inventorier les tests existants (Feature/Unit/E2E) et compléter uniquement les scénarios de
   comportement manquants (permissions, isolation organisation, ordre des contrôles, erreurs,
   transactions/rollback). Faire passer ces tests sur le code **actuel** avant tout déplacement.
3. Déplacer les actions vers des contrôleurs mono-action, en conservant strictement l'ordre des
   contrôles propre à chaque action (l'ordre peut différer d'une action à l'autre dans un même
   contrôleur d'origine — ne pas l'uniformiser).
4. Extraire les dépendances privées partagées entre plusieurs actions vers un composant commun
   (Service/Support), sans duplication, en vérifiant tous les points d'appel restants.
5. Vérifier : `route:list` avant/après (URI, nom, méthode, middleware identiques), tests
   Feature/Unit/E2E concernés, Wayfinder (`php artisan wayfinder:generate --with-form`) et ses
   imports frontend réels (pas seulement leur absence détectée par grep), OpenAPI si le domaine
   touche `routes/api.php`, Pint, ESLint/Prettier, `vue-tsc`, Vitest.
6. Mettre à jour ce document (colonnes Statut / Vérifications / Écarts).

**Leçon retenue au lot 2 (Api/Mobile), à appliquer à tout domaine touchant `routes/api.php`** :
`php artisan scramble:export --api=default` (+ `--api=vitrine` si pertinent) et
`git diff -- docs/openapi/` AVANT de considérer un lot terminé, pas seulement vérifier que les
routes sont exclues de `config('scramble.api_path')`. Deux régressions réelles, invisibles aux
tests PHPUnit, n'ont été détectées QUE par cet export :
- une méthode privée typée `?string` inlinée directement dans `__invoke()` a fait perdre la
  nullabilité du schéma documenté (Scramble infère le schéma depuis la signature de méthode
  statiquement analysée, pas depuis le type réel de retour de `config()`, `mixed`) ;
- `App\Providers\OpenApiServiceProvider::TAGS_BY_CONTROLLER` mappe les tags de la doc par
  **classe de contrôleur** — toute classe renommée/scindée doit y être remappée, sinon
  l'endpoint retombe silencieusement dans le tag générique "Autres".
Après correction des deux, l'export était strictement identique au fichier committé (diff nul).

**Leçon retenue au lot 4 (Sites & Organisation)** : ne jamais lancer un test en arrière-plan
(`run_in_background`) juste après avoir modifié `routes/*.php`/un Service Provider tant que le
`use` d'import ET la registration de route ne sont pas TOUS LES DEUX à jour dans le même geste —
`vendor/bin/pint` peut supprimer silencieusement un import "inutilisé" si la registration n'a pas
encore été mise à jour, laissant une classe supprimée encore référencée par `Route::get(...)`.
Un test lancé en arrière-plan pendant que ce fichier est dans cet état intermédiaire échoue en
masse ("Invalid route action") sur des tests SANS RAPPORT avec le lot en cours (Laravel charge
tout `routes/*.php` au boot, quel que soit le filtre PHPUnit) — un faux signal de régression
généralisée. Toujours : (1) mettre à jour import + registration ensemble, (2) `route:list` complet
immédiatement après pour vérifier l'absence d'erreur de résolution, (3) seulement alors lancer les
tests (au premier plan ou en arrière-plan sans autre édition de fichier de bootstrap en parallèle).

Base de test : SQLite en mémoire (`phpunit.xml`, `DB_CONNECTION=sqlite`, `:memory:`) — aucune
opération sur les données de dev/prod.

## Inventaire global — CORRIGÉ le 12/09/2026

**Correction importante** : le premier inventaire (51 conformes / 99 à traiter) contenait un bug
de script — la classification excluait mal le constructeur du décompte d'actions pour certains
fichiers, et confondait « une seule action » avec « conforme à la convention cible » sans vérifier
que cette action s'appelle bien `__invoke()`. Recompté intégralement par lecture directe du code
(`git show <commit_avant_ce_chantier>:<fichier> | grep -c ...`), avec 4 catégories distinctes :

- **Conforme** : exactement une action publique (hors constructeur), nommée `__invoke()`.
- **Action unique, renommage trivial** : exactement une action publique, mais PAS nommée
  `__invoke()` — ne respecte pas encore la convention cible, mais ne nécessite qu'un renommage +
  mise à jour de la route (pas d'extraction). 10 fichiers dans ce cas.
- **Multi-actions** : ≥ 2 actions publiques — nécessite une vraie extraction (le périmètre de ce
  chantier).
- **Hors périmètre** : 0 action publique (traits, classe de base `Controller.php`) — pas de route,
  pas concerné.

**Baseline avant ce chantier (153 contrôleurs)** : 54 conformes, 10 renommage trivial,
**86 multi-actions à traiter**, 3 hors périmètre.

**État actuel (289 contrôleurs — +178 nouveaux, -42 anciens supprimés, 32 lots dont les 3+1 volets
de Clients, les 4 volets de Depenses, le domaine Divers, le domaine Produits & Stock ET le domaine
Auth & Compte entièrement clos, plus les Lots 28-31 dans le domaine Commandes & Ventes en cours)** :
235 conformes (54 + 178 nouveaux + 3 renommages triviaux), **4 renommage trivial** (10 − 2 traités
dans le Lot 10 − 1 traité au Lot 16 (`StockController`) − 2 traités en fin de domaine Auth & Compte
(`Auth\LivreurRegistrationController`, `Settings\TwoFactorAuthenticationController`) − 1 traité au
Lot 31 (`FactureVenteController`)), **47 multi-actions restants** (86 − 39 résolus),
3 hors périmètre.

**Correction du 13/09/2026 (bucketing du domaine Clients)** : le Lot 27 a révélé que le domaine
Clients, déclaré clos au Lot 8 à 21 contrôleurs, en comptait en réalité **23** —
`CategorieTarifGrossisteController` et `ClientVehicleController` avaient échappé à l'audit initial
(déclarés au milieu du même bloc de routes `clients.*`, mais sous des noms ne contenant pas
« Client » en préfixe évident). Les 2 étaient déjà comptés dans l'agrégat global 153/86 (recompté
par lecture directe de tous les fichiers, cf. section Inventaire) — seule leur attribution au bon
domaine avait été manquée, pas leur existence. Traités et clos au Lot 27.

**Correction arithmétique (Lot 24)** : une résomation directe des lots 17 à 23 a révélé un écart de
+2 sur le compteur cumulatif « nouveaux contrôleurs » (150 affiché contre 152 réels) et donc sur
le nombre de conformes (204 affiché contre 206 réels) — même type d'écart que la correction
arithmétique globale déjà documentée après le Lot 16. Origine : le bilan du Lot 22
(`InstallWizardController`, 6 nouveaux fichiers) affichait « 146 nouveaux » au lieu de 147 après ce
lot. Corrigé ci-dessus ; les totaux par lot (Lots 17 à 23) n'ont pas été réécrits individuellement,
seul le cumul global l'a été.

| Domaine | Contrôleurs (actuel) | Conforme | Renommage trivial | Multi-actions restant | Priorité |
|---|---|---|---|---|---|
| Comptabilite & Commissions | 24 | 0 | 3 | 21 | 6 (le plus sensible : argent, commissions, paie) |
| Logistique | 23 | 10 | 1 | 12 | 5 (fort couplage véhicule/équipe/commission) |
| RH & Personnel | 8 | 0 | 0 | 8 | 5 |
| Produits & Stock | 43 | 43 | 0 | 0 | — (fait) |
| Auth & Compte | 43 | 43 | 0 | 0 | — (fait) |
| Divers | 5 | 5 | 0 | 0 | — (fait) |
| Commandes & Ventes | 8 | 7 | 0 | 1 | 5 (en cours, sensible : tarification/solvabilité) |
| Parametrage (Settings vente/logistique/communication) | 10 | 10 | 0 | 0 | — (fait) |
| Depenses | 28 | 28 | 0 | 0 | — (fait) |
| Clients | 23 | 23 | 0 | 0 | — (fait, corrigé au Lot 27, cf. note ci-dessous) |
| Sites & Organisation | 13 | 13 | 0 | 0 | — (fait) |
| Api/Mobile | 10 | 10 | 0 | 0 | — (fait) |
| Api/Client | 15 | 15 | 0 | 0 | — (fait) |
| Api/Auth | 16 | 15 | 0 | 0 | — (fait, 1 hors périmètre) |
| Api/Public | 5 | 5 | 0 | 0 | — (fait) |
| Api/Backoffice (hors Logistique) | 2 | 2 | 0 | 0 | — (fait) |
| Recherche | 1 | 1 | 0 | 0 | — (fait) |

Priorité croissante = domaines les plus simples/isolés d'abord, les plus sensibles en dernier. La
colonne « Renommage trivial » (10 fichiers au total, tous domaines confondus) est un gisement
distinct et beaucoup moins coûteux que les « multi-actions » — à traiter en fin de chantier dans
chaque domaine une fois les vraies extractions faites, pas en priorité.

**Correction du 13/09/2026 (bucketing du domaine Divers)** : l'ancien chiffre « Divers : 11
contrôleurs (1 conforme / 2 renommage trivial / 6 multi-actions) » ne résistait pas à une
vérification directe (recherche dédiée, cf. Lot 10) — il était d'ailleurs déjà interne-incohérent
(1+2+6=9 ≠ 11). Seuls 4 contrôleurs appartenaient réellement à Divers (vérifié par groupe de
routes) : `SitemapController` (déjà conforme), `CommunicationController` et `DashboardController`
(renommage trivial), `ContactController` (2 actions, multi-action). Les autres candidats
plausibles se sont révélés appartenir à d'autres domaines par leur groupe de routes réel :
`StockController`/`MediaController` → Produits & Stock (groupe `module:PRODUITS`, confirmé et
recalculé au Lot 11 : ligne Produits & Stock ci-dessus déjà à jour avec ces deux contrôleurs),
`LivreurController`/`ProprietaireController` → Logistique (groupe `module:VEHICULES`),
`Testing\CommissionE2eDiagnosticController` → Comptabilite & Commissions. Les lignes de ces deux
derniers domaines dans le tableau ci-dessus n'ont **pas** été recalculées (elles reflètent encore
l'inventaire global de 153 contrôleurs, pas encore ré-audité domaine par domaine) — à corriger
précisément quand chacun sera traité, par la même méthode de vérification directe.

**Correction du 13/09/2026 (bucketing du domaine Auth & Compte)** : l'ancien chiffre « Auth & Compte :
12 contrôleurs (2 conformes / 2 renommage trivial / 8 multi-actions) » ne résistait pas non plus à
une vérification directe (recherche dédiée par agent, même méthode qu'au Lot 10 pour Divers) — le
chiffre réel est **13 contrôleurs (2 conformes / 2 renommage trivial / 9 multi-actions)** avant tout
traitement de ce domaine. Les 9 multi-actions, du plus petit au plus gros : `Settings\PasswordController`
(38 lignes, 2 actions), `Auth\ForcePasswordChangeController` (50 lignes, 2 actions),
`AccountController` (89 lignes, 2 actions), `UserInvitationController` (96 lignes, 4 actions, deux
groupes de middleware distincts), `Settings\ProfileController` (130 lignes, 3 actions),
`InstallWizardController` (242 lignes, 6 actions, public/sans authentification),
`Auth\AcceptInvitationController` (302 lignes, 5 actions, public/sans authentification),
`RoleController` (305 lignes, 6 actions), `UserController` (611 lignes, 9 actions routées + un
helper non routé `indexProps()` réutilisé par `AccountController::index`). Ordre de traitement
retenu : du plus petit/simple au plus gros, comme pour tous les domaines précédents.

## Suivi d'avancement

### Terminé — 10 domaines entièrement clos (Api/Mobile, Api/Client, Sites & Organisation, Parametrage, Clients, Depenses, Divers, Produits & Stock, Auth & Compte, Commandes & Ventes)

- **Auth & Compte — Password, ForcePasswordChange, Account, UserInvitation, Profile,
  InstallWizard, AcceptInvitation, Role, User + 2 renommages triviaux**
  (`Settings\PasswordController` 2 actions au Lot 17 ; `Auth\ForcePasswordChangeController`
  2 actions au Lot 18 ; `AccountController` 2 actions au Lot 19 ; `UserInvitationController`
  4 actions au Lot 20 ; `Settings\ProfileController` 3 actions au Lot 21 ;
  `InstallWizardController` 6 actions au Lot 22 ; `Auth\AcceptInvitationController` 5 actions au
  Lot 23 ; `RoleController` 6 actions au Lot 24 ; `UserController` 9 actions routées + le helper
  non routé `indexProps()` au Lot 25 ; `Auth\LivreurRegistrationController` et
  `Settings\TwoFactorAuthenticationController`, les 2 renommages triviaux du domaine, traités en
  toute fin) → voir Lots 17 à 25 ci-dessous. **Domaine clos.**
- **Produits & Stock — pilote Variantes + `ProduitController` + `CategorieController` +
  `ProduitTypeController` + `OptionCatalogueController` + `ImportProduitsController` +
  `MediaController` + `StockController`** (`ProduitController::updateVariante/variantesIndex/
  variantesBulkUpdate`, 3 actions au Lot 1 ; les 10 actions restantes de `ProduitController` au
  Lot 11 ; les 5 actions de `CategorieController` au Lot 12 ; les 5 actions de
  `ProduitTypeController` au Lot 13 ; les 6 actions de `OptionCatalogueController` au Lot 14 ;
  les 8 actions de `ImportProduitsController` au Lot 15 ; les 5 actions de `MediaController` et
  l'action unique de `StockController` (renommage trivial) au Lot 16) → voir Lots 1, 11 à 16
  ci-dessous. **Domaine clos.**
- **Api/Mobile — Notifications + Web Push** (`NotificationsController` 3 actions,
  `WebPushSubscriptionsController` 3 actions) → voir Lot 2 ci-dessous. **Domaine clos.**
- **Api/Client — Commandes + Propositions Véhicule** (`CommandesController` 2 actions,
  `PropositionsVehiculeController` 2 actions) → voir Lot 3 ci-dessous. **Domaine clos.**
- **Sites & Organisation — Import + Onboarding + CRUD** (`SiteImportController` 3 actions,
  `OnboardingSiteController` 2 actions, `SiteController` 7 actions) → voir Lots 4, 5, 6
  ci-dessous. **Domaine clos.**
- **Parametrage — Ventes, Communications, Logistique, Paramètres génériques**
  (`VenteParametrageController` 2 actions, `CommunicationRuleController` 2 actions,
  `LogistiqueParametrageController` 3 actions, `ParametreController` 3 actions) → voir Lot 7
  ci-dessous. **Domaine clos.**
- **Clients — Parrainage, CRUD, Dashboard espace client, Tarifs Grossiste, Véhicules partenaire**
  (`ParrainController` 3 actions, `ClientController` 10 actions, `Client\ClientDashboardController`
  8 actions au Lot 8 ; `CategorieTarifGrossisteController` 2 actions et `ClientVehicleController`
  3 actions, découverts manquants et traités au Lot 27) → voir Lots 8 et 27 ci-dessous. **Domaine
  clos** (23/23, chiffre corrigé — cf. correction du 13/09/2026 ci-dessus).
- **Depenses — Types, Import, Paramétrage, Dépense elle-même** (`DepenseTypeImportController`
  3 actions, `DepenseTypeController` 7 actions, `Settings\DepenseParametrageController` 2 actions,
  `DepenseController` 16 actions — le plus gros contrôleur traité à ce jour, 1557 lignes) → voir
  Lot 9 ci-dessous. **Domaine clos.**
- **Divers — Contact, Communications, Dashboard staff** (`ContactController` 2 actions,
  `CommunicationController` et `DashboardController` renommage trivial, `SitemapController` déjà
  conforme) → voir Lot 10 ci-dessous. Domaine réellement composé de 4 contrôleurs seulement (pas
  11, cf. correction du 13/09/2026 ci-dessus). **Domaine clos.**
- **Commandes & Ventes — CommandeVenteStatut, Encaissement, Pdv, FactureVente, CommandeVente**
  (`CommandeVenteStatutController` 2 actions au Lot 28 ; `EncaissementVenteController` 2 actions
  au Lot 29 ; `PdvController` 2 actions au Lot 30 ; `FactureVenteController` 1 action (renommage
  trivial) au Lot 31 ; `CommandeVenteController` 11 actions — de très loin le plus gros contrôleur
  du chantier — au Lot 32) → voir Lots 28 à 32 ci-dessous. Domaine réellement composé de 5
  contrôleurs (pas 6, cf. correction du 13/09/2026 ci-dessus). **Domaine clos** (18/18).

128 nouveaux contrôleurs mono-action créés au total sur ces 7 lots + les trois volets du Lot 8 +
les quatre volets du Lot 9 + le Lot 10 + les Lots 11 à 16
(3+6+4+3+2+7+10+3+10+8+3+7+2+16+4+10+5+5+6+8+5+1), 28 anciens fichiers multi-actions ou
renommage-trivial supprimés (tous ceux traités jusqu'ici, `ProduitController`/`CategorieController`/
`ProduitTypeController`/`OptionCatalogueController`/`ImportProduitsController`/`MediaController`/
`StockController` désormais inclus).

### En cours / prochain lot

- **Commandes & Ventes CLOS** (Lots 28 à 32, cf. ci-dessous) — 10e domaine entièrement clos.
- Domaines encore intégralement à traiter, par priorité croissante : RH & Personnel, Logistique,
  puis Comptabilité & Commissions (le plus sensible, traité en dernier). Les comptages exacts de
  Logistique et Comptabilite & Commissions restent à re-vérifier précisément au moment de chacun
  (cf. correction du 13/09/2026 ci-dessus : ils héritent chacun d'au moins un contrôleur mal
  classé « Divers » dans l'ancien inventaire — Produits & Stock, Auth & Compte, Clients et
  Commandes & Ventes, eux, sont déjà recalculés et entièrement clos). Prochain lot : recherche
  dédiée de la composition réelle du domaine RH & Personnel, avant tout déplacement (même
  méthode qu'aux domaines précédents).

### Restant

86 contrôleurs multi-actions au départ (chiffre corrigé, cf. section Inventaire ci-dessus).
40 entièrement résolus (chiffre corrigé ici — la liste ci-dessous en comptait déjà 40, seul le
total « 20 » n'avait pas été mis à jour depuis les premiers lots ; 86 - 46 restants = 40, cohérent)
(fichier supprimé, actions réparties en contrôleurs mono-action) :
`NotificationsController`, `WebPushSubscriptionsController`, `CommandesController` (Api/Client),
`PropositionsVehiculeController`, `SiteImportController`, `OnboardingSiteController`,
`SiteController`, `VenteParametrageController`, `CommunicationRuleController`,
`LogistiqueParametrageController`, `ParametreController`, `ParrainController`, `ClientController`,
`Client\ClientDashboardController`, `DepenseTypeImportController`, `DepenseTypeController`,
`Settings\DepenseParametrageController`, `DepenseController`, `ContactController`,
`ProduitController`, `CategorieController`, `ProduitTypeController`, `OptionCatalogueController`,
`ImportProduitsController`, `MediaController`, `Settings\PasswordController`,
`Auth\ForcePasswordChangeController`, `AccountController`, `UserInvitationController`,
`Settings\ProfileController`, `InstallWizardController`, `Auth\AcceptInvitationController`,
`RoleController`, `UserController`, `CategorieTarifGrossisteController`, `ClientVehicleController`,
`CommandeVenteStatutController`, `EncaissementVenteController`, `PdvController`,
`CommandeVenteController` → **46 contrôleurs multi-actions restants**. Le gisement principal est
désormais exclusivement Comptabilite & Commissions (21 contrôleurs multi-actions, 8017 lignes) et
Logistique (12 restants, 5491 lignes) — traités en dernier du fait de leur sensibilité (argent,
commissions, paie, véhicules/équipes imbriqués) ; Produits & Stock, Auth & Compte, Clients ET
Commandes & Ventes sont désormais tous intégralement clos (hors renommages triviaux). S'y ajoutent
**6** contrôleurs à action unique mais non encore nommés `__invoke()` (renommage trivial, gisement
séparé, cf. Inventaire — 10 au départ, 4 traités : `CommunicationController`/`DashboardController`
au Lot 10, `StockController` au Lot 16, `FactureVenteController` au Lot 31).

### Blocages / décisions nécessaires

Aucun à ce stade.

## Détail par lot traité

### Lot 1 — Produits & Stock : Variantes Produit (pilote)

**Périmètre** : `ProduitController::updateVariante/variantesIndex/variantesBulkUpdate` →
`app/Http/Controllers/Produits/Variantes/{Update,Index,BulkUpdate}ProduitVarianteController.php`
(`__invoke()`), + extraction de la méthode privée partagée `varianteOptions()` (dupliquée par
appel entre `show()`, `edit()` et l'éditeur de variantes) vers
`app/Support/Produits/ProduitVarianteOptionsFormatter.php`.

**Exclusions tenues** : aucun Form Request introduit, aucun changement de binding de route,
`Request`/`validate()` inline conservés à l'identique. Ordre des contrôles reproduit exactement
(différent entre l'action individuelle et le bulk — voir tests ci-dessous).

**Tests ajoutés avant déplacement** (dans `tests/Feature/ProduitTest.php`, 14 nouveaux, tous verts
sur le code d'origine avant tout déplacement — aucun écart constaté avec le plan) :
mise à jour partielle (champs omis conservés), SKU jamais modifiable même envoyé explicitement,
prix effectif validé sur une mise à jour partielle, refus intra-organisation (sans permission,
même org) sur les 3 actions, priorité de l'autorisation sur un payload invalide (individuel et
bulk), variante étrangère + payload invalide (404 pour l'individuel ; 422 si la forme du payload
bulk est invalide, 404 si la forme est valide mais la ligne viole la règle de prix — deux
comportements distincts, tous deux couverts), rollback strict du bulk avec valeurs d'origine
exactes de toutes les lignes, distinction requête JSON (422) / requête web (redirection + session),
cohérence des données et de l'ordre des options entre `show`/`edit`/l'éditeur de variantes.

**Vérifications après extraction** :
- `php artisan route:list --path=produits` : URI, noms et méthodes HTTP identiques pour les 3
  routes (`produits.variantes.index|update|bulk-update`) ; seule la classe cible a changé.
- Suite complète `ProduitTest.php` + `ProduitVarianteTest.php` + `VarianteMediaTest.php` : 97
  tests verts avant déplacement (baseline), ré-exécutés après déplacement (voir résultat consolidé
  dans le compte rendu de progression).
- `vendor/bin/pint --test` sur les fichiers touchés : vert (un import devenu inutile —
  `Illuminate\Support\Arr` — retiré de `ProduitController.php` par Pint, seule modification hors
  périmètre fonctionnel).
- `php artisan wayfinder:generate --with-form` : régénéré sans erreur ; `resources/js/actions` et
  `resources/js/routes` sont gitignorés (générés en CI, jamais commités) — aucun diff à contrôler
  dans le dépôt. Aucun import direct de ces helpers ni de ces routes générées n'a été identifié
  dans `resources/js` pour ces 3 actions (les pages appellent les URLs en dur via
  `router.put/get`) — vérification par lecture statique uniquement, pas par test navigateur réel.
- OpenAPI : non concerné, ces routes vivent dans `routes/web.php`, hors du périmètre
  `api_path.include: 'api'` de `config/scramble.php`.
- E2E : `produit-flow.spec.ts` et `produit-voir-le-stock.spec.ts` n'exercent pas les routes
  variantes — aucune couverture E2E dédiée à ce périmètre, ni avant ni après ce lot (limite
  préexistante, pas introduite par ce chantier).

**Vérifications frontend complémentaires (ajoutées le 12/09/2026, couvrent l'ensemble des lots
1 à 6, pas seulement le pilote)** :
- `npm run typecheck` (vue-tsc) : vert, aucune erreur.
- `npx eslint . --max-warnings 0` : vert, aucun avertissement.
- `npm run test:unit:frontend` (Vitest) : 51 tests verts (6 fichiers).
- `npm run lint:standards` (AGENTS.md) : vert.
- `npm run format:check` (Prettier) : vert.
- **Vérification réelle en navigateur** (Playwright, base E2E isolée `elm_monolithe_e2e` réinitialisée
  pour l'occasion, organisation démo dédiée, script ad hoc non conservé) : parcours complet
  confirmé, PASS, aucune erreur console/page JS.
  - Fiche produit → onglet Variantes → édition individuelle du prix de vente d'une variante via
    la modale (`VarianteEditModal.vue`) → requête `PUT .../produits/{id}/variantes/{varianteId}`
    (`UpdateProduitVarianteController`) → 303 → **persistance vérifiée indépendamment en base**
    (`prix_vente` mis à jour, `updated_at` changé).
  - Éditeur de variantes (`IndexProduitVarianteController`, `/produits/{id}/variantes`) : rendu
    correct des 2 variantes avec leurs options (Couleur : Bleu/Vert), confirmant que
    `ProduitVarianteOptionsFormatter` alimente bien la page.
  - Édition inline des deux lignes + `Enregistrer` → requête `PUT .../produits/{id}/variantes`
    avec les 2 lignes modifiées (`BulkUpdateProduitVarianteController`) → 303 → **persistance des
    deux lignes vérifiée indépendamment en base** (valeurs distinctes par ligne, même horodatage —
    confirme la transaction unique).
  - **Écart de méthode découvert et résolu en cours de route, sans rapport avec une régression** :
    la première tentative d'édition individuelle (prix de vente seul, sans prix d'achat sur la
    variante seedée) a été rejetée par la règle métier existante (`ProduitService::validerPrixSelonType()`,
    prix incohérent pour le type) — un renvoi 303 "redirection avec erreurs" ressemble à un 303 de
    succès si on ne regarde que le code HTTP (Inertia utilise 303 dans les deux cas pour
    PUT/PATCH/DELETE). Corrigé en donnant à la variante de test un prix d'achat valide au
    préalable ; comportement attendu du produit, pas un bug introduit par ce chantier.
  - Le dialogue raccourci « Modifier le prix » (bulk, sélection multiple) n'a pas pu être cliqué
    de façon fiable par Playwright (timeout d'actionabilité non élucidé, indépendant du
    backend) — contourné en éditant directement les cellules du tableau, qui produit exactement
    la même requête. À noter si quelqu'un écrit un jour un test E2E permanent sur ce dialogue
    précis.

### Lot 2 — Api/Mobile : Notifications + Web Push

**Périmètre** : `NotificationsController` (index/markAllRead/markRead) →
`app/Http/Controllers/Api/Mobile/Notifications/{NotificationsIndex,MarkAllNotificationsRead,MarkNotificationRead}Controller.php` ;
`WebPushSubscriptionsController` (vapidPublicKey/store/destroy) →
`app/Http/Controllers/Api/Mobile/WebPush/{VapidPublicKey,StoreWebPushSubscription,DestroyWebPushSubscription}Controller.php`.
Aucune dépendance privée partagée entre actions dans ces deux contrôleurs d'origine (contrairement
au pilote Produit) — déplacement direct, sans extraction supplémentaire.

**Tests avant déplacement** : couverture déjà exhaustive et pertinente (isolation par utilisateur,
idempotence, 404-jamais-403, pagination, préférences de notification) dans
`tests/Feature/Api/Mobile/NotificationsControllerTest.php` (13 tests) et
`WebPushSubscriptionsControllerTest.php` (11 tests) — aucun scénario manquant identifié, aucun
test ajouté.

**Vérifications après extraction** :
- `route:list --path=notifications|web-push` : URI/noms/méthodes identiques (routes
  `client.notifications.*`, `client.web-push.*`), seule la classe cible change.
- 24 tests verts (`NotificationsControllerTest` + `WebPushSubscriptionsControllerTest`).
- `vendor/bin/pint --test` : vert.
- **OpenAPI** (`api/v1/mobile/*` est dans le périmètre documenté par Scramble, contrairement au
  pilote Produit) : `php artisan scramble:export --api=default` + `git diff -- docs/openapi/` a
  révélé 2 régressions réelles avant correction — voir la leçon retenue en tête de ce document
  (perte de nullabilité du schéma `public_key`, tag de doc "Notifications" → "Autres" pour les 3
  endpoints notifications). Les deux corrigées (méthode privée typée restaurée dans
  `VapidPublicKeyController`, `OpenApiServiceProvider::TAGS_BY_CONTROLLER` remappé) ; export final
  strictement identique au fichier committé (`git diff` nul) — aucune mise à jour de
  `docs/openapi/client.json` nécessaire.
- Wayfinder : régénéré sans erreur (fichiers gitignorés, non commités).
- Aucune référence résiduelle aux deux anciennes classes ailleurs dans le dépôt (grep exhaustif).

### Lot 3 — Api/Client : Commandes ("mine") + Propositions Véhicule

**Périmètre** : `CommandesController` (index/show) →
`app/Http/Controllers/Api/Client/Commandes/{Index,Show}CommandeController.php` ;
`PropositionsVehiculeController` (index/store) →
`app/Http/Controllers/Api/Client/PropositionsVehicule/{Index,Store}PropositionVehiculeController.php`.
Attributs natifs Scramble `#[Endpoint(description: ...)]` (déjà utilisés sur `show()`/`store()`)
recopiés à l'identique sur les nouvelles méthodes `__invoke()`. Injection par constructeur
uniformisée (l'ancien `show()` recevait `ClientIdentityResolver` en paramètre de méthode — changement
neutre, la résolution conteneur est identique).

**Tests avant déplacement** : couverture déjà exhaustive (isolation cross-org, 404 vs liste vide
pour un profil non-client, multi-rôle, pagination/filtres, doublon 422, upload photo) dans
`CommandesControllerTest.php` (12 tests) et `PropositionsVehiculeControllerTest.php` (6 tests) —
aucun scénario manquant, aucun test ajouté.

**Vérifications après extraction** :
- `route:list --path=commandes|propositions-vehicules` : URI/noms/méthodes identiques.
- 35 tests verts (les 2 suites Feature ci-dessus + `tests/Feature/OpenApi/OpenApiDocumentationTest.php`,
  qui vérifie déjà automatiquement la présence de `/v1/mobile/commandes/mine` et
  `/v1/mobile/propositions-vehicules` dans la doc générée — bon filet à conserver pour tout futur
  lot touchant `routes/api.php`).
- `vendor/bin/pint --test` : vert.
- **OpenAPI** : `TAGS_BY_CONTROLLER` remappé (`Orders`, `Vehicle Proposals`) et docblocks/attributs
  recopiés à l'identique dès la première extraction (leçon du Lot 2 appliquée directement) → export
  strictement identique au fichier committé dès le premier essai (`git diff` nul).
- Aucune référence résiduelle aux deux anciennes classes ailleurs dans le dépôt (grep exhaustif).

### Lot 4 — Sites & Organisation : Import de sites

**Périmètre** : `SiteImportController` (modele/analyser/confirmer) →
`app/Http/Controllers/Sites/Import/{Modele,Analyser,Confirmer}SiteImportController.php`,
dépendance privée partagée `toResponse()` extraite vers `App\Support\Sites\SiteImportAnalyseFormatter`.
Routes `routes/web.php` (Inertia backoffice) — hors périmètre OpenAPI.

**Tests avant déplacement** : couverture déjà exhaustive (49 tests dans `SiteImportTest.php` :
permissions, scoping organisation, rapprochement par code avec tolérance aux zéros initiaux,
résolution de site parent par nom/code, rollback complet si erreurs, non-écrasement des champs
optionnels laissés vides) + 4 dans `SiteImportParserTest.php` — aucun scénario manquant, aucun
test ajouté.

**Incident de méthode (sans impact sur le code livré)** : un test de régression consolidé lancé en
arrière-plan juste après la suppression de l'ancien fichier — mais avant la mise à jour des lignes
`Route::get/post(...)` correspondantes — a remonté 83 échecs sans rapport avec ce lot (Laravel
charge tout `routes/web.php` au boot ; une classe supprimée encore référencée y casse TOUS les
tests, quel que soit le filtre). Corrigé en mettant à jour import ET registration de route dans le
même geste, puis en revérifiant `route:list` complet avant tout nouveau test — voir la leçon
retenue au sommet de ce document. Un second run consolidé (243 tests, 10 suites) a ensuite confirmé
zéro régression réelle.

**Vérifications après extraction** :
- `route:list --path=sites/import` : URI/noms/méthodes identiques.
- 53 tests verts (`SiteImportTest` + `SiteImportParserTest`).
- `vendor/bin/pint --test` : vert (après correction de l'ordre des imports).
- Aucune référence résiduelle à l'ancienne classe ailleurs dans le dépôt (grep exhaustif).

### Lot 5 — Sites & Organisation : Onboarding du premier site

**Périmètre** : `OnboardingSiteController` (show/store) →
`app/Http/Controllers/Sites/Onboarding/{Show,Store}OnboardingSiteController.php`. Zone signalée
sensible (cf. mémoire `project_onboarding_site_regression` — un utilisateur de test sans site
attaché avait fait échouer un test 403 en 302 à cause du middleware
`EnsureOrganizationHasSite`/`RequireSiteAssigned`). Vérifié : le groupe de routes
`onboarding/site` n'est PAS derrière ce middleware (c'est précisément la porte de sortie de ce
cas — l'appliquer ici créerait une boucle de redirection, cf. commentaire déjà présent dans
`routes/web.php`), donc le risque documenté ne s'applique pas directement à ce contrôleur —
vérifié malgré tout par l'exécution complète de `OnboardingSiteTest.php`.

**Tests avant déplacement** : couverture déjà exhaustive (10 tests : redirection post-connexion
avec/sans site existant, types suggérés par domaine d'activité, création + rattachement comme
site par défaut, génération automatique du nom, héritage téléphone/pays du Super Admin,
validations, **refus 403 si un site existe déjà** — le scénario le plus sensible) — aucun scénario
manquant, aucun test ajouté.

**Vérifications après extraction** :
- Import et registration de route mis à jour dans le même geste, `route:list` complet (537
  routes) vérifié sans erreur AVANT tout lancement de test (leçon du Lot 4 appliquée).
- `route:list --path=onboarding` : URI/noms/méthodes identiques.
- 17 tests verts (`OnboardingSiteTest` + `LoginResponseTest`, qui référence ce parcours).
- `vendor/bin/pint --test` : vert.
- 4 commentaires devenus obsolètes (mentionnant `OnboardingSiteController` par son ancien nom)
  corrigés dans `DomaineActivite.php`, `EnsureOrganizationHasSite.php`, `InstallationService.php`,
  `AuthRedirects.php` — références à du code, pas de la documentation métier, mais rattachées
  directement à ce chantier (cf. règle CLAUDE.md sur la terminologie obsolète).

### Lot 6 — Sites & Organisation : `SiteController` (CRUD classique, clôture du domaine)

**Périmètre** : `SiteController` (index/create/store/show/edit/update/destroy, enregistré via
`Route::resource('sites', SiteController::class)`) → 7 contrôleurs mono-action sous
`app/Http/Controllers/Sites/`. `Route::resource()` remplacé par 7 déclarations explicites,
`PUT|PATCH` combinés sur une seule route via `Route::match(['put','patch'], ...)` pour reproduire
exactement le comportement de `Route::resource()` (constaté par `route:list` avant/après : 11
routes identiques, y compris la ligne combinée `PUT|PATCH`). Dépendances privées partagées
extraites : `siteData()` → `App\Support\Sites\SiteDataFormatter` (Index/Show/Edit),
`normalizeStrings()`/`messages()` → `App\Support\Sites\SiteFormSupport` (Store/Update).
`roleLabel()` reste privée à `ShowSiteController` (seul appelant, aucun helper équivalent trouvé
ailleurs dans le dépôt après recherche).

**Constat hors périmètre** : `SiteController::parentOptions()` était une méthode privée
totalement inutilisée (aucun appel dans tout le fichier — vérifié par grep). Non reprise dans les
nouveaux contrôleurs : la recréer aurait été porter du code mort sans raison, mais la retirer va
au-delà d'un pur déplacement. Signalé ici plutôt que traité silencieusement.

**Tests avant déplacement** : 4 scénarios manquants complétés dans `SiteTest.php` (`create`/`store`
sans permission, `edit`/`update` retournant 403 pour une autre organisation — la policy
`SitePolicy::update()` vérifie déjà `sameOrganization()`, mais rien ne le testait pour ces deux
actions alors que `show`/`destroy` avaient déjà leur équivalent). 20 tests verts sur le code
d'origine avant tout déplacement.

**Leçon de méthode** : `--filter="SiteTest"` matche par sous-chaîne un grand nombre de classes de
test sans rapport (`StockAlerteMultiVarianteSiteTest`, `TransfertLogistiqueProduitsStockParSiteTest`,
etc.) — cibler le fichier directement (`php artisan test tests/Feature/SiteTest.php`) pour une
vérification précise sans bruit ni fausse impression de couverture.

**Vérifications après extraction** :
- Import ET registration de route mis à jour ensemble, `route:list` complet (537 routes) vérifié
  sans erreur avant tout test (leçons des lots 4/5 appliquées) — Pint vert du premier coup.
- `route:list --path=backoffice/sites` : 11 routes identiques (URI, noms, méthodes, y compris la
  ligne `PUT|PATCH` combinée).
- 56 tests verts (`SiteTest.php` + `UserInvitationTest.php`, qui dépend des routes `sites.*`).
- 2 commentaires obsolètes corrigés (`DomaineActivite.php`, `LogistiqueParametrageController.php`).
- Domaine **Sites & Organisation entièrement clos** (4/4 contrôleurs conformes).

### Lot 7 — Parametrage : Ventes, Communications, Logistique, Paramètres génériques

**Périmètre** (4 contrôleurs, `routes/settings.php` — fichier distinct de `routes/web.php`,
découvert à cette occasion) :
- `VenteParametrageController` (edit/update) → `Settings/Ventes/{Edit,Update}VenteParametrageController`,
  dépendance privée partagée `ensureSalesPermissionsExist()` + 2 constantes de permission extraites
  vers `App\Support\Permissions\VenteParametragePermissions`.
- `CommunicationRuleController` (edit/update) → `Settings/Communications/{Edit,Update}CommunicationRuleController`.
  `VALID_RULE_KEYS`/`assertValidRuleKey()` ne servaient qu'à `update()` — laissés dans
  `UpdateCommunicationRuleController` uniquement, aucune extraction nécessaire.
- `LogistiqueParametrageController` (edit/update/updateSite) → `Settings/Logistique/{Edit,Update,UpdateSite}LogistiqueParametrageController`
  — aucune dépendance privée partagée.
- `ParametreController` (edit/update/downloadTemplate) → `Settings/Parametres/{Edit,Update,DownloadTemplate}ParametreController`
  — aucune dépendance privée partagée.

**Tests avant déplacement** : 3 scénarios manquants complétés (403 sans permission non couvert
pour `create`/`store`-équivalents de ce domaine) : `VenteParametrageTest` (`edit`/`update` sans
permission), `CommunicationRuleControllerTest` (`update` sans permission — seul `edit` l'était).
`LogistiqueParametrageTest` et `ParametreTest`/`ParametreTemplateDownloadTest` étaient déjà
exhaustifs (permissions, cross-org, tous types de valeur) — aucun ajout. 60 tests verts sur le
code d'origine avant tout déplacement.

**Vérifications après extraction** :
- Import ET registration de route mis à jour ensemble dans `routes/settings.php`, `route:list`
  complet (537 routes, inchangé) vérifié sans erreur avant tout test — Pint vert du premier coup.
- `route:list --path=settings` : 41 routes identiques (URI, noms, méthodes).
- 60 tests verts (`VenteParametrageTest`, `CommunicationRuleControllerTest`,
  `LogistiqueParametrageTest`, `ParametreTest`, `ParametreTemplateDownloadTest`).
- `vendor/bin/pint --test` : vert.
- 8 commentaires obsolètes corrigés dans des fichiers tiers (`WhatsAppGateway.php`,
  `PreventCachingOfDynamicResponses.php`, `Parametre.php` ×2, `CommunicationRuleResolver.php`,
  `NullWhatsAppGateway.php`, `RoleVisibility.php`, `tests/e2e/logistique-parametrage.spec.ts`) +
  2 docblocks de test mis à jour — trouvés par grep exhaustif sur les 4 anciens noms de classe.
- Domaine **Parametrage entièrement clos** (4/4 contrôleurs conformes).

### Lot 8 — Clients : Parrainage véhicule (partiel)

**Périmètre** : `ParrainController` (rechercherTelephone/store/update) →
`app/Http/Controllers/Vehicules/Parrain/{RechercherTelephone,Store,Update}ParrainController.php`.
Dépendance privée partagée `validationMessages()` extraite vers
`App\Support\Parrainage\ParrainValidationMessages`. Domaine **Clients pas encore clos** :
`ClientController` (10 actions) et `Client\ClientDashboardController` (8 actions) restent à
traiter.

**Tests avant déplacement** : 2 scénarios manquants complétés dans `ParrainVehiculeTest.php`
(`update` sans permission, `update` cross-organisation — `store`/`rechercherTelephone` avaient
déjà leur équivalent). 16 tests verts sur le code d'origine avant tout déplacement.

**Vérifications après extraction** :
- Import et registration de route mis à jour ensemble dans `routes/web.php`, `route:list`
  complet (537 routes, inchangé) vérifié sans erreur avant tout test.
- `route:list --path=vehicules/.../parrain` : 3 routes identiques (URI, noms, méthodes).
- 16 tests verts (`ParrainVehiculeTest.php`).
- `vendor/bin/pint --test` : vert.
- 3 commentaires obsolètes corrigés (`Parrain.php`, `docs/parrainage-vehicule.md` — au passage,
  une incohérence préexistante non liée à ce chantier a été corrigée dans le même geste : la doc
  citait une méthode `assertPhoneUniqueInOrg()` qui n'existe pas, le code appelle
  `Personne::assertTelephoneDisponible()`).

### Lot 8 (suite) — Clients : `ClientController` (CRUD + cashback + dérogation)

**Périmètre** : `ClientController` (index/create/store/show/edit/update/updateCashback/
updateDerogation/destroy/verifierTelephone, 10 actions) → 10 contrôleurs mono-action sous
`app/Http/Controllers/Clients/` (`IndexClientController`, `CreateClientController`,
`StoreClientController`, `ShowClientController`, `EditClientController`, `UpdateClientController`,
`UpdateCashbackClientController`, `UpdateDerogationClientController`, `DestroyClientController`,
`VerifierTelephoneClientController`). `Route::resource('clients', ...)` remplacé par 7
déclarations explicites + `Route::match(['put','patch'], 'clients/{client}', ...)` pour le
`PUT|PATCH` combiné, en conservant l'ordre `verifier-telephone` déclarée AVANT le bloc
`clients/{client}` (nécessaire pour éviter une collision de liaison de route avec `{client}`) et
les routes `cashback`/`derogation-impayes` après.

Dépendances privées partagées extraites (utilisées par plusieurs actions dans le contrôleur
d'origine, jamais dupliquées dans les nouveaux fichiers) :
- Le bloc de calcul du solde cashback (~20 lignes, dupliqué à l'identique entre `show()` et
  `edit()`) → `App\Support\Clients\ClientCashbackSoldeFormatter::pour(Client $client): ?array`
  (retourne `null` si le module est inactif, un tableau à zéro si actif sans ligne de solde, sinon
  le solde réel).
- `assertPhoneUniqueInOrg()`/`assertEmailUniqueInOrg()` (utilisées par `store()` et `update()`) →
  `App\Support\Clients\ClientUniqueness`.
- `validationMessages()` (utilisée par `store()` et `update()`) →
  `App\Support\Clients\ClientValidationMessages::pour()`.

**Tests avant déplacement** : 2 scénarios manquants complétés dans `ClientTest.php`
(`updateCashback` et `updateDerogation` n'avaient pas de test « refuse sans permission », alors que
les deux avaient déjà leur équivalent cross-organisation) : `test_update_cashback_depuis_la_fiche_refuse_sans_permission`,
`test_update_derogation_returns_403_without_permission`. 65 tests verts sur le code d'origine avant
tout déplacement (152 assertions).

**Vérifications après extraction** :
- Import ET registration de route mis à jour ensemble dans `routes/web.php`, `vendor/bin/pint
  --test` vert du premier coup, `route:list --path=backoffice/clients` : 15 routes identiques
  (URI, noms, méthodes, y compris la ligne `PUT|PATCH` combinée pour `clients.update`) ; `route:list`
  complet (537 routes, inchangé).
- 65 tests verts après déplacement (`ClientTest.php`, 152 assertions) — même résultat qu'avant
  déplacement, aucune régression.
- Régression élargie : `ClientTest.php` + `ClientPersonneTest.php` + `ParrainVehiculeTest.php` :
  91 tests verts (222 assertions).
- `npm run typecheck` (vue-tsc) : vert. `npx eslint resources/js/pages/Clients/partials/ClientForm.vue
  --max-warnings 0` : vert (seul fichier frontend touché, par ses commentaires). `npm run
  lint:standards` : vert.
- OpenAPI : non concerné (routes dans `routes/web.php`, hors périmètre `api_path.include: 'api'`).
- 13 références obsolètes à `ClientController` (nom de classe supprimé) corrigées dans des
  commentaires/docblocks tiers, trouvées par grep exhaustif (`\bClientController\b`, en excluant
  `ClientVehicleController`/`ClientDashboardController`/`Api\Client`) : `app/Enums/ClientType.php`,
  `app/Models/CategorieTarifGrossiste.php`, `app/Models/Personne.php`,
  `app/Services/CashbackEligibiliteService.php`, `app/Services/DerogationImpayesService.php`,
  `app/Services/SolvabiliteService.php`, `app/Services/TelephoneOwnerLookupService.php`,
  `app/Http/Requests/Api/Client/UpdateProfileRequest.php`, `tests/Feature/ClientPersonneTest.php`,
  `resources/js/pages/Clients/partials/ClientForm.vue` (×2), `docs/api-auth-contract.md`,
  `docs/cashback.md`, `docs/grossiste.md` (×2), `docs/identite-client-personne.md` (×3). Un de ces
  commentaires (`SolvabiliteService::seuilApplicableClient()`) référençait en réalité une méthode
  déjà morte avant ce chantier (aucun appelant trouvé, `ShowClientController`/`EditClientController`
  lisent le seuil directement via `Parametre::getVentesSeuilImpayesMax()`) — signalé ici, code non
  modifié (hors périmètre de ce chantier, comportement inchangé).
- `docs/openapi/client.json` régénéré après la correction du docblock de `UpdateProfileRequest`
  (seul champ `description` impacté, texte de commentaire propagé dans le schéma OpenAPI) — diff
  vérifié minimal et attendu (`git diff -- docs/openapi/`), aucun autre changement.
- Domaine **Clients pas encore clos** : `Client\ClientDashboardController` (8 actions) reste à
  traiter.

### Lot 8 (fin) — Clients : `Client\ClientDashboardController` (espace client Inertia, clôture du domaine)

**Périmètre** : `ClientDashboardController` (qrCode/index/earnings/vehicleBalance/proposals/
vehicles/profile/storeVehicleProposal, 8 actions, namespace `App\Http\Controllers\Client`
— singulier, l'espace self-service, distinct du `Clients\` pluriel du CRUD staff traité plus haut
dans ce même lot) → 8 contrôleurs mono-action sous `app/Http/Controllers/Client/`
(`IndexClientDashboardController`, `VehiclesClientDashboardController`,
`EarningsClientDashboardController`, `VehicleBalanceClientDashboardController`,
`QrCodeClientDashboardController`, `ProposalsClientDashboardController`,
`ProfileClientDashboardController`, `StoreVehicleProposalClientDashboardController`).

Dépendance privée partagée extraite : `dashboardPayload()` (et ses méthodes privées
`userProposals()`, `capacitesPayload()`, `vehiculesPartenaires()`, `vehiculesDuProprietaire()`),
dupliquée par appel entre 6 des 8 actions (index/earnings/vehicleBalance/proposals/vehicles/
profile) → `App\Support\Client\ClientDashboardPayloadBuilder`. Contrairement aux autres extractions
de ce chantier (classes à méthodes statiques), cette classe est injectée par le conteneur
(constructeur avec `ClientIdentityResolver`/`ClientEarningsService`/`VehicleProposalService`,
les mêmes dépendances que l'ancien contrôleur) car `dashboardPayload()` orchestrait déjà 3 services
injectés — un choix délibéré, motivé par la nature du code d'origine, pas une nouvelle convention.
`resolveActorContext()` (utilisée par `dashboardPayload()` ET directement par
`storeVehicleProposal()`) est exposée en méthode publique de ce Support pour rester appelable par
les deux. `resolveQrPayload()` (utilisée uniquement par `qrCode()`) et `resolveDashboardFilters()`
(utilisée uniquement par `index()`, délègue à `ClientEarningsService::resolveFilters()`) ne sont
pas partagées : laissées (`resolveQrPayload`) ou inlinées (`resolveDashboardFilters`) dans leur
seul contrôleur appelant respectif.

**Tests avant déplacement** : `ClientDashboardTest.php` (25 tests, dont 3 qui invoquent
`resolveQrPayload()` par Reflection sur `app(ClientDashboardController::class)` — pattern de test
existant, pas introduit par ce chantier) déjà exhaustif (200/403/redirections, périmètre
proprietaire/livreur/téléphone, proposition de véhicule avec anti-doublon, QR code par profil) —
aucun scénario manquant identifié, aucun test ajouté.

**Vérifications après extraction** :
- Import ET registration de route mis à jour ensemble dans `routes/web.php`, `vendor/bin/pint
  --test` vert (après correction automatique de l'ordre des `use` sur 2 fichiers), `route:list
  --path=client` : 8 routes `client.*` identiques (URI, noms, méthodes) ; `route:list` complet
  (537 routes, inchangé).
- Les 3 tests par Reflection mis à jour pour cibler `QrCodeClientDashboardController` (seule classe
  qui porte désormais `resolveQrPayload()`), classe et méthode toujours privées comme avant.
- 119 tests verts après déplacement (`ClientDashboardTest.php` + les tests `Api/Client`
  dépendant du même moteur partagé + `ImageServiceTest.php` + `RegistrationTest.php` +
  `RoleCumulAccessTest.php` + `LoginResponseTest.php` + `RequireActiveLivreurTest.php`,
  403 assertions).
- `npm run typecheck` (vue-tsc) : vert. `npm run lint:standards` : vert. Aucun fichier `.vue`
  touché dans ce volet (uniquement PHP + routes + doc) — pas d'ESLint ciblé nécessaire.
- Wayfinder (`php artisan wayfinder:generate --with-form`) régénéré sans erreur ;
  `resources/js/routes/client/...` (gitignoré) référence déjà les nouvelles classes.
- OpenAPI : non concerné (routes dans `routes/web.php`, hors périmètre `api_path.include: 'api'`).
  `scramble:export --api=default` réexécuté par précaution : diff inchangé par rapport au volet
  précédent de ce lot.
- 11 références obsolètes à `ClientDashboardController` (nom de classe supprimé, décrivant un
  comportement ACTUEL — distinctes des références historiques du type « extrait le 26/08/2026 de
  ClientDashboardController », volontairement laissées telles quelles ailleurs) corrigées :
  `app/Http/Middleware/PreventCachingOfDynamicResponses.php`,
  `app/Http/Controllers/Api/Client/DashboardController.php`,
  `app/Http/Controllers/Api/Client/ProfileController.php`,
  `app/Http/Controllers/Api/Client/PropositionsVehicule/{Store,Index}PropositionVehiculeController.php`,
  `app/Services/Client/{ClientEarningsService,VehicleProposalService,QrPayloadResolver}.php`
  (3 fichiers, références courantes seulement — les mentions historiques d'extraction conservées),
  `tests/Unit/ImageServiceTest.php`, `tests/Feature/Api/Client/PropositionsVehiculeControllerTest.php`,
  `tests/e2e/proposition-vehicule-flow.spec.ts`, `docs/scanner-dashboard-mobile.md`. Au passage,
  une incohérence préexistante non liée à ce chantier a été corrigée dans le même geste
  (`VehicleProposalService.php` citait encore `Api\Client\PropositionsVehiculeController`, un nom
  de classe déjà obsolète depuis le Lot 3 de ce même chantier — corrigé vers
  `Api\Client\PropositionsVehicule\StorePropositionVehiculeController`).
- Domaine **Clients entièrement clos** (21/21 contrôleurs conformes).

## Lot 9 — Depenses : Types, Import, Paramétrage (partiel — `DepenseController` lui-même restant)

### Volet 1 — `DepenseTypeImportController` (modele/analyser/confirmer)

**Périmètre** : identique en forme au pattern `SiteImportController` du Lot 4 (aucune page dédiée,
pas d'enregistrement persisté entre aperçu et confirmation, fichier ré-analysé à chaque appel) →
`app/Http/Controllers/Depenses/Types/Import/{Modele,Analyser,Confirmer}DepenseTypeImportController.php`.
Dépendance privée partagée `toResponse()` (utilisée par `analyser()` et `confirmer()`) extraite
vers `App\Support\Depenses\DepenseTypeImportAnalyseFormatter::pour()`.

**Tests avant déplacement** : `DepenseTypeImportTest.php` déjà exhaustif (19 tests : permissions
sur les 3 actions, parsing/validation variés, atomicité de `confirmer`, re-analyse d'un aperçu
client obsolète) — aucun scénario manquant identifié, aucun test ajouté. 19 tests verts sur le
code d'origine avant tout déplacement.

**Vérifications après extraction** :
- Import ET registration de route mis à jour ensemble dans `routes/web.php`, `vendor/bin/pint
  --test` vert (après correction automatique de l'ordre des `use`), `route:list
  --path=depenses/types` : 3 routes d'import identiques (URI, noms, méthodes) ; `route:list`
  complet (537 routes, inchangé).
- 19 tests verts après déplacement.

### Volet 2 — `DepenseTypeController` (CRUD + export + toggle)

**Périmètre** (7 actions) : `index/store/update/toggle/destroy/exportExcel/exportPdf` →
`app/Http/Controllers/Depenses/Types/{Index,Store,Update,Toggle,Destroy,ExportExcel,ExportPdf}DepenseTypeController.php`.
Dépendance privée partagée `filteredTypes()` (utilisée par `exportExcel()` et `exportPdf()`)
extraite vers `App\Support\Depenses\DepenseTypeFilterQuery::pour()`. `typesPourOrganisation()`
(seulement `index()`), `generateCode()` (seulement `store()`) et `filtresLabel()` (seulement
`exportPdf()`) restent des méthodes privées de leur seul contrôleur appelant — non partagées, non
extraites.

**Tests avant déplacement** : 4 scénarios manquants complétés dans `DepenseTypeTest.php`
(`toggle` et `destroy` n'avaient NI test « sans permission » NI test « autre organisation »,
contrairement à `store`/`update`, alors que `DepenseTypePolicy::update()`/`delete()` vérifient
déjà les deux — jamais testé pour ces deux actions) : `test_toggle_forbidden_sans_permission`,
`test_toggle_cannot_touch_other_org_type`, `test_destroy_forbidden_sans_permission`,
`test_destroy_cannot_touch_other_org_type`. 31 tests verts sur le code d'origine avant tout
déplacement.

**Vérifications après extraction** :
- Import ET registration de route mis à jour ensemble dans `routes/web.php`, `vendor/bin/pint
  --test` vert (après correction automatique de l'ordre des `use`), `route:list
  --path=depenses/types` : 10 routes identiques (URI, noms, méthodes) ; `route:list` complet
  (537 routes, inchangé).
- 50 tests verts après déplacement (`DepenseTypeTest.php` + `DepenseTypeImportTest.php`, les deux
  partagent le même modèle `DepenseType`).
- 5 références obsolètes à `DepenseTypeController` (nom de classe supprimé, décrivant un
  comportement ACTUEL) corrigées : `app/Services/DepenseTypes/DepenseTypeListExport.php`,
  `app/Services/ImportDepenseTypes/DepenseTypeImportParser.php` (×2),
  `app/Http/Controllers/Settings/DepenseParametrageController.php` (avant son propre traitement
  au volet 3 ci-dessous).

### Volet 3 — `Settings\DepenseParametrageController` (droits de création/validation)

**Périmètre** (2 actions) : `edit/updateDroits` →
`app/Http/Controllers/Settings/Depenses/{Edit,Update}DepenseParametrageController.php`
(`updateDroits()` renommé `__invoke()` sous `UpdateDepenseParametrageController`, cohérent avec la
convention de nommage par verbe déjà utilisée dans ce chantier plutôt que par nom de méthode
d'origine). Aucune dépendance privée partagée entre les deux actions — déplacement direct.

**Tests avant déplacement** : `DepenseParametrageTest.php` déjà exhaustif (22 tests : permissions,
isolation organisation sur `edit`, validation complète de `updateDroits` incluant le cas Admin
Entreprise) — aucun scénario manquant identifié, aucun test ajouté. 22 tests verts sur le code
d'origine avant tout déplacement.

**Vérifications après extraction** :
- Import ET registration de route mis à jour ensemble dans `routes/settings.php`, `vendor/bin/pint
  --test` vert, `route:list --path=settings/depenses` : 2 routes identiques (URI, noms,
  méthodes) ; `route:list` complet (537 routes, inchangé).
- 23 tests verts après déplacement (`DepenseParametrageTest.php` + `StockAjustementControllerTest.php`,
  qui documente un bug analogue historique).
- 3 références obsolètes à `DepenseParametrageController` corrigées :
  `app/Support/Permissions/RoleVisibility.php`,
  `app/Http/Controllers/Depenses/Types/IndexDepenseTypeController.php`,
  `tests/Feature/Settings/StockAjustementControllerTest.php`.

**Vérifications communes aux 3 volets** : `npm run typecheck` (vue-tsc) : vert. `npm run
lint:standards` : vert. Aucun fichier `.vue` touché (uniquement PHP + routes) — pas d'ESLint ciblé
nécessaire. Wayfinder régénéré sans erreur après chaque volet. OpenAPI : non concerné (routes dans
`routes/web.php`/`routes/settings.php`, hors périmètre `api_path.include: 'api'`).

**Domaine Depenses PAS encore clos** : `DepenseController` (16 actions, 1557 lignes — le plus gros
contrôleur traité à ce jour dans ce chantier, plus gros que `ProduitController` à l'origine)
reste à traiter, probablement en plusieurs sous-lots compte tenu de sa taille.

### Volet 4 — `DepenseController` (16 actions, clôture du domaine)

**Périmètre** : `index/exportCsv/suggestions/concereneDetail/vehiculeDetail/imprimer/show/create/
store/edit/update/soumettre/valider/rejeter/destroy/historique` →
`app/Http/Controllers/Depenses/{Index,ExportCsv,Suggestions,ConcerneDetail,VehiculeDetail,
Imprimer,Show,Create,Store,Edit,Update,Soumettre,Valider,Rejeter,Destroy,Historique}DepenseController.php`
(nom de classe `ConcerneDetailDepenseController` en orthographe correcte — le nom de méthode
d'origine `concereneDetail` contenait une coquille, corrigée à l'occasion de la création de la
nouvelle classe ; le nom de route `depenses.concerne-detail`, lui, était déjà correctement
orthographié et reste inchangé).

Deux groupes de dépendances privées partagées identifiés (sur les ~25 méthodes privées
d'origine) :
- `buildQuery()`/`matchPrestataireIdentite()`/`transformDepense()`/`preloadBeneficiaires()` —
  partagées par `index`/`exportCsv`/`imprimer` (les 3 actions qui listent des dépenses) → classe
  injectée `App\Support\Depenses\DepenseListingService` (constructeur avec
  `DroitCreationDepenseService`, comme l'ancien contrôleur — nécessaire pour le calcul de
  `can_valider` dans `transform()`).
- `loadTypes/loadVehicules/loadSites/loadEmployes/loadLivreurs/loadProprietaires/
  loadPrestataires/loadClients/vehiculeInfoDepuis` — partagées par `create`/`edit` → classe
  statique `App\Support\Depenses\DepenseFormOptionsLoader`.

Tout le reste (`buildProprietaireDetail`/`buildLivreurDetail`/`buildEmployeDetail`/
`buildPrestataireDetail`/`buildClientDetail`, `resolveVehiculeInfo`/`resoudreBeneficiaireLabel`,
`notifierDepenseValidee`, `buildAuditDescription`/`describeUpdate`, `defaultSiteId`) n'était
utilisé que par une seule action chacun (parfois plusieurs méthodes formant un seul groupe
cohérent, ex. les 5 `build*Detail()` de `concereneDetail`) — laissé en méthodes privées du
contrôleur mono-action correspondant, non extrait.

**Duplication préexistante non touchée** : le bloc de calcul des `sites` (admin voit tous les
sites, non-admin voit ses sites rattachés) était déjà dupliqué à l'identique entre `create()` et
`edit()` dans le contrôleur d'origine, jamais mutualisé — laissé dupliqué entre
`CreateDepenseController` et `EditDepenseController` pour ne pas mêler une déduplication non
demandée à ce refactoring pur.

**Gap de test découvert et comblé avant tout déplacement** : l'action `historique()` (endpoint
JSON listant l'audit trail d'une dépense) n'avait **aucune couverture de test**, dans aucun des 8
fichiers de test du module (150 tests au total sur les 15 autres actions). 3 tests ajoutés à
`DepenseTest.php` : `test_historique_returns_logs_with_description` (contenu JSON réel, format de
description), `test_historique_forbidden_sans_permission`, `test_historique_forbidden_for_other_org`.
153 tests verts sur le code d'origine avant tout déplacement (737 assertions).

**Vérifications après extraction** :
- Import ET registration de route mis à jour ensemble dans `routes/web.php` (`Route::resource()`
  remplacé par 7 déclarations explicites + `Route::match(['put','patch'], ...)` pour `update`,
  même schéma que les lots CRUD précédents), `vendor/bin/pint` vert (après correction automatique
  de l'ordre des `use` sur 3 fichiers), `route:list --path=depenses` : 16 routes identiques (URI,
  noms, méthodes, y compris la ligne `PUT|PATCH` combinée) ; `route:list` complet (537 routes,
  inchangé).
- 153 tests verts après déplacement (737 assertions) — résultat strictement identique à la
  baseline, sur les 8 fichiers de test du module (`DepenseTest`, `DepensePermissionTest`,
  `DepenseSiteFilterTest`, `DepenseValidationTest`, `DepenseWorkflowTest`,
  `DepenseCreationScopeTest`, `Comptabilite/ComptabilisationBloquanteTest`,
  `Notification/DepenseValideeNotificationTest`).
- `npm run typecheck` (vue-tsc) : vert. `npm run lint:standards` : vert. Aucun fichier `.vue`
  touché (uniquement PHP + routes + doc) — pas d'ESLint ciblé nécessaire. Wayfinder régénéré sans
  erreur.
- OpenAPI : non concerné (routes dans `routes/web.php`, hors périmètre `api_path.include: 'api'`).
- 8 références obsolètes à `DepenseController` (nom de classe supprimé, décrivant un comportement
  ACTUEL) corrigées : `app/Observers/DepenseObserver.php` (×2),
  `app/Http/Controllers/Comptabilite/CommissionConsultantController.php`,
  `tests/Feature/DepenseValidationTest.php`, `tests/Feature/DepenseTest.php`,
  `docs/depenses-validation.md` (×3, dont un lien markdown vers l'ancien chemin de fichier). Les
  références historiques (« extrait de DepenseController » dans les deux nouvelles classes
  Support, qui décrivent correctement l'origine de l'extraction) laissées inchangées, cohérent
  avec la convention déjà appliquée aux lots précédents.
- Domaine **Depenses entièrement clos** (28/28 contrôleurs conformes).

## Lot 10 — Divers : Contact, Communications, Dashboard staff (clôture du domaine)

**Recadrage préalable** : l'ancien inventaire annonçait 11 contrôleurs pour ce domaine (1 conforme
/ 2 renommage trivial / 6 multi-actions — déjà incohérent en interne, 1+2+6=9≠11). Une recherche
dédiée (vérification directe par groupe de routes dans `routes/web.php`/`routes/api.php`) a établi
que seuls **4** contrôleurs appartiennent réellement à Divers ; les candidats plausibles restants
appartiennent à d'autres domaines non encore traités (détail dans la section Inventaire
ci-dessus). Domaine donc beaucoup plus petit que prévu — traité intégralement dans ce seul lot.

**Périmètre** :
- `SitemapController` (1 action, déjà `__invoke()`) — déjà conforme, non touché.
- `CommunicationController::index()` (1 action) → `Divers\IndexCommunicationController`
  (renommage trivial + déplacement ; `transform()`/`purposeLabel()`, `public static` mais
  appelés uniquement en interne via `self::`, conservés tels quels dans la nouvelle classe).
- `DashboardController::index()` (1 action, tableau de bord staff — statistiques factures/CA) →
  `Divers\IndexDashboardController` (renommage trivial + déplacement ; `dateRangeForPeriode()`
  reste une méthode privée, utilisée uniquement par cette action).
- `ContactController` (`markRead`/`unreadCount`, 2 actions) →
  `Divers\{MarkRead,UnreadCount}ContactController` (aucune dépendance privée partagée).

**Gap de test découvert et comblé avant tout déplacement** : `unreadCount()` n'avait **aucun**
test (`ContactTest.php` ne couvrait que `markRead`). Ajouté : `test_unread_count_scopes_to_own_organization`
(vérifie le comptage et l'isolation organisationnelle, déjà correctement implémentée côté
requête). 2 tests verts sur le code d'origine avant tout déplacement.

**Point hors périmètre signalé, non corrigé** : `markRead()` ne vérifie **aucune** appartenance
organisationnelle du `ContactMessage` reçu (pas de policy, pas de `abort_unless` sur
`organization_id`) — un compte staff authentifié de n'importe quelle organisation peut a priori
marquer comme lu un message de contact appartenant à une AUTRE organisation, simple faille
d'isolation multi-tenant (cf. règle CLAUDE.md § sécurité/multi-tenant). Ce comportement est
antérieur à ce chantier, non introduit ni aggravé par le déplacement (code copié à l'identique) —
corriger cette absence de garde serait un changement de comportement, hors périmètre d'un
refactoring pur ; signalé ici plutôt que corrigé silencieusement, conformément à la règle CLAUDE.md
§ 8 sur les bugs sans rapport avec le chantier en cours.

**Vérifications après extraction** :
- Import ET registration de route mis à jour ensemble dans `routes/web.php`, `vendor/bin/pint`
  vert (après correction automatique de l'ordre des `use`), `route:list` ciblé sur
  `contact-messages`/`communications`/`dashboard` : 5 routes identiques (URI, noms, méthodes) ;
  `route:list` complet (537 routes, inchangé).
- 12 tests verts après déplacement (`ContactTest` + `CommunicationControllerTest` +
  `DashboardTest`, 80 assertions).
- `npm run typecheck` (vue-tsc) : vert. `npm run lint:standards` : vert. Aucun fichier `.vue`
  touché (uniquement PHP + routes + doc) — pas d'ESLint ciblé nécessaire. Wayfinder régénéré sans
  erreur.
- OpenAPI : non concerné (routes dans `routes/web.php`, hors périmètre `api_path.include: 'api'`).
- 5 références obsolètes corrigées (décrivant un comportement ACTUEL) :
  `docs/communications.md` (×2, `IndexCommunicationController`),
  `tests/Feature/CommunicationControllerTest.php`,
  `docs/scanner-dashboard-mobile.md`,
  `database/seeders/Organizations/FelloDemo/FelloDemoSalesSeeder.php` (les deux derniers pour
  `IndexDashboardController`). Les références à `Api\Client\DashboardController`/
  `Api\Mobile\ContactController`/`Api\Public\ContactController` (classes distinctes, mêmes noms
  courts, namespaces différents) volontairement laissées intactes — vérifiées non concernées.
- Domaine **Divers entièrement clos** (5/5 contrôleurs conformes : `SitemapController` +
  `IndexCommunicationController` + `IndexDashboardController` + `MarkReadContactController` +
  `UnreadCountContactController`).

## Lot 11 — Produits & Stock : `ProduitController` lui-même (pilote, suite et fin de son volet)

**Recadrage préalable** : une vérification directe par groupe de routes (`module:PRODUITS` dans
`routes/web.php`) a établi la composition réelle du domaine Produits & Stock : 19 contrôleurs (pas
14), dont `StockController` et `MediaController` — précédemment mal comptés sous « Divers » dans
un inventaire antérieur (cf. correction du 13/09/2026 ci-dessus). Le domaine reste donc **ouvert**
après ce lot : `CategorieController`/`ProduitTypeController`/`OptionCatalogueController`/
`ImportProduitsController`/`MediaController` (5 contrôleurs multi-actions) restent à traiter, et
`StockController` (1 action, pas encore `__invoke()`) reste en renommage trivial.

**Périmètre** (10 actions du pilote `ProduitController`, restées non traitées depuis le Lot 1) :
`index/create/store/show/historique/edit/update/archiver/destroy/ajusterStock` →
`app/Http/Controllers/Produits/{Index,Create,Store,Show,Historique,Edit,Update,Archiver,Destroy,
AjusterStock}ProduitController.php`. C'est, avec `DepenseController` (Lot 9), l'un des deux
contrôleurs les plus volumineux traités dans ce chantier (~1090 lignes, ~25 méthodes privées
avant extraction).

Quatre groupes de dépendances privées partagées identifiés :
- `typesOptions()`/`fournisseursOptions()`/`limitesCatalogue()` — partagées par
  create/edit/show (à des degrés divers) → classe statique
  `App\Support\Produits\ProduitFormOptions`.
- `validerFormulaire()` — partagée par store/update → classe statique
  `App\Support\Produits\ProduitFormValidator`.
- `produitSnapshot()`/`produitDiff()` — partagées par store/update/destroy (snapshot) et update
  (diff, pour ne journaliser que les champs réellement modifiés) → classe statique
  `App\Support\Produits\ProduitAuditSnapshot`.
- `loadModifications()` — partagée par show/historique → classe statique
  `App\Support\Produits\ProduitModificationsHistory`.

**Duplication préexistante non touchée** : le calcul des `sites` autorisés (admin voit tous les
sites, non-admin voit ses sites rattachés) était déjà dupliqué à l'identique entre `create()` et
`edit()` avant ce chantier — laissé dupliqué entre `CreateProduitController` et
`EditProduitController`, même choix que pour `DepenseController` au Lot 9.

**Gaps de test découverts et comblés avant tout déplacement** — `ProduitTest.php` (97 tests
existants, très large couverture fonctionnelle) n'avait **aucun** test de refus de permission ni
d'isolation organisationnelle pour `create`/`store`/`edit`/`update`/`destroy`, et `archiver()`
n'avait **aucun** test, de quelque nature que ce soit (seules les actions Variantes du pilote,
ajoutées au Lot 1, avaient ce type de couverture). 11 tests ajoutés :
`test_create_refuse_utilisateur_sans_permission_meme_organisation`,
`test_store_refuse_utilisateur_sans_permission_meme_organisation`,
`test_show_refuse_utilisateur_sans_permission_meme_organisation`,
`test_edit_returns_403_for_other_organization`,
`test_edit_refuse_utilisateur_sans_permission_meme_organisation`,
`test_update_returns_403_for_other_organization`,
`test_update_refuse_utilisateur_sans_permission_meme_organisation`,
`test_destroy_refuse_utilisateur_sans_permission_meme_organisation`,
`test_archiver_archives_produit_and_redirects`, `test_archiver_returns_403_for_other_organization`,
`test_archiver_refuse_utilisateur_sans_permission_meme_organisation`. 108 tests verts sur le code
d'origine avant tout déplacement (254 assertions).

**Vérifications après extraction** :
- Import ET registration de route mis à jour ensemble dans `routes/web.php` (`Route::resource()`
  remplacé par 7 déclarations explicites + `Route::match(['put','patch'], ...)` pour `update`,
  même schéma que les CRUD précédents), `vendor/bin/pint` vert (après correction automatique de
  l'ordre des `use` sur 1 fichier), `route:list --path=produits` : 10 routes `produits.*`
  identiques (URI, noms, méthodes, y compris la ligne `PUT|PATCH` combinée) ; `route:list` complet
  (537 routes, inchangé).
- 173 tests verts après déplacement (660 assertions ; `ProduitTest` + `ProduitAjustementScopeTest`
  + `ProduitMediaTest` + `ProduitSeuilAlerteSiteTest` + `StockIndexTest` + `StockIsolationMultiSiteTest`)
  — résultat strictement identique à la baseline (108 + 14 + 9 + 19 + 11 + 12 = 173 tests).
- `npm run typecheck` (vue-tsc) : vert. `npx eslint` sur les 6 fichiers `.vue` touchés
  (commentaires uniquement, aucune logique modifiée) : vert. `npm run lint:standards` : vert.
  Wayfinder régénéré sans erreur.
- OpenAPI : non concerné (routes dans `routes/web.php`, hors périmètre `api_path.include: 'api'` —
  `Api\Produits\ProduitController`, contrôleur API distinct au nom identique, non touché).
- 13 références obsolètes à `ProduitController` (nom de classe supprimé, décrivant un comportement
  ACTUEL — distinctes des références historiques « extrait de ProduitController » dans les 4
  nouvelles classes Support et dans `ProduitVarianteOptionsFormatter`/`ProduitTest.php` du Lot 1,
  volontairement laissées telles quelles) corrigées : `app/Services/MouvementStockService.php`
  (×2), `app/Services/MouvementStockMotifService.php`, `app/Services/DroitAjustementStockService.php`,
  `app/Services/ImportProduits/ImportProduitsParser.php` (×2), `app/Models/Produit.php`,
  `app/Http/Controllers/Produits/Variantes/IndexProduitVarianteController.php` (×2),
  `docs/stock-alertes.md` (×4), `resources/js/pages/Produits/{Create,Edit}.vue`,
  `resources/js/pages/Produits/partials/{AjusterStockModal(×3),HistoriqueModal,
  VariantesGroupees,ProduitForm(×2)}.vue`, `tests/Feature/ProduitSeuilAlerteSiteTest.php` (×2),
  `tests/Feature/StockIsolationMultiSiteTest.php`. Une référence à `Api\Produits\ProduitController`
  dans `app/Http/Resources/Api/ProduitResource.php` (« côté API », explicitement désambiguïsée par
  le commentaire lui-même) vérifiée non concernée et laissée intacte.
- Domaine **Produits & Stock toujours ouvert** : 13/19 contrôleurs conformes désormais (pilote
  Variantes + `ProduitController` complets), `StockController` en renommage trivial, et
  `CategorieController`/`ProduitTypeController`/`OptionCatalogueController`/
  `ImportProduitsController`/`MediaController` (5 contrôleurs) restent multi-actions.

## Lot 12 — Produits & Stock : `CategorieController`

**Périmètre** (5 actions) : `index/store/update/toggle/destroy` →
`app/Http/Controllers/Produits/Categories/{Index,Store,Update,Toggle,Destroy}CategorieController.php`.
Aucune dépendance privée partagée entre les 5 actions d'origine — déplacement direct, sans
extraction Support.

**Gaps de test découverts et comblés avant tout déplacement** : `store`/`toggle`/`destroy`
n'avaient aucun test de refus de permission (seul `index` l'avait), et `toggle` n'avait pas non
plus de test cross-organisation. 5 tests ajoutés :
`test_store_refuse_utilisateur_sans_permission_meme_organisation`,
`test_update_refuse_utilisateur_sans_permission_meme_organisation`,
`test_toggle_returns_403_for_other_organization`,
`test_toggle_refuse_utilisateur_sans_permission_meme_organisation`,
`test_destroy_refuse_utilisateur_sans_permission_meme_organisation`. 26 tests verts sur le code
d'origine avant tout déplacement (49 assertions).

**Vérifications après extraction** :
- Import ET registration de route mis à jour ensemble dans `routes/web.php`, `vendor/bin/pint`
  vert (après correction automatique de l'ordre des `use`), `route:list --path=produits/categories`
  : 5 routes identiques (URI, noms, méthodes) ; `route:list` complet (537 routes, inchangé).
- 26 tests verts après déplacement (`CategorieTest.php`, 49 assertions) — résultat strictement
  identique à la baseline.
- `npm run typecheck` (vue-tsc) : vert. `npx eslint` sur les 2 fichiers `.vue` touchés
  (commentaires uniquement) : vert. `npm run lint:standards` : vert. Wayfinder régénéré sans
  erreur.
- OpenAPI : non concerné (routes dans `routes/web.php`, hors périmètre `api_path.include: 'api'`).
- 4 références obsolètes à `CategorieController` (nom de classe supprimé, décrivant un
  comportement ACTUEL) corrigées : `app/Http/Controllers/VehiculeController.php`,
  `app/Http/Controllers/FonctionRhController.php`,
  `resources/js/pages/Produits/partials/CreateCategorieModal.vue`,
  `resources/js/pages/Produits/Categories/Index.vue`. Un commentaire de `routes/web.php`
  (`employes.*`) qui citait `CategorieController` comme exemple générique du motif « pas de
  create()/edit() dédiés, création en popup » reformulé pour référencer les routes
  `produits/categories` plutôt qu'une classe qui n'existe plus telle quelle.
- Domaine **Produits & Stock toujours ouvert** : 18/23 contrôleurs conformes (le total passe de
  19 à 23 : `CategorieController`, 1 fichier, remplacé par 5 contrôleurs mono-action),
  `StockController` en renommage trivial, et
  `ProduitTypeController`/`OptionCatalogueController`/`ImportProduitsController`/`MediaController`
  (4 contrôleurs) restent multi-actions.

## Lot 13 — Produits & Stock : `ProduitTypeController`

**Périmètre** (5 actions) : `index/store/update/toggle/destroy` →
`app/Http/Controllers/Produits/Types/{Index,Store,Update,Toggle,Destroy}ProduitTypeController.php`.
Aucune dépendance privée partagée entre les 5 actions d'origine — déplacement direct. La constante
privée `CHAMPS_STRUCTURELS` (utilisée uniquement par `update()`, jamais par les 4 autres actions)
reste une constante privée de `UpdateProduitTypeController`, non extraite.

**Gaps de test découverts et comblés avant tout déplacement** : aucune des 5 actions n'avait de
test de refus de permission, et seule `update` avait un test cross-organisation (`toggle`/`destroy`
n'en avaient aucun). 7 tests ajoutés : `test_index_returns_403_without_permission`,
`test_store_refuse_utilisateur_sans_permission_meme_organisation`,
`test_update_refuse_utilisateur_sans_permission_meme_organisation`,
`test_destroy_returns_403_for_other_organization`,
`test_destroy_refuse_utilisateur_sans_permission_meme_organisation`,
`test_toggle_returns_403_for_other_organization`,
`test_toggle_refuse_utilisateur_sans_permission_meme_organisation`. 23 tests verts sur le code
d'origine avant tout déplacement (43 assertions).

**Vérifications après extraction** :
- Import ET registration de route mis à jour ensemble dans `routes/web.php`, `vendor/bin/pint`
  vert (après correction automatique de l'ordre des `use`), `route:list --path=produits/types` :
  5 routes identiques (URI, noms, méthodes) ; `route:list` complet (537 routes, inchangé).
- 23 tests verts après déplacement (`ProduitTypeTest.php`, 43 assertions) — résultat strictement
  identique à la baseline.
- `npm run typecheck` (vue-tsc) : vert. `npx eslint` sur le fichier `.vue` touché (commentaire
  uniquement) : vert. `npm run lint:standards` : vert. Wayfinder régénéré sans erreur.
- OpenAPI : non concerné (routes dans `routes/web.php`, hors périmètre `api_path.include: 'api'`).
- 4 références obsolètes à `ProduitTypeController` (nom de classe supprimé, décrivant un
  comportement ACTUEL) corrigées : `app/Support/Produits/ProduitFormOptions.php`,
  `app/Services/ProduitService.php`, `database/migrations/0001_01_01_000018_create_produits_table.php`
  (commentaire seul, aucun changement de schéma), `resources/js/pages/Produits/Types/Index.vue`.
- **Correction du bilan du Lot 12** : le total du domaine Produits & Stock avait été laissé à 19
  après le Lot 12 alors qu'il aurait dû passer à 23 (`CategorieController`, 1 fichier, remplacé par
  5 contrôleurs mono-action) — repéré et corrigé en préparant ce lot (cf. section Inventaire et
  clôture du Lot 12 ci-dessus, désormais à jour).
- Domaine **Produits & Stock toujours ouvert** : 23/27 contrôleurs conformes (le total passe de
  23 à 27 : `ProduitTypeController`, 1 fichier, remplacé par 5 contrôleurs mono-action),
  `StockController` en renommage trivial, et
  `OptionCatalogueController`/`ImportProduitsController`/`MediaController` (3 contrôleurs)
  restent multi-actions.

## Lot 14 — Produits & Stock : `OptionCatalogueController`

**Périmètre** (6 actions) : `index/store/update/destroy/storeValeur/destroyValeur` →
`app/Http/Controllers/Produits/Options/{Index,Store,Update,Destroy,StoreValeur,DestroyValeur}
OptionCatalogueController.php`. Aucune dépendance privée partagée entre les 6 actions d'origine —
déplacement direct.

**Gaps de test découverts et comblés avant tout déplacement** : `store`/`update`/`destroy`
n'avaient aucun test de refus de permission, `storeValeur` avait la vérification cross-organisation
mais pas la permission, et `destroyValeur` n'avait **aucune** des deux. 6 tests ajoutés :
`test_store_refuse_utilisateur_sans_permission_meme_organisation`,
`test_update_refuse_utilisateur_sans_permission_meme_organisation`,
`test_destroy_refuse_utilisateur_sans_permission_meme_organisation`,
`test_store_valeur_refuse_utilisateur_sans_permission_meme_organisation`,
`test_destroy_valeur_returns_403_for_other_organization`,
`test_destroy_valeur_refuse_utilisateur_sans_permission_meme_organisation`. 21 tests verts sur le
code d'origine avant tout déplacement (33 assertions).

**Vérifications après extraction** :
- Import ET registration de route mis à jour ensemble dans `routes/web.php`, `vendor/bin/pint`
  vert (après correction automatique de l'ordre des `use`), `route:list --path=produits/options` :
  6 routes identiques (URI, noms, méthodes) ; `route:list` complet (537 routes, inchangé).
- 21 tests verts après déplacement (`OptionCatalogueTest.php`, 33 assertions) — résultat
  strictement identique à la baseline.
- `npm run typecheck` (vue-tsc) : vert. `npx eslint` sur le fichier `.vue` touché (commentaire
  uniquement) : vert. `npm run lint:standards` : vert. Wayfinder régénéré sans erreur.
- OpenAPI : non concerné (routes dans `routes/web.php`, hors périmètre `api_path.include: 'api'`).
- 3 références obsolètes à `OptionCatalogueController` (nom de classe supprimé, décrivant un
  comportement ACTUEL) corrigées :
  `database/migrations/0001_01_01_000017_6_create_option_catalogues_table.php` (commentaire seul,
  aucun changement de schéma), `database/seeders/OptionCatalogueDefaultSeeder.php`,
  `resources/js/pages/Produits/partials/CreateOptionCatalogueModal.vue`.
- **Correction arithmétique globale** : en recomptant directement la somme des contrôleurs créés
  lot par lot (3+6+4+3+2+7+10+3+10+8+3+7+2+16+4+10+5+5+6 = 114), une dérive de +2 a été repérée
  dans les bilans « nouveaux contrôleurs » cumulés des lots précédents — corrigée dans la section
  Inventaire ci-dessus (114 nouveaux au total, pas 112 ; 168 conformes, pas 167).
- Domaine **Produits & Stock toujours ouvert** : 29/32 contrôleurs conformes (le total passe de
  27 à 32 : `OptionCatalogueController`, 1 fichier, remplacé par 6 contrôleurs mono-action),
  `StockController` en renommage trivial, et `ImportProduitsController`/`MediaController`
  (2 contrôleurs) restent multi-actions.

## Lot 15 — Produits & Stock : `ImportProduitsController`

**Périmètre** (8 actions) : `index/create/store/show/confirm/retry/template/reprise` →
`app/Http/Controllers/Produits/Imports/{Index,Create,Store,Show,Confirm,Retry,Template,Reprise}
ImportProduitsController.php`. Namespace `Produits\Imports` (routes `produits/imports/*`), pages
Inertia inchangées (`ImportsProduits/Index`/`Create`/`Show` — dossier frontend historiquement
distinct du dossier backend, non renommé ici, hors périmètre d'un refactoring pur).

Deux groupes de dépendances privées partagées identifiés :
- `toRow()`/`toDetail()` — partagées par `index()` et `show()` (`toDetail()` réutilise `toRow()`
  tel quel) → classe statique `App\Support\Produits\Imports\ImportProduitsFormatter`.
- `analyser()`/`traiter()`/`messageDeStatut()`/`messageSucces()`/`compteurs()` — `analyser()` est
  utilisée uniquement par `store()` ; `traiter()`/`messageDeStatut()` sont partagées par
  `confirm()`/`retry()` (mêmes transitions de statut, confirmation initiale ou relance) ;
  `compteurs()`/`messageSucces()` sont des sous-helpers utilisés par les précédentes. L'ensemble
  formant un seul groupe cohérent (transitions de statut d'un import), extrait en bloc vers
  `App\Support\Produits\Imports\ImportProduitsStatusProcessor` plutôt que dispersé en plusieurs
  petites classes.

**Gap de test découvert et comblé avant tout déplacement** : `index()` n'avait **aucun** test
(aucune mention dans les 26 tests de `ImportProduitsTest.php` ni les 9 de
`ImportProduitsExportTest.php`), et `show()` n'était référencée qu'indirectement (comme cible de
redirection après `store()`/`confirm()`), jamais testée directement avec ses propres assertions de
statut/permission/isolation. 5 tests ajoutés : `test_index_returns_200_for_authorized_user`,
`test_index_returns_403_without_permission`,
`test_index_ne_retourne_que_les_imports_de_lorganisation`, `test_show_returns_200_for_authorized_user`,
`test_show_returns_403_for_other_organization`. 41 tests verts sur le code d'origine avant tout
déplacement (145 assertions, `ImportProduitsTest.php` + `ImportProduitsExportTest.php`).

**Vérifications après extraction** :
- Import ET registration de route mis à jour ensemble dans `routes/web.php` (ordre des 8 routes
  strictement préservé, notamment `modele`/`nouveau` déclarées avant le wildcard
  `{importProduits}`), `vendor/bin/pint` vert (après correction automatique de l'ordre des `use`),
  `route:list --path=produits/imports` : 8 routes identiques (URI, noms, méthodes) ; `route:list`
  complet (537 routes, inchangé).
- 41 tests verts après déplacement (145 assertions) — résultat strictement identique à la
  baseline.
- `npm run typecheck` (vue-tsc) : vert. `npm run lint:standards` : vert. Aucun fichier `.vue`
  touché (uniquement PHP + routes) — pas d'ESLint ciblé nécessaire. Wayfinder régénéré sans
  erreur.
- OpenAPI : non concerné (routes dans `routes/web.php`, hors périmètre `api_path.include: 'api'`).
- 4 références obsolètes à `ImportProduitsController` (nom de classe supprimé, décrivant un
  comportement ACTUEL) corrigées : `app/Http/Controllers/ImportVehiculesMajController.php`,
  `app/Services/ImportProduits/ImportProduitsTemplateExport.php`,
  `app/Services/ImportProduits/ImportProduitsRepriseExport.php`,
  `app/Services/ImportProduits/ImportProduitsReferencesSheetExport.php`.
- Domaine **Produits & Stock toujours ouvert** : 37/39 contrôleurs conformes (le total passe de
  32 à 39 : `ImportProduitsController`, 1 fichier, remplacé par 8 contrôleurs mono-action),
  `StockController` en renommage trivial, et `MediaController` (1 contrôleur, dernier du domaine)
  reste multi-actions.

## Lot 16 — Produits & Stock : `MediaController` + `StockController` (clôture du domaine)

### Volet 1 — `MediaController` (galerie photo produit)

**Périmètre** (5 actions) : `store/definirPrincipale/reordonner/destroy/assignerVariantes` →
`app/Http/Controllers/Produits/Medias/{Store,DefinirPrincipale,Reordonner,Destroy,
AssignerVariantes}MediaController.php`. Aucune dépendance privée partagée entre les 5 actions
d'origine — déplacement direct. Les 5 actions partagent toutes littéralement
`$this->authorize('update', $produit)` (même ability `ProduitPolicy::update`, pas de policy
dédiée à la galerie).

**Gaps de test découverts et comblés avant tout déplacement** : `assignerVariantes` n'avait
**aucun** test dans `ProduitMediaTest.php` (couvert séparément et déjà exhaustivement dans
`VarianteMediaTest.php`, constaté après recherche), et `definirPrincipale`/`reordonner`
n'avaient pas de test cross-organisation (3 des 5 actions l'avaient déjà : store/destroy/
assignerVariantes). 3 tests ajoutés dans `VarianteMediaTest.php` :
`test_definir_principale_returns_403_for_other_organization`,
`test_reordonner_returns_403_for_other_organization`, et un test représentatif de refus de
permission (`test_store_media_refuse_utilisateur_sans_permission_meme_organisation` — les 5
actions partageant la même ability déjà testée en profondeur dans `ProduitTest.php`, un seul test
représentatif suffit à confirmer le branchement plutôt que de dupliquer cinq fois la même
vérification). 26 tests verts sur le code d'origine avant tout déplacement (51 assertions,
`ProduitMediaTest.php` + `VarianteMediaTest.php`).

**Vérifications après extraction** :
- Import ET registration de route mis à jour ensemble dans `routes/web.php`, `vendor/bin/pint`
  vert (après correction automatique de l'ordre des `use`), `route:list` ciblé sur
  `produits/{produit}/medias*` : 5 routes identiques (URI, noms, méthodes) ; `route:list` complet
  (537 routes, inchangé).
- 26 tests verts après déplacement (51 assertions) — résultat strictement identique à la
  baseline.
- 1 référence obsolète à `MediaController` (nom de classe supprimé, décrivant un comportement
  ACTUEL) corrigée : `tests/Feature/VarianteMediaTest.php`.

### Volet 2 — `StockController` (renommage trivial)

**Périmètre** (1 action) : `index` → `app/Http/Controllers/Produits/Stock/IndexStockController.php`
(renommage `__invoke()` + déplacement, code copié à l'identique — 5 méthodes privées
(`stockQuery`/`enrichirLignes`/`sitesConsultables`/`libelleVariante`/`siteIdsFiltres`/
`premiereValeur`), toutes utilisées uniquement par cette seule action, aucune extraction
nécessaire).

**Tests avant déplacement** : `StockIndexTest.php` (11 tests) + `StockIsolationMultiSiteTest.php`
(12 tests) déjà exhaustifs — 23 tests verts sur le code d'origine avant tout déplacement
(248 assertions), aucun ajout nécessaire.

**Vérifications après extraction** :
- Import ET registration de route mis à jour ensemble dans `routes/web.php`, `vendor/bin/pint`
  vert (après correction automatique de l'ordre des `use`), `route:list --path=produits/stock` :
  1 route identique (URI, nom, méthode) ; `route:list` complet (537 routes, inchangé).
- 23 tests verts après déplacement (248 assertions) — résultat strictement identique à la
  baseline.
- 4 références obsolètes à `StockController` (nom de classe supprimé, décrivant un comportement
  ACTUEL) corrigées : `app/Services/StockReservationService.php`,
  `app/Services/StockStatutService.php`, `tests/e2e/produit-voir-le-stock.spec.ts`,
  `docs/stock-alertes.md` (×2).

**Vérifications communes aux 2 volets** : `npm run typecheck` (vue-tsc) : vert. `npm run
lint:standards` : vert. Aucun fichier `.vue` touché (uniquement PHP + routes + doc/test) — pas
d'ESLint ciblé nécessaire. Wayfinder régénéré sans erreur après chaque volet. OpenAPI : non
concerné (routes dans `routes/web.php`, hors périmètre `api_path.include: 'api'`).

- Domaine **Produits & Stock entièrement clos** (43/43 contrôleurs conformes : pilote Variantes +
  `ProduitController` + `CategorieController` + `ProduitTypeController` +
  `OptionCatalogueController` + `ImportProduitsController` + `MediaController` +
  `StockController` — 8ᵉ domaine clos de ce chantier, et le seul jusqu'ici où le pilote initial
  (Lot 1) a fini par se recombiner avec tous les lots suivants du même domaine).

## Lot 17 — Auth & Compte : `Settings\PasswordController` (ouverture du domaine)

**Périmètre** (2 actions) : `edit/update` →
`app/Http/Controllers/Settings/Password/{Edit,Update}PasswordController.php` (`__invoke()`).
Aucune dépendance privée partagée entre les 2 actions d'origine (39 lignes au total, pas de
constructeur) — déplacement direct, aucune extraction Support nécessaire.

**Tests avant déplacement** : `tests/Feature/Settings/PasswordUpdateTest.php` (3 tests : affichage
de la page, mise à jour réussie, mot de passe actuel incorrect refusé) déjà exhaustif pour ce
contrôleur — pas de gap de permission/cross-organisation applicable ici : l'action ne porte que sur
`$request->user()` (l'utilisateur courant), sans donnée d'organisation ni permission dédiée à
vérifier. 3 tests verts sur le code d'origine avant tout déplacement (9 assertions).

**Vérifications après extraction** :
- Import ET registration de route mis à jour ensemble dans `routes/settings.php`,
  `vendor/bin/pint` vert, `route:list` ciblé sur `password` : les 2 routes `settings/password`
  (GET `user-password.edit`, PUT `user-password.update`) résolvent vers les nouveaux contrôleurs,
  identiques par ailleurs (URI, nom, méthode, middleware `throttle:6,1` sur `update`) ; `route:list`
  complet (541 routes, inchangé par rapport à avant ce lot).
- 3 tests verts après déplacement (9 assertions) — résultat strictement identique à la baseline.
  Suite complète `tests/Feature/Settings/` (114 tests, 534 assertions) également verte, sans
  régression sur les contrôleurs voisins du même dossier de routes.
- `npm run` typecheck (`vue-tsc --noEmit`) : vert, aucune erreur liée à Password. Wayfinder
  régénéré (`php artisan wayfinder:generate --with-form`) sans erreur — nouveaux fichiers
  `resources/js/actions/App/Http/Controllers/Settings/Password/{Edit,Update}PasswordController.ts`.
- OpenAPI : non concerné (routes dans `routes/settings.php`, hors périmètre
  `api_path.include: 'api'`).
- 2 références obsolètes à `Settings\PasswordController` (nom de classe supprimé, décrivant un
  comportement ACTUEL) corrigées : `resources/js/pages/settings/Password.vue` (import Wayfinder +
  usage `.form()`). Les autres occurrences du mot « PasswordController » trouvées par le balayage
  (`App\Providers\OpenApiServiceProvider`, `routes/api.php`,
  `App\Http\Controllers\Api\Mobile\ChangePasswordController`) désignent une classe homonyme mais
  distincte (`Api\Mobile\ChangePasswordController`), non renommée, laissées intactes.

- Domaine **Auth & Compte ouvert** (4/14 contrôleurs conformes après ce lot — le total passe de 13
  à 14, `Settings\PasswordController` 1 fichier remplacé par 2 contrôleurs mono-action). Reste à
  traiter, du plus petit au plus gros : `Auth\ForcePasswordChangeController`, `AccountController`,
  `UserInvitationController`, `Settings\ProfileController`, `InstallWizardController`,
  `Auth\AcceptInvitationController`, `RoleController`, `UserController`, puis les 2 renommages
  triviaux du domaine.

## Lot 18 — Auth & Compte : `Auth\ForcePasswordChangeController`

**Périmètre** (2 actions) : `show/update` →
`app/Http/Controllers/Auth/ForcePasswordChange/{Show,Update}ForcePasswordChangeController.php`
(`__invoke()`). Aucune dépendance privée partagée entre les 2 actions d'origine — déplacement
direct. Le trait Fortify `PasswordValidationRules` (fournit `passwordRules()`) n'est utilisé que
par `update()` — conservé uniquement sur `UpdateForcePasswordChangeController`, retiré de
`ShowForcePasswordChangeController` (qui ne l'utilisait pas). Le docblock de classe décrivant la
règle métier (pas de "mot de passe actuel" requis contrairement au changement volontaire depuis les
Réglages, cf. `EnsurePasswordIsNotExpired`) déplacé sur `ShowForcePasswordChangeController`, seule
des deux actions à porter l'affichage de la page.

**Tests avant déplacement** : `tests/Feature/ForcePasswordChangeTest.php` (8 tests) déjà exhaustif
(redirections, 200/403, succès/échec de mise à jour, flag `must_change_password`) — pas de gap de
permission/cross-organisation applicable : l'action ne porte que sur `$request->user()` courant,
sans donnée d'organisation ni permission dédiée à vérifier (comme au Lot 17). 8 tests verts sur le
code d'origine avant tout déplacement (15 assertions).

**Vérifications après extraction** :
- Import ET registration de route mis à jour ensemble dans `routes/web.php`, `vendor/bin/pint`
  vert, `route:list` ciblé sur `force-change` : les 2 routes `password/force-change` (GET
  `password.force-change`, POST `password.force-change.update`) résolvent vers les nouveaux
  contrôleurs, identiques par ailleurs (URI, nom, méthode, middleware `auth`+`account.active`) ;
  `route:list` complet (541 routes, inchangé).
- 8 tests verts après déplacement (15 assertions) — résultat strictement identique à la baseline.
  Régression élargie (`ForcePasswordChangeTest` + `InstallWizardTest` + `InstallAppTest` +
  `Security\InertiaResponseSecurityTest`, tests touchant le même parcours d'installation/connexion) :
  76 tests verts, 509 assertions.
- `npm run` typecheck (`vue-tsc --noEmit`) : vert. Wayfinder régénéré
  (`php artisan wayfinder:generate --with-form`) — déjà auto-régénéré correctement par le watcher
  Vite au moment de la modification de `routes/web.php` (nouveaux fichiers
  `resources/js/actions/App/Http/Controllers/Auth/ForcePasswordChange/{Show,Update}
  ForcePasswordChangeController.ts`, corrects), régénération manuelle confirmée idempotente. Aucun
  fichier `.vue` à modifier : `resources/js/pages/auth/ForcePasswordChange.vue` poste vers l'URL
  littérale `/password/force-change`, jamais vers un import Wayfinder nommé par classe.
- OpenAPI : non concerné (routes dans `routes/web.php`, hors périmètre `api_path.include: 'api'`).
- 2 références obsolètes à `ForcePasswordChangeController` (nom de classe supprimé, décrivant un
  comportement ACTUEL) corrigées : `app/Support/AuthRedirects.php` (docblock),
  `tests/Feature/ForcePasswordChangeTest.php` (docblock de classe).

- Domaine **Auth & Compte** : 6/15 contrôleurs conformes après ce lot (le total passe de 14 à 15,
  `Auth\ForcePasswordChangeController` 1 fichier remplacé par 2 contrôleurs mono-action). Reste à
  traiter, du plus petit au plus gros : `AccountController`, `UserInvitationController`,
  `Settings\ProfileController`, `InstallWizardController`, `Auth\AcceptInvitationController`,
  `RoleController`, `UserController`, puis les 2 renommages triviaux du domaine.

## Lot 19 — Auth & Compte : `AccountController`

**Périmètre** (2 actions) : `index/toggleActive` →
`app/Http/Controllers/Account/{Index,ToggleActive}AccountController.php` (`__invoke()`). Aucune
dépendance privée partagée entre les 2 actions d'origine — déplacement direct. `index()` conserve
sa dépendance croisée volontaire vers `app(UserController::class)->indexProps($authUser)`
(délégation vers la liste organisation-scopée existante pour tout acteur non super_admin, cf.
docblock de la classe) — `UserController` n'étant pas encore traité dans ce chantier (dernier
contrôleur du domaine par priorité, le plus gros), cet appel reste inchangé et fonctionnel tel
quel ; à ré-examiner uniquement quand `UserController` sera lui-même scindé.

**Gap de test découvert et comblé avant tout déplacement** : `AccountController` n'avait **aucun**
test, ni sur `index()` ni sur `toggleActive()` — un point d'entrée sensible (console plateforme
multi-organisation pour super_admin, activation/désactivation de comptes). 9 tests créés dans
`tests/Feature/AccountControllerTest.php` (permissions viewAny/`users.read`, module UTILISATEURS
désactivé → 403, non-authentifié → redirect login, accès multi-organisation réservé au
super_admin, toggle réussi, toggle refusé pour un non-super_admin, toggle refusé sur son propre
compte). 9 tests verts sur le code d'origine avant tout déplacement (16 assertions).

**Vérifications après extraction** :
- Import ET registration de route mis à jour ensemble dans `routes/web.php`, `vendor/bin/pint`
  vert, `route:list` ciblé sur `comptes` : les 2 routes `backoffice/comptes*` (GET
  `comptes.index`, PATCH `comptes.toggle-active`) résolvent vers les nouveaux contrôleurs,
  identiques par ailleurs (URI, nom, méthode, middleware du groupe backoffice inchangé) ;
  `route:list` complet (541 routes, inchangé).
- 9 tests verts après déplacement (16 assertions) — résultat strictement identique à la baseline.
  Régression élargie avec `UserControllerTest` (dépendance croisée `indexProps()`) : 49 tests
  verts, 98 assertions, aucune régression sur le contrôleur voisin.
- `npm run` typecheck (`vue-tsc --noEmit`) : vert. Wayfinder régénéré sans erreur, aucun fichier
  `.vue` à modifier : `Accounts/Index.vue` poste vers l'URL littérale
  `/backoffice/comptes/{id}/toggle-active`, jamais vers un import Wayfinder nommé par classe.
- OpenAPI : non concerné (routes dans `routes/web.php`, hors périmètre `api_path.include: 'api'`).
- 2 références obsolètes à `AccountController` (nom de classe supprimé, décrivant un comportement
  ACTUEL) corrigées : `app/Http/Controllers/UserController.php` (docblock de `indexProps()`),
  `resources/js/components/users/RoleBadges.vue` (docblock de prop).

- Domaine **Auth & Compte** : 8/16 contrôleurs conformes après ce lot (le total passe de 15 à 16,
  `AccountController` 1 fichier remplacé par 2 contrôleurs mono-action). Reste à traiter, du plus
  petit au plus gros : `UserInvitationController`, `Settings\ProfileController`,
  `InstallWizardController`, `Auth\AcceptInvitationController`, `RoleController`,
  `UserController`, puis les 2 renommages triviaux du domaine.

## Lot 20 — Auth & Compte : `UserInvitationController`

**Périmètre** (4 actions) : `store/resend/destroy/forceDestroy` →
`app/Http/Controllers/UserInvitation/{Store,Resend,Destroy,ForceDestroy}UserInvitationController.php`
(`__invoke()`). Aucune dépendance privée partagée entre les 4 actions d'origine (chacune injecte
`UserInvitationService` par méthode, pas de constructeur) — déplacement direct. Ordre des routes
strictement préservé : `store` reste dans le groupe `module:SITES` (throttle `10,1`, préfixe
`sites/{site}/invitations`), `resend`/`destroy`/`forceDestroy` restent dans le groupe
`module:UTILISATEURS` (préfixe `invitations/{invitation}*`) — les deux groupes de middleware
distincts, non fusionnés.

**Gap de test découvert et comblé avant tout déplacement** : `forceDestroy()` n'avait **aucun**
test (contrairement à `store`/`resend`/`destroy`, déjà exhaustivement couverts dans
`tests/Feature/UserInvitationTest.php`) alors qu'elle porte une vraie règle métier
(`UserInvitationService::delete()` : seule une invitation révoquée ou expirée peut être supprimée
définitivement, sinon `InvitationException`). 3 tests ajoutés :
`test_force_destroy_returns_403_for_user_from_other_org` (même ability `delete` que `destroy`,
cross-organisation), `test_force_destroy_permanently_deletes_a_revoked_invitation` (succès),
`test_force_destroy_refuses_a_pending_invitation` (règle métier : invitation encore en attente
refusée). 39 tests verts sur le code d'origine avant tout déplacement (160 assertions) — ce
fichier de test couvre aussi `Auth\AcceptInvitationController` (non concerné par ce lot, traité
plus tard dans le domaine).

**Vérifications après extraction** :
- Import ET registration de route mis à jour ensemble dans `routes/web.php` (dans les DEUX
  groupes de middleware, chacun à sa place d'origine), `vendor/bin/pint` vert, `route:list` ciblé
  sur `invitation` : les 4 routes résolvent vers les nouveaux contrôleurs, identiques par ailleurs
  (URI, nom, méthode, middleware/throttle) ; `route:list` complet (541 routes, inchangé).
- 39 tests verts après déplacement (160 assertions) — résultat strictement identique à la
  baseline.
- `npm run` typecheck (`vue-tsc --noEmit`) : vert. Wayfinder régénéré sans erreur, aucun fichier
  `.vue` à modifier : `Sites/Show.vue` (seul consommateur frontend de ces 4 actions) poste vers
  des URLs littérales (`/backoffice/sites/{id}/invitations`, `/backoffice/invitations/{id}*`),
  jamais vers un import Wayfinder nommé par classe.
- OpenAPI : non concerné (routes dans `routes/web.php`, hors périmètre `api_path.include: 'api'`).
- Aucune référence obsolète à `UserInvitationController` trouvée ailleurs dans le dépôt (balayage
  complet, seuls les nouveaux fichiers et `routes/web.php` mentionnent encore le nom, à jour).

- Domaine **Auth & Compte** : 12/19 contrôleurs conformes après ce lot (le total passe de 16 à 19,
  `UserInvitationController` 1 fichier remplacé par 4 contrôleurs mono-action). Reste à traiter,
  du plus petit au plus gros : `Settings\ProfileController`, `InstallWizardController`,
  `Auth\AcceptInvitationController`, `RoleController`, `UserController`, puis les 2 renommages
  triviaux du domaine.

## Lot 21 — Auth & Compte : `Settings\ProfileController`

**Périmètre** (3 actions) : `edit/update/destroy` →
`app/Http/Controllers/Settings/Profile/{Edit,Update,Destroy}ProfileController.php`
(`__invoke()`). 2 méthodes privées (`syncEmailIdentity`/`syncTelephoneIdentity`) utilisées
uniquement par `update()` — conservées telles quelles sur `UpdateProfileController`, aucune
extraction Support nécessaire (pas partagées avec `edit()`/`destroy()`).

**Gap de test découvert et comblé avant tout déplacement** : la règle métier
« le champ `telephone` n'est validé (et donc appliqué) que pour un `super_admin`,
cf. `ProfileUpdateRequest::rules()` » n'avait **aucun** test — ni pour confirmer qu'un super_admin
peut effectivement mettre à jour son téléphone via ce formulaire, ni pour confirmer qu'un
utilisateur non-super_admin voit ce champ silencieusement ignoré (pas d'erreur de validation, la
règle est absente du tableau plutôt que rejetée). 2 tests ajoutés à
`tests/Feature/Settings/ProfileUpdateTest.php` : `test_super_admin_can_update_telephone`,
`test_telephone_field_is_ignored_for_non_super_admin` (ce dernier corrigé une fois en cours de
rédaction : `User::factory()->create()` attache déjà une identité téléphone par défaut, le test
vérifie donc qu'elle reste inchangée après soumission, pas qu'elle reste `null`). 7 tests verts sur
le code d'origine avant tout déplacement (31 assertions).

**Vérifications après extraction** :
- Import ET registration de route mis à jour ensemble dans `routes/settings.php`,
  `vendor/bin/pint` vert, `route:list` ciblé sur `settings/profile` : les 3 routes résolvent vers
  les nouveaux contrôleurs, identiques par ailleurs (URI, nom, méthode, middleware `auth`) ;
  `route:list` complet (541 routes, inchangé).
- 7 tests verts après déplacement (31 assertions) — résultat strictement identique à la baseline.
  Régression élargie `tests/Feature/Settings/` (116 tests, 543 assertions), aucune régression sur
  les contrôleurs voisins.
- `npm run` typecheck (`vue-tsc --noEmit`) : vert. `npm run lint:standards` : vert (2 fichiers
  `.vue` touchés). Wayfinder régénéré sans erreur.
- OpenAPI : non concerné (routes dans `routes/settings.php`, hors périmètre
  `api_path.include: 'api'`).
- 2 références obsolètes à `Settings\ProfileController` (nom de classe supprimé, décrivant un
  comportement ACTUEL — import Wayfinder + usage `.form()`) corrigées :
  `resources/js/pages/settings/Profile.vue` (→ `UpdateProfileController`),
  `resources/js/components/DeleteUser.vue` (→ `DestroyProfileController`). Les occurrences
  restantes de « ProfileController » ailleurs dans le dépôt désignent
  `Api\Client\ProfileController`/`Api\Client\UpdateProfileController`, classes homonymes mais
  distinctes, déjà conformes et non renommées — laissées intactes.

- Domaine **Auth & Compte** : 15/21 contrôleurs conformes après ce lot (le total passe de 19 à 21,
  `Settings\ProfileController` 1 fichier remplacé par 3 contrôleurs mono-action). Reste à traiter,
  du plus petit au plus gros : `InstallWizardController`, `Auth\AcceptInvitationController`,
  `RoleController`, `UserController`, puis les 2 renommages triviaux du domaine.

## Lot 22 — Auth & Compte : `InstallWizardController`

**Périmètre** (6 actions) : `show/verifyToken/resolvePhone/sendEmailCode/verifyEmailCode/store` →
`app/Http/Controllers/InstallWizard/{Show,VerifyToken,ResolvePhone,SendEmailCode,VerifyEmailCode,
Store}InstallWizardController.php` (`__invoke()`). 3 méthodes privées partagées entre plusieurs
actions (`assertSaasTokenConfigured()` — les 6 actions, `tokenRequired()` — 2 actions,
`ensureTokenVerified()` — 4 actions), toutes dépendantes du constructeur `InstallationService
$service` injecté — extraites vers `App\Support\Auth\InstallWizardGuard` (constructeur-injecté,
même exception documentée que `ClientDashboardPayloadBuilder`/`DepenseListingService` : pas de
méthode statique possible puisque `InstallationService` doit être injecté). Chaque nouveau
contrôleur injecte à la fois `InstallationService` (appels directs `isLocked()`/`isSaas()`/
`install()`/`resolveTelephone()`) et `InstallWizardGuard`. Le trait `HasOtpRateLimitResponse`
(partagé avec `Auth\AcceptInvitationController`, non concerné par ce lot) reste sur
`SendEmailCodeInstallWizardController`, seule action qui l'utilisait. L'appel direct
`$this->service->isLocked()` (présent dans les 6 actions d'origine, avec une gestion différente
selon l'action : redirection vers `/login` pour `show()`, abort 404 pour les 5 autres) reste
dupliqué tel quel dans chaque nouveau contrôleur — pas une méthode privée partagée à l'origine
(un appel direct répété), donc pas un candidat à l'extraction Support, conformément à la règle du
chantier de ne jamais dédupliquer une duplication préexistante hors périmètre pur.

**Tests avant déplacement** : `tests/Feature/InstallWizardTest.php` (49 tests, déjà exhaustif —
branches saas/on_premise, token, rate limiting OTP email, validations métier, création
organisation/site/super_admin/propriétaire interne) — aucun gap détecté, contrôleur public (pas de
dimension permission/organisation à tester, l'isolation vient du token + du verrou
`InstallationService::isLocked()`, déjà couverts). 49 tests verts sur le code d'origine avant tout
déplacement (243 assertions).

**Vérifications après extraction** :
- Import ET registration de route mis à jour ensemble dans `routes/web.php` (groupe `throttle:
  install` pour `show/verifyToken/resolvePhone/store`, throttles dédiés `otp-email-send`/
  `otp-email-verify` pour les 2 actions email, groupes strictement préservés), `vendor/bin/pint`
  vert, `route:list` ciblé sur `install` : les 6 routes résolvent vers les nouveaux contrôleurs,
  identiques par ailleurs (URI, nom, méthode, middleware/throttle) ; `route:list` complet
  (541 routes, inchangé).
- 49 tests verts après déplacement (243 assertions) — résultat strictement identique à la
  baseline.
- `npm run` typecheck (`vue-tsc --noEmit`) : vert. `npm run lint:standards` : vert
  (`Install/Wizard.vue` touché, uniquement des docblocks de commentaire). Wayfinder régénéré sans
  erreur ; `Install/Wizard.vue` et `Install/Token.vue` postent vers des URLs littérales
  (`/install/token`, `/install/phone-info`, `/install/email/*`), jamais vers un import Wayfinder
  nommé par classe — aucun composant `.vue` à modifier au-delà des docblocks.
- OpenAPI : non concerné (routes dans `routes/web.php`, hors périmètre `api_path.include: 'api'`).
- **E2E dédié non exécuté** : `tests/e2e-install/*.spec.ts` (4 fichiers) existe mais tourne sous un
  harnais Playwright séparé et manuel (`playwright.install.config.ts` — base vide, serveur lancé
  à la main sur le port 8080, jamais en parallèle du harnais e2e "normal"), sans script npm dédié
  dans ce dépôt et jamais utilisé plus tôt dans ce chantier. Non lancé ici : le risque réel est très
  faible (relocation pure, 49 tests backend identiques avant/après, typecheck propre), mais la
  vérification E2E réelle de ce parcours reste un point ouvert, signalé explicitement plutôt que
  silencieusement ignoré.
- 8 références obsolètes à `InstallWizardController` (nom de classe supprimé, décrivant un
  comportement ACTUEL) corrigées : `app/Services/InstallationService.php` (×4 docblocks),
  `app/Console/Commands/InstallApp.php` (×3 docblocks), `app/Providers/FortifyServiceProvider.php`
  (commentaire RateLimiter), `app/Http/Controllers/Concerns/HasOtpRateLimitResponse.php`
  (docblock de trait), `resources/js/pages/Install/Wizard.vue` (×2 commentaires),
  `config/app.php` (×2 commentaires de configuration APP_INSTALL_TOKEN). Une coquille repérée et
  corrigée dans mon propre docblock de `InstallWizardGuard` en le rédigeant (`Auth\InstallWizard\*`
  au lieu du namespace réel `InstallWizard\*`, sans le préfixe `Auth\`).

- Domaine **Auth & Compte** : 20/26 contrôleurs conformes après ce lot (le total passe de 21 à 26,
  `InstallWizardController` 1 fichier remplacé par 6 contrôleurs mono-action). Reste à traiter, du
  plus petit au plus gros : `Auth\AcceptInvitationController`, `RoleController`, `UserController`,
  puis les 2 renommages triviaux du domaine.

## Lot 23 — Auth & Compte : `Auth\AcceptInvitationController`

**Périmètre** (5 actions) : `show/checkPhone/resendOtp/verifyOtp/accept` →
`app/Http/Controllers/Auth/AcceptInvitation/{Show,CheckPhone,ResendOtp,VerifyOtp,Accept}
AcceptInvitationController.php` (`__invoke()`). 2 méthodes privées partagées entre plusieurs
actions, sans dépendance à un service injecté (pas de constructeur d'origine) — extraites en
`static` vers `App\Support\Auth\AcceptInvitationStates` : `otpContext()` (4 actions :
checkPhone/resendOtp/verifyOtp/accept) et `invitationErrorState()` (2 actions : show/accept).
`queryErrorState()`, utilisée uniquement par `show()`, reste une méthode privée locale de
`ShowAcceptInvitationController` — pas partagée, pas de raison de l'extraire. Le trait
`HasOtpRateLimitResponse` (partagé avec `InstallWizard\SendEmailCodeInstallWizardController`,
Lot 22) reste sur les 2 actions qui l'utilisaient (`CheckPhoneAcceptInvitationController`,
`ResendOtpAcceptInvitationController`).

**Gap de test découvert et comblé avant tout déplacement** : l'état d'erreur
`already_authenticated` — atteint quand `accept()` redirige un utilisateur déjà connecté vers
`show()` avec `?state=already_authenticated` (chemin non-JSON, celui réellement emprunté par le
formulaire web, `queryErrorState()`) — n'était testé que côté JSON (`postJson` → 422), jamais côté
redirection HTML ni côté rendu de `show()` avec ce state. 2 tests ajoutés à
`tests/Feature/UserInvitationTest.php` : `test_accept_redirects_to_show_with_already_authenticated_state`,
`test_accept_show_returns_error_for_already_authenticated_state` (ce dernier utilise une invitation
réellement `pending`, pas un token invalide — `invitationErrorState()` renvoie sinon `not_found`
en priorité sur l'état de requête). 41 tests verts sur le code d'origine avant tout déplacement
(174 assertions) — fichier partagé avec `UserInvitationController` (Lot 20, non re-touché ici).

**Vérifications après extraction** :
- Import ET registration de route mis à jour ensemble dans `routes/web.php` (5 routes, chacune son
  throttle propre — `20,1`/`otp-send`/`otp-verify`/`otp-send`/`5,1`, aucun groupe commun à
  préserver), `vendor/bin/pint` vert, `route:list` ciblé sur `invitations/accept` : les 5 routes
  résolvent vers les nouveaux contrôleurs, identiques par ailleurs ; `route:list` complet
  (541 routes, inchangé).
- 41 tests verts après déplacement (174 assertions) — résultat strictement identique à la
  baseline. Régression élargie avec `InstallWizardTest` (trait `HasOtpRateLimitResponse` partagé) :
  90 tests verts, 417 assertions.
- `npm run` typecheck (`vue-tsc --noEmit`) : vert. `npm run lint:standards` : vert
  (`Install/Wizard.vue` re-touché, uniquement un commentaire). Wayfinder régénéré sans erreur ;
  `Invitations/Accept.vue` et `Sites/Show.vue` (seuls consommateurs frontend) postent vers des
  URLs littérales, jamais vers un import Wayfinder nommé par classe.
- OpenAPI : non concerné (routes dans `routes/web.php`, hors périmètre `api_path.include: 'api'`).
- **E2E** : aucun test `.spec.ts` ne couvre `/invitations/accept/*` (vérifié par recherche) —
  cohérent avec Lot 20 (`UserInvitationController`, même famille de routes).
- 4 références obsolètes à `AcceptInvitationController` (nom de classe supprimé, décrivant un
  comportement ACTUEL) corrigées : `app/Http/Controllers/Concerns/HasOtpRateLimitResponse.php`
  (docblock de trait), `app/Http/Controllers/InstallWizard/SendEmailCodeInstallWizardController.php`
  (docblock), `app/Services/UserInvitationService.php` (commentaire), `resources/js/pages/Install/
  Wizard.vue` (commentaire) — plus 5 marqueurs de section dans
  `tests/Feature/UserInvitationTest.php` mis à jour vers les nouveaux noms de classe (organisation
  du fichier de test, pas du code applicatif).

- Domaine **Auth & Compte** : 25/30 contrôleurs conformes après ce lot (le total passe de 26 à 30,
  `Auth\AcceptInvitationController` 1 fichier remplacé par 5 contrôleurs mono-action). Reste à
  traiter, du plus petit au plus gros : `RoleController`, `UserController`, puis les 2 renommages
  triviaux du domaine.

## Lot 24 — Auth & Compte : `RoleController`

**Périmètre** (6 actions) : `index/create/store/edit/update/destroy` →
`app/Http/Controllers/Role/{Index,Create,Store,Edit,Update,Destroy}RoleController.php`
(`__invoke()`). `Route::resource('roles', RoleController::class)->only([...])` remplacé par 6
déclarations de route explicites (`Route::match(['put', 'patch'], ...)` pour `update`, même
pattern que dans les lots précédents). 4 méthodes privées partagées entre plusieurs actions,
sans dépendance à un service injecté — extraites en `static` vers
`App\Support\Permissions\RoleAccess` (nouvelle classe, `App\Support\Permissions\RoleVisibility`
existant déjà et utilisé par 5 autres contrôleurs non concernés par ce lot, volontairement non
modifié au-delà de ses propres références obsolètes) : `isProtected()` (index/update/destroy),
`authorizeSameOrganization()` (edit/update/destroy), `canManageRoles()` (create/store),
`canManageRole()` (edit/update/destroy). `visibleRoles()`, utilisée uniquement par `index()`,
reste une méthode privée locale de `IndexRoleController` — pas partagée, pas de raison de
l'extraire. Le docblock de classe (règle métier centrale : seul `super_admin` protégé ; `name`
jamais réécrit après création) déplacé sur `RoleAccess`, désormais le point de centralisation de
cette règle documentée.

**Gap de test découvert et comblé avant tout déplacement** : `create()` (page `Roles/Create`)
n'avait **aucun** test — ni accès autorisé, ni refus de permission — alors que les 5 autres
actions étaient déjà exhaustivement testées (31 tests). 2 tests ajoutés à
`tests/Feature/RoleTest.php` : `test_create_returns_200_for_authorized_user`,
`test_create_returns_403_if_not_admin_entreprise` (même pattern que les tests `store` existants,
`create()` et `store()` partageant la même ability `canManageRoles()`). 35 tests verts sur le code
d'origine avant tout déplacement (80 assertions).

**Vérifications après extraction** :
- Import ET registration de route mis à jour ensemble dans `routes/web.php`, `vendor/bin/pint`
  vert, `route:list` ciblé sur `roles` : les 6 routes résolvent vers les nouveaux contrôleurs,
  identiques par ailleurs (URI, nom, méthode — `PUT|PATCH` combiné préservé pour `update`,
  middleware `module:UTILISATEURS` inchangé) ; `route:list` complet (541 routes, inchangé).
- 35 tests verts après déplacement (80 assertions) — résultat strictement identique à la
  baseline.
- `npm run` typecheck (`vue-tsc --noEmit`) : vert. `npm run lint:standards` : vert
  (`Roles/Edit.vue` touché, uniquement un docblock de commentaire). Wayfinder régénéré sans
  erreur ; aucune page `Roles/*.vue` n'importe d'action Wayfinder nommée par classe.
- OpenAPI : non concerné (routes dans `routes/web.php`, hors périmètre `api_path.include: 'api'`).
- **E2E** : aucun test `.spec.ts` ne couvre `/backoffice/roles*` (vérifié par recherche).
- **17 références obsolètes** à `RoleController` (nom de classe supprimé, décrivant un
  comportement ACTUEL — la classe centralisait une règle métier très documentée, donc largement
  citée ailleurs) corrigées, réparties sur 16 fichiers : `app/Http/Controllers/UserController.php`
  (×3), `app/Http/Controllers/FonctionRhController.php`, `app/Support/Permissions/
  RoleVisibility.php` (×2), `tests/Unit/UserTest.php`, `database/seeders/
  RolesAndPermissionsSeeder.php`, `app/Support/Permissions/PermissionCatalog.php` (×2 — une 3ᵉ
  occurrence, "avaient divergé", est de la narration historique légitime, laissée intacte),
  `resources/js/pages/Roles/Edit.vue`, `app/Models/User.php`, `app/Http/Controllers/Settings/
  StockAjustementController.php`, `tests/Feature/UserControllerPrivilegeEscalationTest.php` (×2),
  `tests/Feature/UserControllerTest.php`, `tests/Feature/EnsureIsStaffAccountTest.php` (×2),
  `app/Http/Middleware/EnsureIsStaffAccount.php`, `tests/Feature/FonctionRhTest.php` (×2),
  `tests/Feature/EmployeAffectationTest.php`, `app/Services/Rh/AccountValidationService.php` (×2),
  `app/Services/RoleNamingService.php` (×4), `database/migrations/
  2026_08_15_192815_add_code_and_is_system_to_roles_table.php` (×2 — édition d'un commentaire
  dans une migration déjà exécutée, aucun changement de schéma).

- Domaine **Auth & Compte** : 32/35 contrôleurs conformes après ce lot (le total passe de 30 à 35,
  `RoleController` 1 fichier remplacé par 6 contrôleurs mono-action). Il ne reste plus qu'**1 seul
  contrôleur multi-actions** dans tout le domaine : `UserController` — puis les 2 renommages
  triviaux (`Auth\LivreurRegistrationController`, `Settings\TwoFactorAuthenticationController`)
  pour clore entièrement le domaine.

## Lot 25 — Auth & Compte : `UserController` (dernier multi-actions du domaine)

**Périmètre** (9 actions routées) : `index/create/store/edit/update/validateAccount/
rejectAccount/updatePassword/destroy` →
`app/Http/Controllers/User/{Index,Create,Store,Edit,Update,ValidateAccount,RejectAccount,
UpdatePassword,Destroy}UserController.php` (`__invoke()`). `Route::resource('users',
UserController::class)->except(['show'])` remplacé par 6 déclarations explicites (`update` en
`Route::match(['put', 'patch'], ...)`) + les 3 routes déjà explicites (`update-password`,
`validate`, `reject`) simplement repointées. Le plus gros contrôleur de ce domaine (611 lignes) et
celui portant le plus de dépendances externes de tout le chantier à ce jour.

**Extraction Support** — 4 classes `static`, aucune ne dépendant d'un service injecté :
- `App\Support\User\UserFormOptions` : constantes `STAFF_ROLES`/`INVITABLE_ROLES`/`ADMIN_ROLES`
  (ex-constantes de classe de `UserController`, référencées par **6 fichiers externes**, cf.
  ci-dessous) + `PAYS` (ex-`const USER_PAYS` au niveau fichier, devenue constante de classe) +
  `assignableStaffRoles()`/`getRoleOptions()`/`getSiteOptions()`/`validationRoleOptions()`/
  `assignableRoleRule()`/`resolvePays()`/`buildFullTelephone()`.
- `App\Support\User\UserIdentitySync` : `buildPersonneFields()`/`syncTelephoneIdentity()`/
  `syncEmailIdentity()`/`assertIdentityUnique()`.
- `App\Support\User\UserPrivilegeGuard` : `assertNoPrivilegeEscalation()` (règle anti-élévation
  de privilège corrigée le 2026-08-21/2026-09-06, docblock intégralement préservé).
- `App\Support\User\UserIndexPayload` : `build(User $authUser): array` — ex-`indexProps()`,
  **la dépendance croisée déjà signalée dans ce document avant ce lot** : appelée par
  `Account\IndexAccountController` (Lot 19) pour tout acteur non super_admin de l'écran "Comptes".
  `Account\IndexAccountController` mis à jour pour appeler `UserIndexPayload::build($authUser)`
  directement, au lieu de `app(UserController::class)->indexProps($authUser)` — la dépendance
  croisée est résolue proprement, les deux domaines partagent maintenant un Support commun plutôt
  qu'un contrôleur appelant un autre contrôleur.

**Gap de test découvert et comblé avant tout déplacement** : `pending_registrations` (comptes en
attente de toute la plateforme, `whereNull('organization_id')`, sans scoping par organisation)
n'était réservé au super_admin que par un simple `if` dans `indexProps()` — aucun test ne
vérifiait qu'un admin_entreprise ordinaire ne pouvait PAS recevoir cette donnée, un risque réel de
fuite inter-organisation si cette condition venait à régresser silencieusement. 1 test ajouté à
`tests/Feature/UserControllerTest.php` : `test_index_hides_pending_registrations_from_non_super_admin`.
41 tests verts sur le code d'origine avant tout déplacement (84 assertions) — `validateAccount()`/
`rejectAccount()` déjà exhaustivement couverts séparément par `tests/Feature/AccountValidationTest.php`
(8 tests), pas de gap à combler là ; la matrice d'autorisation complète (update/delete, même
organisation, cross-organisation, élévation vers super_admin) est déjà exhaustivement testée au
niveau Policy par `tests/Unit/UserPolicyTest.php` (19 tests unitaires) — pas dupliquée en tests
Feature, proportionnalité déjà appliquée aux lots précédents (ex. Lot 16 `MediaController`).

**Vérifications après extraction** :
- Import ET registration de route mis à jour ensemble dans `routes/web.php`, `vendor/bin/pint`
  vert (imports réordonnés automatiquement sur les 6 fichiers externes touchés), `route:list`
  ciblé sur `users` : les 9 routes résolvent vers les nouveaux contrôleurs, identiques par
  ailleurs (URI, nom, méthode — `PUT|PATCH` combiné préservé pour `update`, middleware
  `module:UTILISATEURS` inchangé) ; `route:list` complet (541 routes, inchangé).
- 116 tests verts après déplacement, régression combinée sur les 6 fichiers de test dépendants
  (`UserControllerTest`, `UserControllerPrivilegeEscalationTest`, `AccountValidationTest`,
  `MatriculeTest`, `AccountControllerTest`, `UserInvitationTest` — ces deux derniers pour
  confirmer la dépendance croisée `UserIndexPayload`/les constantes de rôle réutilisées),
  331 assertions — résultat strictement identique à la baseline.
- `npm run` typecheck (`vue-tsc --noEmit`) : vert. `npm run lint:standards` : vert
  (`Users/partials/UserForm.vue` touché, uniquement un commentaire). Wayfinder régénéré sans
  erreur ; les pages `Users/*.vue` utilisent le helper de route nommée Ziggy (`route()`), jamais
  d'import Wayfinder nommé par classe — aucune adaptation frontend nécessaire au-delà du
  commentaire.
- OpenAPI : non concerné (routes dans `routes/web.php`, hors périmètre `api_path.include: 'api'`).
- **6 fichiers externes** référençant les constantes `UserController::{STAFF_ROLES,
  INVITABLE_ROLES,ADMIN_ROLES}` ou `app(UserController::class)->indexProps()` mis à jour vers
  `App\Support\User\UserFormOptions`/`UserIndexPayload` : `app/Console/Commands/
  BackfillMatricules.php`, `app/Services/MatriculeService.php`, `tests/Feature/MatriculeTest.php`
  (`STAFF_ROLES`) ; `app/Http/Controllers/UserInvitation/StoreUserInvitationController.php`,
  `app/Http/Controllers/Sites/ShowSiteController.php` (`INVITABLE_ROLES`) ;
  `app/Services/UserInvitationService.php` ×2 (`ADMIN_ROLES`) ;
  `app/Http/Controllers/Account/IndexAccountController.php` (`indexProps()`).
- **9 autres références obsolètes** à `UserController` (docblocks décrivant un comportement
  ACTUEL) corrigées : `app/Services/InstallationService.php`,
  `app/Http/Controllers/Settings/Profile/UpdateProfileController.php`,
  `resources/js/pages/Users/partials/UserForm.vue`, `app/Models/User.php` (×2),
  `app/Support/Permissions/RoleVisibility.php`. Les mentions restantes de « UserController »
  ailleurs dans le dépôt (`ScanUserController`, `docs/scanner-dashboard-mobile.md`,
  `docs/references-metier.md`) désignent une classe homonyme sans rapport
  (`ScanUserController`), laissées intactes. `tests/Feature/UserControllerPrivilegeEscalationTest.php`
  (docblock de classe) décrit un bug historique déjà corrigé le 2026-08-21 — narration au passé
  légitime, non modifiée.

- Domaine **Auth & Compte** : 41/43 contrôleurs conformes après ce lot (le total passe de 35 à 43,
  `UserController` 1 fichier remplacé par 9 contrôleurs mono-action). **Plus aucun contrôleur
  multi-actions dans ce domaine.** Restent uniquement les 2 renommages triviaux
  (`Auth\LivreurRegistrationController`, `Settings\TwoFactorAuthenticationController`) avant
  clôture complète du domaine.

## Lot 26 — Auth & Compte : les 2 derniers renommages triviaux (clôture du domaine)

**Périmètre** : `Auth\LivreurRegistrationController::store()` →
`Auth\StoreLivreurRegistrationController` (`__invoke()`), méthode privée statique
`formatPrenom()` conservée telle quelle (non partagée, reste locale) ;
`Settings\TwoFactorAuthenticationController::show()` →
`Settings\ShowTwoFactorAuthenticationController` (`__invoke()`), implémente toujours
`HasMiddleware` — `Middleware('password.confirm', only: ['show'])` adapté en
`only: ['__invoke']` (adaptation mécanique obligatoire : Laravel cible les méthodes par nom, pas
un changement de comportement).

**Gap de test découvert et comblé avant tout déplacement** :
`Auth\LivreurRegistrationController::store()` — inscription livreur avec vérification OTP,
création User/Personne/Livreur, liaison à un livreur pré-existant sans compte — n'avait **aucun**
test, ni PHPUnit ni E2E, malgré une logique métier réelle (transaction, unicité téléphone,
exigence de vérification OTP, réutilisation d'un `Livreur` existant). 6 tests créés dans
`tests/Feature/Auth/LivreurRegistrationTest.php` : création + connexion automatique, refus sans
vérification OTP, refus téléphone déjà utilisé, refus téléphone invalide, liaison à un livreur
existant sans compte, validation des champs requis. `Settings\TwoFactorAuthenticationController`
disposait déjà de 4 tests exhaustifs (`tests/Feature/Settings/TwoFactorAuthenticationTest.php`,
vérifiés au Lot 17). 10 tests verts sur le code d'origine avant tout déplacement (54 assertions).

**Vérifications après extraction** :
- Import ET registration de route mis à jour ensemble dans `routes/web.php`/`routes/settings.php`,
  `vendor/bin/pint` vert, `route:list` ciblé : les 2 routes résolvent vers les nouveaux
  contrôleurs, identiques par ailleurs (URI, nom, méthode, middleware — y compris le
  `password.confirm` conditionnel de `ShowTwoFactorAuthenticationController`) ; `route:list`
  complet (541 routes, inchangé).
- 10 tests verts après déplacement (54 assertions) — résultat strictement identique à la
  baseline.
- **E2E** : tentative sur `tests/e2e/user-flow.spec.ts`/`user-quality.spec.ts` (build e2e +
  `playwright test`, 2 essais) — échec systématique dans `global-setup.ts` (timeout sur le login
  partagé par tous les specs e2e, avant même d'atteindre les fichiers ciblés), reproductible à
  l'identique aux deux tentatives. Comportement cohérent avec la flakiness déjà documentée de ce
  harnais local (contention/cold-start, cf. mémoire de session recommandant les artefacts CI
  plutôt qu'une repro locale) — non poursuivi davantage, signalé explicitement comme vérification
  E2E non aboutie plutôt que silencieusement ignoré. Aucune régression PHPUnit détectée en
  parallèle (route:list propre, 100 % des tests Feature/Unit du domaine verts), ce qui limite le
  risque réel d'une régression fonctionnelle passée inaperçue.
- OpenAPI : non concerné (routes dans `routes/web.php`/`routes/settings.php`, hors périmètre
  `api_path.include: 'api'`).
- Aucune référence obsolète trouvée à `LivreurRegistrationController` ou
  `TwoFactorAuthenticationController` en dehors des nouveaux fichiers et des routes (balayage
  complet) — les mentions de `LivreurRegistrationController` dans `Api\Public\` désignent une
  classe homonyme distincte, déjà conforme, non renommée.

- **Domaine Auth & Compte entièrement clos** : 43/43 contrôleurs conformes. 9ᵉ domaine clos de ce
  chantier.

## Lot 27 — Correction du domaine Clients : 2 contrôleurs manqués à la clôture (Lot 8)

**Découverte** : en lançant la recherche dédiée de vérification du domaine suivant (Commandes &
Ventes), l'agent de recherche a signalé un candidat plausible pour Commandes & Ventes
(`CategorieTarifGrossisteController`) et un signalement hors-périmètre
(`ClientVehicleController`, jugé appartenir au domaine Clients déjà clos). Vérification directe
(lecture du code, groupe de routes) : les deux contrôleurs sont déclarés **au milieu même du bloc
de routes `clients.*`** (`routes/web.php`, juste après `clients.derogation-impayes.update`),
utilisent `$this->authorize('update', $client)` (ClientPolicy, exactement comme
`UpdateCashbackClientController`/`UpdateDerogationClientController`, déjà dans ce domaine), et
leurs deux modèles (`CategorieTarifGrossiste`, `ClientVehicle`) sont rattachés à `client_id`. Les
deux appartiennent bien au domaine **Clients**, pas à Commandes & Ventes — contrairement à
l'hypothèse initiale de l'agent pour le premier (retenue avec prudence, jamais adoptée sans
vérification directe). Le domaine Clients, déclaré clos au Lot 8 à 21/21, avait donc en réalité
**23 contrôleurs**, 2 étant passés inaperçus lors de l'audit initial.

**Périmètre** : `CategorieTarifGrossisteController::forClient/update` (108 lignes, 2 actions) →
`app/Http/Controllers/Clients/{Show,Update}TarifsGrossisteClientController.php` (`__invoke()`),
méthode privée `assertTarifCouvreCout()` conservée sur `UpdateTarifsGrossisteClientController`
(utilisée uniquement par `update()`) ; `ClientVehicleController::store/update/destroy` (3 actions)
→ `app/Http/Controllers/Clients/{Store,Update,Destroy}VehiculeClientController.php`
(`__invoke()`), méthode privée `validated()` partagée par les 3 actions extraite en `static` vers
`App\Support\Clients\ClientVehicleData` (utilise le trait `PhoneHandlerTrait` pour
`supportedPays()`, comme l'original).

**Gap de test découvert et comblé avant tout déplacement** : `ClientVehicleController` n'avait
**aucun** test (ni PHPUnit ni E2E), alors que `CategorieTarifGrossisteController` disposait déjà
de 12 tests exhaustifs (`tests/Feature/CategorieTarifGrossisteTest.php`, permissions + isolation
organisationnelle déjà couvertes). 12 tests créés dans `tests/Feature/ClientVehicleTest.php` :
création/modification/suppression, tous les champs facultatifs, normalisation téléphone
chauffeur, téléphone invalide rejeté, permission `clients.update` requise, isolation
organisationnelle par action, et un garde-fou métier jusque-là non testé : `update()`/`destroy()`
renvoient 404 (pas juste un refus silencieux) quand le véhicule ciblé appartient à un AUTRE
client que celui de l'URL. 24 tests verts au total sur le code d'origine avant tout déplacement
(12 + 12, 25 assertions chacun).

**Vérifications après extraction** :
- Import ET registration de route mis à jour ensemble dans `routes/web.php`, `vendor/bin/pint`
  vert, `route:list` ciblé sur `tarifs-grossiste`/`vehicules` : les 5 routes résolvent vers les
  nouveaux contrôleurs, identiques par ailleurs (URI, nom, méthode) ; `route:list` complet
  (541 routes, inchangé).
- 24 tests verts après déplacement (50 assertions) — résultat strictement identique à la
  baseline.
- `npm run` typecheck (`vue-tsc --noEmit`) : vert. Wayfinder régénéré sans erreur, aucun fichier
  `.vue` touché (aucune page frontend n'importe une action Wayfinder nommée par ces classes).
- OpenAPI : non concerné (routes dans `routes/web.php`, hors périmètre `api_path.include: 'api'`).
- **E2E** : aucun test `.spec.ts` ne couvre `tarifs-grossiste`/`clients/*/vehicules` (vérifié par
  recherche).
- 6 références obsolètes corrigées (docblocks décrivant un comportement ACTUEL) :
  `app/Models/CategorieTarifGrossiste.php`, `app/Http/Requests/Produits/
  UpdateCategorieTarifsGrossisteRequest.php` (×2), `docs/grossiste.md` (×4),
  `database/migrations/0001_01_01_000022_5_create_client_vehicules_table.php` (édition d'un
  commentaire dans une migration déjà exécutée, aucun changement de schéma).

- **Domaine Clients** : total corrigé de 21 à 23 contrôleurs, désormais **23/23 conformes**
  (toujours entièrement clos, chiffre rectifié).

## Correction du 13/09/2026 (bucketing du domaine Commandes & Ventes)

Recherche dédiée (même méthode qu'aux domaines précédents) avant d'ouvrir le domaine
**Commandes & Ventes**. L'ancien inventaire annonçait 6 contrôleurs (0 conforme / 1 renommage
trivial / 5 multi-actions) ; la recherche a confirmé l'existence de `PdvController` dans ce
domaine (point de vente / caisse, module `PDV` distinct mais même domaine métier que les ventes)
mais a également établi que `CategorieTarifGrossisteController` — un temps envisagé ici car sa
donnée est consommée par le moteur de vente — appartient en réalité au domaine **Clients** (cf.
Lot 27 ci-dessus, décidé par vérification directe du groupe de routes et du pattern
d'autorisation, pas par la simple consommation de la donnée). Une fois cette exclusion faite, le
total réel de Commandes & Ventes est **5 contrôleurs**, pas 6 (0 conforme / 1 renommage trivial /
4 multi-actions) — chiffre initialement mal reporté dans ce document (une première rédaction de
cette section annonçait encore « 6 contrôleurs » tout en énumérant 5 éléments, incohérence
corrigée ici).

**Composition confirmée (5 contrôleurs)**, du plus petit au plus gros :
1. `CommandeVenteStatutController` (107 lignes, 2 actions)
2. `EncaissementVenteController` (169 lignes, 2 actions)
3. `PdvController` (183 lignes, 2 actions)
4. `FactureVenteController` (223 lignes, 1 action — renommage trivial)
5. `CommandeVenteController` (1775 lignes, 11 actions — de très loin le plus gros contrôleur de
   tout le chantier à ce jour, traité en dernier dans ce domaine)

**Avertissement découvert pendant la recherche (hors périmètre, à garder en tête pour
`CommandeVenteController`)** : `CommandeVenteController::valider()`/`annuler()` (routes
`ventes.valider`/`ventes.annuler`) semblent recouper fonctionnellement
`CommandeVenteStatutController::avancer()`/`annuler()` (routes `ventes.statut.avancer`/
`ventes.statut.annuler`, cf. Lot 28 ci-dessous) — mêmes transitions de statut, validations et
ability Policy proches mais pas strictement identiques (ex. `MotifAnnulation::validValues()` avec
règle `in:` stricte côté `CommandeVenteController::annuler()`, contre une simple chaîne côté
`CommandeVenteStatutController::annuler()`). Duplication ou legacy pré-existante, **non modifiée** —
signalée explicitement pour investigation au moment de traiter `CommandeVenteController` lui-même,
jamais fondue silencieusement pendant ce chantier de pure relocation.

## Lot 28 — Commandes & Ventes : `CommandeVenteStatutController` (ouverture du domaine)

**Périmètre** (2 actions) : `avancer/annuler` →
`app/Http/Controllers/Ventes/{Avancer,Annuler}StatutVenteController.php` (`__invoke()`). Aucune
dépendance privée partagée entre les 2 actions d'origine — déplacement direct.

**Gaps de test découverts et comblés avant tout déplacement** : malgré 28 tests déjà existants
(workflow, commissions, stock, motifs d'annulation), **aucun** ne couvrait le refus de permission
ni l'isolation organisationnelle pour `avancer()`/`annuler()` — un angle mort d'autant plus notable
que `CommandeVentePolicy::avancerStatut()`/`annuler()` vérifient explicitement
`sameOrganization()`, et que `annuler()` restreint l'action aux rôles `super_admin`/
`admin_entreprise` (jamais testé avec un rôle `manager`, pourtant le cas réel le plus probable de
refus). 4 tests ajoutés à `tests/Feature/CommandeVenteStatutTest.php` :
`test_avancer_returns_403_without_ventes_update_permission`,
`test_avancer_returns_403_for_other_organization`,
`test_annuler_returns_403_for_non_admin_role`, `test_annuler_returns_403_for_other_organization`.
32 tests verts sur le code d'origine avant tout déplacement (87 assertions).

**Vérifications après extraction** :
- Import ET registration de route mis à jour ensemble dans `routes/web.php`, `vendor/bin/pint`
  vert, `route:list` ciblé sur `ventes/.../statut` : les 2 routes résolvent vers les nouveaux
  contrôleurs, identiques par ailleurs (URI, nom, méthode, middleware `module:VENTES`) ;
  `route:list` complet (541 routes, inchangé).
- 32 tests verts après déplacement (87 assertions) — résultat strictement identique à la
  baseline.
- `npm run` typecheck (`vue-tsc --noEmit`) : vert. Aucun fichier `.vue` touché (uniquement PHP +
  routes + tests/docs) — pas d'ESLint ciblé nécessaire. Wayfinder régénéré sans erreur.
- OpenAPI : non concerné (routes dans `routes/web.php`, hors périmètre `api_path.include: 'api'`).
- **E2E** : aucun test `.spec.ts` ne couvre `ventes/*/statut/*` (vérifié par recherche).
- 4 références obsolètes à `CommandeVenteStatutController` (nom de classe supprimé, décrivant un
  comportement ACTUEL) corrigées : `tests/Feature/CommandeVenteStatutTest.php` (docblock),
  `docs/notifications-transactionnelles.md` (×2), `tests/Feature/CommandeVenteCommunicationTest.php`,
  `app/Jobs/NotifierChargementValideCommandeVenteJob.php`.

- Domaine **Commandes & Ventes ouvert** : 2/6 contrôleurs conformes après ce lot (le total passe de
  5 à 6, `CommandeVenteStatutController` 1 fichier remplacé par 2 contrôleurs mono-action). Reste
  à traiter, du plus petit au plus gros : `EncaissementVenteController`, `PdvController`,
  `FactureVenteController` (renommage trivial), `CommandeVenteController`.

## Lot 29 — Commandes & Ventes : `EncaissementVenteController`

**Périmètre** (2 actions) : `store/destroy` →
`app/Http/Controllers/Ventes/{Store,Destroy}EncaissementVenteController.php` (`__invoke()`).
Constructeur injectant `AuditLogService` dupliqué à l'identique sur les deux nouveaux contrôleurs
(aucune méthode privée partagée dans l'original). Contrôleur au cœur du moteur de commissions et
de cashback (nombreux appelants documentés ailleurs dans le code, cf. balayage ci-dessous) — aucune
règle métier touchée, relocation pure.

**Gap de test découvert et comblé avant tout déplacement** : ni `store()` ni `destroy()` n'avaient
de test d'isolation organisationnelle, alors que les deux portent un `abort_unless(...
organization_id === auth()->user()->organization_id, 403, ...)` explicite jamais exercé. 2 tests
ajoutés à `tests/Feature/EncaissementVenteTest.php` :
`test_store_returns_403_for_facture_from_other_organization`,
`test_destroy_returns_403_for_encaissement_from_other_organization`. 9 tests verts sur le code
d'origine avant tout déplacement (16 assertions). Aucune permission dédiée n'existe sur ce
contrôleur (seule l'appartenance à l'organisation est vérifiée) — cohérent avec l'original, rien à
ajouter de ce côté.

**Vérifications après extraction** :
- Import ET registration de route mis à jour ensemble dans `routes/web.php`, `vendor/bin/pint`
  vert, `route:list` ciblé sur `encaissements` : les 2 routes résolvent vers les nouveaux
  contrôleurs, identiques par ailleurs (URI, nom, méthode, middleware `module:VENTES`) ;
  `route:list` complet (541 routes, inchangé).
- 9 tests verts après déplacement (16 assertions) — résultat strictement identique à la baseline.
  Régression élargie avec `CommissionMoteurGeneriqueMultiProcessusTest` (moteur de commissions
  fortement couplé à l'encaissement) : 22 tests verts, 113 assertions.
- `npm run` typecheck (`vue-tsc --noEmit`) : vert. Aucun fichier `.vue` à modifier :
  `Ventes/Show.vue` (seul consommateur frontend) poste vers l'URL littérale
  `/backoffice/factures/{id}/encaissements`, jamais un import Wayfinder nommé par classe.
  Wayfinder régénéré sans erreur.
- OpenAPI : non concerné (routes dans `routes/web.php`, hors périmètre `api_path.include: 'api'`).
- **E2E** : 3 specs existent (`commission-declencheur-encaissement`,
  `commission-encaissements-multiples`, `commission-regression-encaissement-supprime`) mais n'ont
  pas été relancées localement (harnais e2e local en panne de démarrage, cf. Lot 26 — timeout
  systématique dans `global-setup.ts`, non spécifique à ce lot) ; leurs seules références au nom
  de classe supprimé (commentaires) ont été corrigées. Risque jugé faible : relocation pure,
  9 tests PHPUnit + 22 tests du moteur de commissions strictement identiques avant/après.
- **13 références obsolètes** à `EncaissementVenteController` (nom de classe supprimé, décrivant
  un comportement ACTUEL — contrôleur central au moteur de commissions/cashback, donc largement
  cité) corrigées : `app/Models/EncaissementVente.php` (×2), `app/Observers/VenteObserver.php`,
  `app/Services/CashbackService.php`, `app/Services/CommissionTriggerService.php`,
  `app/Services/Commission/CommissionEnveloppeGenerator.php`,
  `database/migrations/2026_08_28_105847_migrate_client_type_standard_to_revendeur.php` (édition
  d'un commentaire dans une migration déjà exécutée), `database/seeders/Organizations/
  FelloDemo/FelloDemoSalesSeeder.php`, `tests/Feature/CommandeVenteTest.php`,
  `tests/Feature/CommissionMoteurGeneriqueMultiProcessusTest.php`, `docs/grossiste.md` (×2),
  `docs/cashback.md`, `docs/commissions.md`, `tests/e2e/commissions/helpers.ts`,
  `tests/e2e/commissions/commission-regression-encaissement-supprime.spec.ts`.

- Domaine **Commandes & Ventes** : 4/7 contrôleurs conformes après ce lot (le total passe de 6 à
  7, `EncaissementVenteController` 1 fichier remplacé par 2 contrôleurs mono-action). Reste à
  traiter, du plus petit au plus gros : `PdvController`, `FactureVenteController` (renommage
  trivial), `CommandeVenteController`.

## Lot 30 — Commandes & Ventes : `PdvController`

**Périmètre** (2 actions) : `index/checkout` →
`app/Http/Controllers/Ventes/{Index,Checkout}PdvController.php` (`__invoke()`). Constructeur
injectant `PdvCheckoutService` conservé sur `CheckoutPdvController` uniquement (`index()` ne
l'utilisait pas). 1 méthode privée partagée entre les 2 actions, sans dépendance à un service
injecté — extraite en `static` vers `App\Support\Ventes\PdvSiteResolver::defaultSiteId()`
(ex-`getUserSiteId()`). `produitsPdv()`, utilisée uniquement par `index()`, reste une méthode
privée locale de `IndexPdvController` — pas partagée, pas de raison de l'extraire.

**Tests avant déplacement** : `tests/Feature/PdvCheckoutTest.php` (27 tests) déjà exhaustif
(rendu de la grille, isolation des données sensibles, modes vente rapide/client/livreur, stock,
dette/dérogation, refus non-authentifié) — aucune permission dédiée sur ce contrôleur (seule
l'appartenance à l'organisation/le site est vérifiée), donc pas de gap permission/cross-org
applicable au-delà de ce qui existe déjà. 27 tests verts sur le code d'origine avant tout
déplacement (91 assertions).

**Vérifications après extraction** :
- Import ET registration de route mis à jour ensemble dans `routes/web.php`, `vendor/bin/pint`
  vert, `route:list` ciblé sur `pdv` : les 2 routes résolvent vers les nouveaux contrôleurs,
  identiques par ailleurs (URI, nom, méthode, middleware `module:PDV`) ; `route:list` complet
  (541 routes, inchangé).
- 27 tests verts après déplacement (91 assertions) — résultat strictement identique à la
  baseline.
- `npm run` typecheck (`vue-tsc --noEmit`) : vert. Aucun fichier `.vue` à modifier (aucune page
  `PDV/*.vue` n'importe une action Wayfinder nommée par classe). Wayfinder régénéré sans erreur.
- OpenAPI : non concerné (routes dans `routes/web.php`, hors périmètre `api_path.include: 'api'`).
- **E2E** : aucun test `.spec.ts` ne couvre `backoffice/pdv*` (vérifié par recherche).
- 5 références obsolètes à `PdvController` (nom de classe supprimé, décrivant un comportement
  ACTUEL — contrôleur cité comme référence croisée dans plusieurs docblocks de produits/véhicules)
  corrigées : `app/Support/Produits/ProduitFormOptions.php`,
  `app/Http/Controllers/Produits/IndexProduitController.php`, `app/Models/Vehicule.php`,
  `tests/Feature/VehiculeTest.php`, `tests/Feature/ProduitListeFiltreeParStockSiteTest.php`.

- Domaine **Commandes & Ventes** : 6/8 contrôleurs conformes après ce lot (le total passe de 7 à
  8, `PdvController` 1 fichier remplacé par 2 contrôleurs mono-action). Reste à traiter :
  `FactureVenteController` (renommage trivial), puis `CommandeVenteController` (le plus gros
  contrôleur du chantier) — le seul multi-actions restant dans ce domaine.

## Lot 31 — Commandes & Ventes : `FactureVenteController` (renommage trivial)

**Périmètre** (1 action) : `index` → `app/Http/Controllers/Ventes/IndexFactureVenteController.php`
(`__invoke()`), code copié à l'identique (223 lignes, aucune méthode privée — tout le filtrage
vit en ligne dans l'action).

**Tests avant déplacement** : `tests/Feature/FactureVenteTest.php` (17 tests, déjà exhaustif —
permission, isolation organisationnelle, filtres période/statut/site, totaux) +
`tests/Unit/FactureVenteTest.php` (4 tests, recalcul de statut) — aucun gap détecté. 22 tests
verts sur le code d'origine avant tout déplacement (210 assertions).

**Vérifications après extraction** :
- Import ET registration de route mis à jour ensemble dans `routes/web.php`, `vendor/bin/pint`
  vert, `route:list` ciblé sur `factures` : la route résout vers le nouveau contrôleur, identique
  par ailleurs (URI, nom, méthode, middleware `module:VENTES`) ; `route:list` complet (541 routes,
  inchangé).
- 22 tests verts après déplacement (210 assertions) — résultat strictement identique à la
  baseline.
- `npm run` typecheck (`vue-tsc --noEmit`) : vert. Aucun fichier `.vue` à modifier (aucune page
  `Factures/*.vue` n'importe une action Wayfinder nommée par classe). Wayfinder régénéré sans
  erreur.
- OpenAPI : non concerné (routes dans `routes/web.php`, hors périmètre `api_path.include: 'api'`).
- **E2E** : `tests/e2e/facture-flow.spec.ts` existe mais n'a pas été relancé localement (harnais
  e2e local en panne de démarrage, cf. Lot 26/29 — timeout systématique dans `global-setup.ts`,
  non spécifique à ce lot). Risque jugé quasi nul : renommage trivial pur, code strictement
  identique, 22 tests PHPUnit inchangés.
- Aucune référence obsolète à `FactureVenteController` trouvée ailleurs dans le dépôt (balayage
  complet).

- Domaine **Commandes & Ventes** : 7/8 contrôleurs conformes après ce lot (le total reste à 8,
  simple renommage). **Il ne reste plus qu'un seul contrôleur multi-actions dans tout le
  chantier restant hors Comptabilité/Logistique** : `CommandeVenteController` (1775 lignes,
  11 actions), traité au lot suivant.

## Lot 32 — Commandes & Ventes : `CommandeVenteController` (clôture du domaine)

**Périmètre** (11 actions, 1775 lignes — de très loin le plus gros contrôleur du chantier à ce
jour) → 11 nouveaux contrôleurs mono-action dans `app/Http/Controllers/Ventes/` :
`CheckSolvabiliteCommandeVenteController` (`checkSolvabilite`), `IndexCommandeVenteController`
(`index`, sert aussi `distributions.index`), `CreateCommandeVenteController` (`create`),
`StoreCommandeVenteController` (`store`), `ShowCommandeVenteController` (`show`, sert aussi
`distributions.show`), `EditCommandeVenteController` (`edit`), `UpdateCommandeVenteController`
(`update`), `ValiderCommandeVenteController` (`valider`), `AnnulerCommandeVenteController`
(`annuler`), `DestroyCommandeVenteController` (`destroy`),
`RelancerCommissionsCommandeVenteController` (`relancerCommissions`).

Les méthodes privées partagées entre plusieurs actions de l'original ont été extraites en 2
classes Support :
- `App\Support\Ventes\CommandeVenteFormBuilder` (constructeur injectant `VehiculeCapaciteService`,
  comme l'original) : options de formulaire (`getUserSite()`, `getUserSiteModel()`,
  `redirectSiCreationBloquee()`, `produitsActifs()`, `vehiculesActifs()`, `vehiculesLogistiques()`,
  `clientsActifs()`), validation (`commandeValidationRules()`, `commandeValidationMessages()`,
  `ensureVehiculeOrClientSelected()`), construction de commande (`deriverModeRemiseGrossiste()`,
  `resolveVehiculeAvecEquipe()`, `ensureNatureOperationCoherente()`,
  `ensureQuantiteMatchesVehiculeCapacity()`, `ensurePartageLivraisonCategorieConfigure()` + son
  privé `ensureTransfertGrossisteBaremeConfigure()`, `resolveClientForTarification()`,
  `enforcePrixVentePolicy()`, `buildLignesDataAndTotal()` + ses privés `resolveVariante()`/
  `libelleSnapshot()`/`existingPrixVenteByVariante()`, `assertStockDisponiblePourLignes()`,
  `commandeSnapshot()`) — partagée par exactement les 4 actions qui en avaient besoin dans
  l'original : `create`/`store`/`edit`/`update`.
- `App\Support\Ventes\CommandeVenteCommissionStatus` (statique) : `getCommissionGenerationStatut()`,
  partagée par `show()`/`relancerCommissions()` uniquement.
- `getCommissionStatutGlobal()` (usage unique, `show()` seul) et `mapCommandeForIndex()`/
  `getUserSiteIdOrNull()` (usage unique, `index()` seul) sont restées des méthodes privées locales
  des contrôleurs correspondants — pas d'extraction inutile pour un usage unique.

Tous les docblocks métier (garde-fous, incidents référencés, décisions produit datées) conservés
verbatim méthode par méthode — aucune règle métier modifiée, relocation pure.

**Gaps de test découverts et comblés avant tout déplacement** : malgré 59 tests déjà existants
dans `tests/Feature/CommandeVenteTest.php`, plusieurs actions n'avaient aucun test de refus de
permission ni d'isolation organisationnelle : `create()` (permission), `update()`/`edit()`/
`valider()`/`annuler()`/`destroy()` (cross-org), `relancerCommissions()` (permission + cross-org +
cas nominal "rien à régulariser", jamais testé). 9 tests ajoutés à
`tests/Feature/CommandeVenteTest.php` (`test_create_returns_403_without_permission`,
`test_update_returns_403_for_other_organization`, `test_edit_returns_403_for_other_organization`,
`test_valider_returns_403_for_other_organization`, `test_annuler_returns_403_for_other_organization`,
`test_destroy_returns_403_for_other_organization`,
`test_relancer_commissions_returns_403_without_permission`,
`test_relancer_commissions_returns_403_for_other_organization`,
`test_relancer_commissions_redirects_with_success_when_nothing_to_regularize`). 70 tests verts sur
le code d'origine avant tout déplacement (192 assertions).

**Vérifications après extraction** :
- Import ET registration de route mis à jour ensemble dans `routes/web.php` :
  `Route::resource('ventes', CommandeVenteController::class)->except([])` (7 routes générées) + les
  5 routes explicites déjà existantes (`check-solvabilite`, `distributions.index`,
  `distributions.show`, `valider`, `annuler`, `commissions.relancer`) remplacés par 12
  déclarations explicites, URI/nom/méthode/nom de paramètre strictement identiques à l'original
  (`{vente}` sur show/edit/update/destroy, `{commande_vente}` sur valider/annuler/
  commissions.relancer — jamais « normalisé »). `vendor/bin/pint` vert, `route:list` ciblé sur
  `ventes`/`distributions` : les 12 routes résolvent vers les nouveaux contrôleurs ; `route:list`
  complet (541 routes, inchangé).
- 70 tests verts après déplacement (192 assertions) — résultat strictement identique à la
  baseline.
- Régression élargie : `CommandeVenteAuditTest` (6), `CommandeVenteStatutTest` (32),
  `CommissionMoteurGeneriqueMultiProcessusTest` (13), `VehiculePremiereRegularisationTest` (9),
  `SolvabiliteImpayesTest` (21) → 81 tests verts, 311 assertions.
- `npm run` typecheck (`vue-tsc --noEmit`) : vert. Wayfinder régénéré sans erreur (aucun diff —
  déjà à jour, watcher Vite l'avait déjà régénéré). Aucune page `.vue` à modifier :
  `Ventes/{Index,Create,Edit,Show}.vue`/`Distributions/Show.vue` utilisent `route('ventes.xxx',
  ...)` (Ziggy, par nom de route), jamais un import Wayfinder nommé par classe — noms de route
  strictement inchangés.
- OpenAPI : non concerné (routes dans `routes/web.php`, hors périmètre `api_path.include: 'api'`).
- **E2E** : plusieurs specs existent (`vente-controle-impayes-flow`, `vente-stock-insuffisant`,
  `facture-flow`, `vente-filtre-statut`, `vente-parametrage-chargement`, `filtre-agence-kouria`,
  `clickable-datatable-row`, `commissions/*`) mais n'ont pas été relancées localement (harnais e2e
  local en panne de démarrage, cf. Lots 26/29/31 — timeout systématique dans `global-setup.ts`, non
  spécifique à ce lot). Risque jugé faible : relocation pure, 70 + 81 = 151 tests PHPUnit
  strictement identiques avant/après.
- **97 références obsolètes** à `CommandeVenteController` (nom de classe supprimé, décrivant un
  comportement ACTUEL — de très loin le contrôleur le plus référencé de tout le chantier vu sa
  taille/centralité) corrigées à travers 55 fichiers : 20 fichiers de code de production
  (`app/Policies/CommandeVentePolicy.php`, `app/Models/{CommandeVente,CommissionProcessus,
  Vehicule}.php`, `app/Support/Produits/ProduitFormOptions.php`,
  `app/Support/Ventes/PdvSiteResolver.php`, `app/Enums/{CommunicationEvent,NatureOperation}.php`,
  `app/Services/{GrossisteTarifResolver,PdvCheckoutService,PrixVenteNatureResolver,
  SolvabiliteService,VehiculeCommandeContextResolver,VehiculeCapaciteService,
  CommandeVenteService}.php`, `app/Services/Commission/{CommissionProcessusDefaults,
  CommissionPartageLivraisonCategorieChecker,CommissionEnveloppeGenerator}.php`,
  `app/Http/Controllers/{EquipeLivraisonController,TransfertLogistiqueController}.php`, et 2
  fichiers `database/` — `ElmV2DemoCatalogSeeder.php` et la migration déjà exécutée
  `2026_09_05_090000_add_mode_remise_grossiste_...`), 4 fichiers `docs/*.md` (`commissions.md`,
  `grossiste.md`, `notifications-transactionnelles.md`, `identite-client-personne.md`), 6 fichiers
  `resources/js/**` (`Ventes/{Index,Create,Edit,Show}.vue`, `Distributions/Show.vue`,
  `composables/useVehiculeCommandeTarification.ts`) et 23 fichiers `tests/**`. **2 références
  laissées intactes délibérément** : narration historique décrivant un état antérieur à ce
  chantier (`app/Services/VehiculeCommandeContextResolver.php:33`, consolidation déjà réalisée
  avant ce chantier ; `tests/Feature/CommissionMoteurGeneriqueMultiProcessusTest.php:408`,
  correctif daté du 30/08/2026).

**Point hors périmètre confirmé, non modifié** : la duplication fonctionnelle signalée à
l'ouverture du domaine (correction du 13/09/2026 ci-dessus) entre
`Ventes\{Valider,Annuler}CommandeVenteController` (routes `ventes.valider`/`ventes.annuler`) et
`Ventes\{Avancer,Annuler}StatutVenteController` (routes `ventes.statut.avancer`/
`ventes.statut.annuler`, Lot 28) reste entière après cette relocation — même code, mêmes
différences mineures qu'avant (`MotifAnnulation::validValues()` avec règle `in:` stricte côté
`AnnulerCommandeVenteController`, contre une simple chaîne côté `AnnulerStatutVenteController`).
Signalé pour investigation future, jamais fondu silencieusement pendant ce chantier de pure
relocation.

- **Domaine Commandes & Ventes CLOS** : 18/18 contrôleurs conformes (8 - 1 + 11 = 18,
  `CommandeVenteController` remplacé par ses 11 contrôleurs mono-action). 0 renommage trivial et
  0 multi-actions restant dans ce domaine.

## PAUSE DU CHANTIER — 13/09/2026 (à la demande de l'utilisateur)

Le chantier est mis en pause à la demande explicite de l'utilisateur, qui doit développer et
déployer des fonctionnalités métier et ne veut pas que la réorganisation bloque ses livraisons.
**Aucun nouveau lot n'a été ouvert après ce point.** Cette section fait foi pour toute reprise
future — lire ceci en premier avant de continuer le chantier.

### Lots terminés et vérifiés (rien à refaire)

10 domaines entièrement clos, chacun vérifié (tests + `route:list` + typecheck, détail lot par
lot plus haut dans ce document) :
1. Api/Mobile (Lot 2)
2. Api/Client (Lot 3)
3. Sites & Organisation (Lots 4 à 6)
4. Parametrage (Lot 7)
5. Clients (Lots 8 et 27)
6. Depenses (Lot 9)
7. Divers (Lot 10)
8. Produits & Stock (Lots 1, 11 à 16)
9. Auth & Compte (Lots 17 à 26)
10. **Commandes & Ventes (Lots 28 à 32)** — dernier domaine clos avant la pause, cf. section
    immédiatement au-dessus (Lot 32).

Tout ce travail est présent dans l'arborescence du dépôt (non commité par cette session — cf.
rapport final pour l'état Git exact) mais entièrement fonctionnel et vérifié (tests verts,
routes résolues, typecheck propre).

### Point exact d'arrêt

- Le domaine **Commandes & Ventes** est le dernier domaine complété : `CommandeVenteController`
  (1775 lignes, 11 actions) a été scindé en 11 contrôleurs mono-action + 2 classes Support,
  routes repointées à l'identique, 151 tests verts (70 + 81 de régression élargie), sweep de 97
  références obsolètes effectué (cf. Lot 32 ci-dessus). **Ce domaine est terminé à 100%, aucune
  action requise dessus.**
- Le domaine **RH & Personnel** a été identifié (composition réelle confirmée directement via le
  groupe de routes `Route::middleware('module:'.ModuleFeature::RH)` dans `routes/web.php`,
  lignes 683-714 au moment de cette pause) mais **aucune extraction de contrôleur n'a été
  commencée**. Seule une préparation a été faite : 9 tests de comblement de trous de couverture
  (permission + isolation cross-organisation, y compris une couverture précédemment inexistante
  pour `destroy()`) ont été ajoutés à `tests/Feature/PaieTest.php`, ciblant les actions
  `store()`/`destroy()` de `PaiePaiementController`. **Le fichier contrôleur lui-même n'a pas été
  touché, aucune route n'a été modifiée, aucun nouveau fichier de contrôleur n'a été créé.** Ces
  9 tests passent contre le code actuel inchangé (18/18 verts sur `tests/Feature/PaieTest.php`,
  40 assertions au total) et constituent une amélioration de couverture autonome, sans rapport
  avec un risque de régression de refactoring puisqu'aucun code de production n'a été déplacé.

**Composition confirmée du domaine RH & Personnel** (pour la reprise future — à ne pas
re-rechercher) : 6 contrôleurs, tous multi-actions, 0 déjà conforme, 0 renommage trivial :
1. `PaiePaiementController` (52 lignes, 2 actions : `store`/`destroy`) — tests de comblement déjà
   ajoutés (voir ci-dessus) ; prêt à être scindé en premier lot de reprise.
2. `PaieVariableController` (72 lignes, 3 actions : `store`/`update`/`destroy`).
3. `FonctionRhController` (153 lignes, 4 actions : `index`/`store`/`update`/`toggle`).
4. `ContratController` (207 lignes, 6 actions : `index`/`create`/`store`/`edit`/`update`/
   `destroy`).
5. `PaieController` (227 lignes, **2 actions réellement routées** — `index`/`show`, legacy
   lecture seule — **sur 9 méthodes publiques au total** : `create`/`store`/`calculer`/
   `valider`/`marquerPaye`/`cloturer`/`destroy` existent dans le fichier mais ne sont enregistrées
   dans AUCUNE route, ni `routes/web.php` ni `routes/api.php` (confirmé par recherche exhaustive)
   — le commentaire du groupe de routes RH le confirme explicitement : « Paie (legacy — lecture
   seule, gestion déplacée dans Comptabilité > Salaires) ». **Point d'attention pour la
   reprise** : décider explicitement du sort de ces 7 méthodes orphelines (suppression pure —
   sans impact fonctionnel puisque déjà inatteignables — ou signalement à l'utilisateur avant
   suppression), jamais les recopier silencieusement dans un nouveau contrôleur mono-action.
6. `EmployeController` (454 lignes, 8 actions : `index`/`create`/`store`/`show`/`edit`/`update`/
   `transfererSite`/`destroy`) — le plus gros du domaine, à traiter en dernier.

Total : 1165 lignes cumulées de code multi-actions — domaine nettement plus petit que Commandes
& Ventes.

**Pièges à éviter à la reprise** :
- `LivreurController` et `EquipeLivraisonController` (souvent associés mentalement à « RH »)
  appartiennent en réalité au module `VEHICULES` (donc très probablement au domaine
  **Logistique**, pas RH & Personnel) — confirmé par leur groupe de routes dans `routes/web.php`
  (aux alentours des lignes 418-499 au moment de cette pause). Ne pas les inclure dans RH &
  Personnel par erreur à la reprise.
- `Comptabilite\PaiementFicheController`, `Comptabilite\PaiementFichePaiementController`,
  `Comptabilite\PaiementPeriodeController` et `Comptabilite\SalaireController` (paie/paiements
  également) appartiennent au module `COMPTABILITE`, pas RH & Personnel — confirmé par leur
  groupe de routes. Domaine Comptabilité & Commissions à composer en les incluant le moment venu.

### Domaines restants (non commencés)

- **RH & Personnel** — composition confirmée ci-dessus, 0% commencé au niveau extraction de
  code (seuls des tests de comblement ajoutés pour `PaiePaiementController`, cf. ci-dessus).
- **Logistique** — composition à re-vérifier par recherche dédiée avant de commencer (même
  méthode que pour chaque domaine précédent — ne jamais faire confiance à l'inventaire global
  seul, cf. corrections du 13/09/2026 plus haut dans ce document). Inclut probablement
  `LivreurController` et `EquipeLivraisonController` (cf. ci-dessus), `TransfertLogistiqueController`
  et d'autres à confirmer. ~12 contrôleurs multi-actions estimés, ~5491 lignes (chiffre à
  reconfirmer par recherche directe, pas à supposer).
- **Comptabilité & Commissions** — le plus sensible (argent, commissions, paie), traité en
  dernier par choix délibéré. ~21 contrôleurs multi-actions estimés, ~8017 lignes (chiffre à
  reconfirmer), incluant probablement les 4 contrôleurs `Comptabilite\Paiement*`/`Salaire`
  identifiés ci-dessus.

### Reprendre plus tard — instructions

1. Ne PAS re-rechercher la composition de RH & Personnel — elle est confirmée ci-dessus,
   directement via le groupe de routes `module:RH`.
2. Commencer par le plus petit contrôleur du domaine : `PaiePaiementController` (52 lignes,
   2 actions). Les tests de comblement sont déjà écrits et verts (`tests/Feature/PaieTest.php`,
   9 tests ajoutés le 13/09/2026) — ne pas les réécrire, juste vérifier qu'ils passent toujours
   avant de commencer l'extraction (le code a pu évoluer entre-temps, cf. point 6 ci-dessous).
3. Traiter les contrôleurs dans l'ordre croissant de taille (cf. liste ci-dessus), avec la même
   rigueur que tous les lots précédents : tests avant extraction, contrats HTTP préservés à
   l'identique (URI/nom de route/méthode/nom de paramètre), import + enregistrement de route mis
   à jour ensemble dans le même edit, `vendor/bin/pint` + `route:list` avant tout lancement de
   tests, sweep des références obsolètes après suppression du fichier original, documentation
   mise à jour au fur et à mesure (jamais en bloc à la fin).
4. Trancher le sort des 7 méthodes orphelines de `PaieController` (cf. ci-dessus) au moment de
   traiter ce contrôleur précis — ne pas les recopier silencieusement dans un nouveau contrôleur
   mono-action sans décision explicite.
5. Une fois RH & Personnel clos, refaire une recherche dédiée pour Logistique puis Comptabilité &
   Commissions avant de commencer chacun (jamais se fier au comptage agrégé global seul — cf.
   toutes les corrections déjà documentées dans ce fichier pour la raison).
6. **Avant de reprendre quoi que ce soit**, relire l'état Git réel du dépôt (`git log`,
   `git status`, `git diff`) et le code actuel des fichiers concernés : l'utilisateur peut avoir
   commité, poussé, ou modifié manuellement des fichiers entre la pause et la reprise (son
   outillage auto-commit tourne périodiquement, cf. commit `045f0ae6` par exemple, et des
   permissions/abilities ont déjà évolué sur `CommandeVentePolicy.php` pendant cette session même)
   — ne jamais supposer que l'état documenté ici est encore exactement celui du disque.
