# Réorganisation des contrôleurs — contrôleur mono-action (`__invoke`)

## Objectif

Réorganiser progressivement **tous** les contrôleurs applicatifs du dépôt (web + API) vers un
contrôleur par action HTTP (`__invoke()`), y compris pour le CRUD, regroupés par domaine métier.
Aucun changement de comportement observable (routes, permissions, isolation organisation/agence,
validations, réponses, transactions, effets de bord) pendant cette migration — c'est un
refactoring pur, pas une correction fonctionnelle. Voir l'historique de cadrage dans la
conversation ayant précédé ce document pour le détail des décisions (architecture cible,
exclusions du pilote, ordre des contrôles à préserver par action).

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

## Inventaire global (153 contrôleurs applicatifs)

51 étaient déjà conformes au départ (`__invoke()` unique) — essentiellement `Api/Auth/*`,
`Api/Public/*`, `Api/Backoffice/*` (hors Logistique), la famille `Scan*Controller`, et une partie
de `Api/Client` et `Api/Mobile`. 99 regroupaient plusieurs actions ; **7 entièrement résolus à ce
jour** (Lots 2 à 6, voir Suivi d'avancement) → **92 contrôleurs multi-actions restent à traiter**
(`ProduitController` du pilote en a 11 sur 14 restantes, comptées dans ces 92).

| Domaine | Contrôleurs | Déjà conformes | À traiter | Lignes totales | Priorité |
|---|---|---|---|---|---|
| Api/Auth | 16 | 16 | 0 | 870 | — (fait) |
| Api/Public | 5 | 5 | 0 | 327 | — (fait) |
| Api/Backoffice (hors Logistique) | 2 | 2 | 0 | 107 | — (fait) |
| Recherche | 1 | 1 | 0 | 29 | — (fait) |
| Api/Mobile | 6 | 6 | 0 | 375 | — (fait) |
| Api/Client | 13 | 13 | 0 | 1439 | — (fait) |
| Sites & Organisation | 4 | 4 | 0 | 470 | — (fait) |
| Parametrage (Settings vente/logistique/communication) | 4 | 0 | 4 | 614 | 2 |
| Depenses | 4 | 0 | 4 | 1935 | 3 (DepenseController déjà audité) |
| Clients | 3 | 0 | 3 | 1079 | 3 |
| Divers | 11 | 5 | 6 | 1446 | 3 |
| Produits & Stock | 11 | 4 | 7 | 2805 | 3 (pilote Variantes terminé, 7 restants) |
| Auth & Compte | 12 | 4 | 8 | 1105 | 4 |
| Commandes & Ventes | 6 | 1 | 5 | 2735 | 5 (sensible : tarification/solvabilité) |
| RH & Personnel | 8 | 0 | 8 | 2410 | 5 |
| Logistique | 23 | 11 | 12 | 5491 | 5 (fort couplage véhicule/équipe/commission) |
| Comptabilite & Commissions | 24 | 3 | 21 | 8017 | 6 (le plus sensible : argent, commissions, paie) |

Priorité croissante = domaines les plus simples/isolés d'abord, les plus sensibles en dernier,
conformément à la consigne. Le détail fichier par fichier est disponible en régénérant
l'inventaire (`find app/Http/Controllers -name "*.php" -exec wc -l {} \;` + comptage des méthodes
publiques) — non dupliqué ici pour garder ce document lisible.

## Suivi d'avancement

### Terminé

- **Produits & Stock — pilote Variantes** (`ProduitController::updateVariante/variantesIndex/variantesBulkUpdate`)
  → voir Lot 1 ci-dessous.
- **Api/Mobile — Notifications + Web Push** (`NotificationsController` 3 actions,
  `WebPushSubscriptionsController` 3 actions) → voir Lot 2 ci-dessous.
- **Api/Client — Commandes + Propositions Véhicule** (`CommandesController` 2 actions,
  `PropositionsVehiculeController` 2 actions) → voir Lot 3 ci-dessous.
- **Sites & Organisation — Import + Onboarding + CRUD** (`SiteImportController` 3 actions,
  `OnboardingSiteController` 2 actions, `SiteController` 7 actions) → voir Lots 4, 5, 6
  ci-dessous. **Domaine entièrement clos.**

### En cours / prochain lot

- Parametrage (Settings vente/logistique/communication, 4 contrôleurs), Clients (3), Divers (6)
  — domaines de complexité/sensibilité comparable, avant d'attaquer Auth & Compte,
  Commandes & Ventes, RH & Personnel, Logistique et enfin Comptabilité & Commissions.

### Restant

99 contrôleurs multi-actions au départ. 7 entièrement résolus (fichier supprimé, actions
réparties en contrôleurs mono-action) : `NotificationsController`, `WebPushSubscriptionsController`,
`CommandesController` (Api/Client), `PropositionsVehiculeController`, `SiteImportController`,
`OnboardingSiteController`, `SiteController` → **92 contrôleurs restants**. `ProduitController`
(pilote) a commencé sa transition (3 actions extraites sur 14) mais reste comptabilisé « à
traiter » tant que ses 11
actions restantes (CRUD + stock + historique) n'ont pas été réparties. Le gisement principal est
Comptabilite & Commissions (21 contrôleurs, 8017 lignes)
et Logistique (12 restants, 5491 lignes) — traités en dernier du fait de leur sensibilité (argent,
commissions, paie, véhicules/équipes imbriqués).

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
