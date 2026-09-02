# Disponibilité et alerte de stock faible

Le stock est décliné par **VARIANTE × SITE** (`variante_stocks`) depuis longtemps déjà. Depuis le
02/09/2026 après-midi, sa configuration par site repose sur **deux notions INDÉPENDANTES**,
volontairement jamais mélangées :

- **DISPONIBILITÉ** — « ce produit est-il vendu/géré sur ce site ? » Défaut **VRAI** partout
  (mode « Tous les sites ») tant qu'aucune restriction explicite n'a été enregistrée.
- **ALERTE** — « faut-il surveiller ce couple (produit, site) et notifier en cas de stock
  faible/rupture ? » Défaut **FAUX** partout, avec un seuil configurable par site.

L'**état physique réel** (Disponible / Stock faible / Rupture / Stock négatif) est calculé par
une fonction **PURE** (`StockStatutService::statutPour()`) qui ne connaît ni l'une ni l'autre —
le stock physique reste réel, toujours, quelle que soit la configuration. Ce sont les
**appelants** (badges d'alerte, notifications, filtres) qui combinent séparément disponibilité
et alerte pour décider ce qu'il faut afficher/notifier :

```text
Disponible sur le site ?
│
├── NON → pas de rupture "métier" possible, pas d'alerte, jamais de notification
│
└── OUI
     │
     └── Alerte active ?
          │
          ├── NON → stock réel affiché (Stock, fiche produit), aucune notification/badge
          │
          └── OUI → stock réel affiché + notification/badge en cas de Stock faible/Rupture
```

## Historique de la décision

- **29/08/2026** — le seuil devient configurable par (produit, site), en remplacement du seuil
  unique `produits.seuil_alerte_stock`.
- **01/09/2026** — l'activation (« être alerté ? ») devient elle aussi configurable par site, en
  remplacement du choix global `produits.alerte_stock_active`.
- **02/09/2026 matin** — tentative de faire dépendre l'état physique lui-même (Rupture incluse)
  de l'activation de l'alerte : un site sans alerte active affichait "Disponible" quelle que soit
  sa quantité réelle. **Rejetée le jour même** : un incident en production a montré qu'un site
  légitimement disponible mais sans alerte (Cimenterie) perdait toute visibilité sur son vrai
  stock, y compris pour les équipes opérationnelles consultant la page Stock — pas seulement les
  destinataires d'alertes.
- **02/09/2026 après-midi** — architecture actuelle : DISPONIBILITÉ (nouvelle) et ALERTE
  (existante) redeviennent deux filtres indépendants appliqués *après* le calcul de l'état
  physique, jamais mélangés dans son calcul.

## Règles métier (IDs)

- **STOCK-ALERTE-001** — Le seuil effectif d'un produit **pour un site donné** est : le seuil
  spécifique défini pour ce couple (produit, site) s'il existe, sinon le seuil global de
  l'organisation (`Parametre::CLE_SEUIL_STOCK_FAIBLE`, 10 par défaut).
- **STOCK-ALERTE-002** — Le seuil d'un site ne s'applique **jamais** à un autre site du même
  produit — même en l'absence de seuil spécifique sur ce dernier (repli direct sur le seuil
  global, jamais sur le seuil d'un site voisin).
- **STOCK-ALERTE-003** — L'état (Disponible / Stock faible / Rupture / Stock négatif) reste
  calculé pour **chaque couple VARIANTE × SITE** indépendamment — un stock élevé ailleurs (autre
  variante, autre site, total organisation) ne doit jamais masquer une alerte locale.
- **STOCK-ALERTE-004** — `StockStatutService::statutPour(qte, seuil): StockStatut` est une
  fonction **PURE** : elle ne prend ni la disponibilité ni l'alerte en paramètre, et ne les
  consulte jamais. Le stock physique reste réel — un site sans alerte active affiche quand même
  son état réel (Rupture comprise) partout où cet état est montré (Stock, fiche produit).
- **STOCK-ALERTE-007** — DISPONIBILITÉ et ALERTE sont deux gardes **INDÉPENDANTES**, toutes deux
  requises pour qu'un couple (produit, site) alimente un badge/compteur d'alerte ou déclenche une
  notification :
  - un site **NON DISPONIBLE** (`ProduitSeuilAlerte.disponible = false`) n'a **jamais** de
    rupture "métier" à afficher ni à notifier, quel que soit son stock physique — le frontend y
    affiche "Non disponible" à la place du statut coloré ;
  - un site **DISPONIBLE mais SANS ALERTE ACTIVE** affiche son état réel normalement (Stock,
    fiche produit) mais ne déclenche **jamais** de notification/badge/compteur.
- **STOCK-ALERTE-005** — Un champ de seuil vidé dans le formulaire, sur un site avec l'alerte
  active, signifie « utiliser le seuil par défaut de l'organisation », jamais `0` implicite et
  jamais bloquant. Un site désactivé (organisationnellement, `Site.statut`) n'apparaît plus dans
  le formulaire de paramétrage, mais ses éventuelles lignes de configuration ne sont pas
  supprimées (pas de perte de configuration en cas de réactivation ultérieure du site).
- **STOCK-ALERTE-006** — L'ALERTE est un choix **explicite par site**, jamais une case cochée
  automatiquement : l'absence de ligne `produit_seuils_alerte` pour un couple (produit, site)
  signifie alerte **INACTIVE** sur ce site. Désactiver un site (`actif = false`) ne supprime
  jamais un seuil spécifique déjà enregistré (même garantie que STOCK-ALERTE-005) : seule la
  ligne est marquée inactive, prête à être réactivée sans ressaisie.
- **STOCK-ALERTE-008** — La DISPONIBILITÉ suit la convention **inverse** de l'alerte : elle
  défaut à **VRAI** en l'absence de ligne (« Tous les sites »), jamais à faux — un produit est
  présumé vendu partout tant qu'un administrateur n'a pas explicitement restreint sa
  disponibilité à une sélection de sites. Rendre un site disponible (`disponible = true`) ne crée
  jamais de ligne s'il n'y a rien d'autre à y enregistrer : le défaut de colonne couvre déjà ce
  cas.

## Champs

| Champ | Table | Rôle |
|---|---|---|
| `alerte_stock_active` | `produits` | **Historique / figé** — ancien choix global « être alerté ? » appliqué à tous les sites. Conservé en base (migration non destructive), plus jamais lu ni écrit par le code applicatif à partir du 01/09/2026. |
| `seuil_alerte_stock` | `produits` | **Historique / figé** — ancien seuil unique appliqué à tous les sites. Conservé en base (migration non destructive), plus jamais lu ni écrit par le code applicatif à partir du 29/08/2026. |
| `disponible` | `produit_seuils_alerte` | DISPONIBILITÉ pour ce couple (produit, site). Absence de ligne = **VRAI** (jamais implicite à faux, cf. STOCK-ALERTE-008). Ajoutée par [`add_disponible_to_produit_seuils_alerte_table`](../database/migrations/2026_09_02_120000_add_disponible_to_produit_seuils_alerte_table.php). |
| `actif` | `produit_seuils_alerte` | ALERTE activée pour ce couple (produit, site). Absence de ligne = **FAUX** (jamais implicite à vrai, cf. STOCK-ALERTE-006). Ajoutée par [`add_actif_to_produit_seuils_alerte_table`](../database/migrations/2026_09_01_120000_add_actif_to_produit_seuils_alerte_table.php). |
| `seuil_alerte_stock` | `produit_seuils_alerte` | Seuil spécifique pour un couple (produit, site) précis. Absence de valeur (`null`) = repli sur le seuil global de l'organisation. Ajoutée par [`create_produit_seuils_alerte_table`](../database/migrations/2026_08_29_120000_create_produit_seuils_alerte_table.php). |
| `seuil_stock_faible` | `parametres` | Seuil global de l'organisation (`Parametre::getSeuilStockFaible()`, 10 par défaut) — inchangé. |

## Résolution — trois fonctions indépendantes

Sur [`StockStatutService`](../app/Services/StockStatutService.php) :

```text
disponible_pour_site(produit, site) = disponible de la ligne (produit, site) si elle existe,
                                       sinon VRAI

alerte_active_pour_site(produit, site) = actif de la ligne (produit, site) si elle existe,
                                          sinon FAUX

seuil_effectif(produit, site) = seuil spécifique (produit, site) si présent
                                 sinon seuil global de l'organisation

statutPour(qte, seuil) = STOCK_NEGATIF  si qte < 0        ← fonction PURE, ne connaît ni
                          RUPTURE        si qte = 0          disponible_pour_site() ni
                          STOCK_FAIBLE   si seuil > 0 et qte <= seuil   alerte_active_pour_site()
                          DISPONIBLE     sinon
```

`statutPour()`/`statutPourVarianteStock()`/`detailParVarianteEtSite()` retournent TOUJOURS
l'état physique réel. `detailParVarianteEtSite()` expose en plus `disponible_sur_site` et
`alerte_active` par ligne, pour que les appelants filtrent selon leur besoin :

- **Affichage opérationnel** (page Stock, tableau "Stock par agence"/"Détail par variante" de la
  fiche produit) — filtré par `disponible_sur_site` UNIQUEMENT : un site non disponible affiche
  "Non disponible" au lieu du statut coloré ; un site disponible affiche toujours son état réel,
  alerte active ou non ;
- **Badges/compteurs/notifications** (bannière rouge Index, badge d'en-tête fiche produit,
  compteur sidebar, email) — filtrés par `disponible_sur_site` **ET** `alerte_active`.

Utilisées par :
- `StockStatutService::detailParVarianteEtSite()` / `nombreAlertesPourProduit()` /
  `compterAlertesPourOrganisation()` (badge sidebar) ;
- `Produit::getIsLowStockAttribute()`/`getIsOutOfStockAttribute()` (filtrés disponible+alerte) ;
- `ProduitController::index()`/`show()` (Web — badges filtrés, tableaux non filtrés) ;
- `StockController::stockQuery()` (page Stock — filtres de statut conditionnés par la
  disponibilité SEULE, jamais l'alerte) ;
- `MouvementStockService::alerterSiFranchissementSeuil()` (notification email/in-app, filtrée
  disponible+alerte) ;
- `ProduitResource` (API) — résolues pour le site **par défaut de l'utilisateur authentifié**
  (repli sur le seuil global / `false` si l'utilisateur n'a aucun site).

## Gestion de la configuration par site

[`ProduitSeuilAlerteService`](../app/Services/ProduitSeuilAlerteService.php) est le seul point
d'écriture, avec deux familles de méthodes totalement indépendantes :

**Disponibilité :**
- `definirDisponibilite(produit, siteId, disponible)` — rendre disponible ne crée une ligne que
  si une autre config (alerte) existe déjà pour ce site (le défaut de colonne — vrai — couvre
  déjà "tous les sites") ; rendre indisponible crée/modifie la ligne, sans jamais toucher
  `actif`/`seuil_alerte_stock` ;
- `definirDisponibilitePourSites(produit, siteIds|null)` — mode "Tous les sites" (`null`, lève
  toute restriction) ou "Sites sélectionnés" (liste : les sites absents deviennent
  explicitement indisponibles) — seul point d'écriture utilisé par le formulaire web.

**Alerte (inchangé depuis le 01/09/2026) :**
- `definir(produit, siteId, actif, seuil)` — active/désactive et fixe le seuil d'UN site, sans
  jamais toucher `disponible` ;
- `definirPourTousLesSitesActifs(produit, actif, seuil)` / `activerPourTousLesSitesActifs(produit, actif)` /
  `definirSeuilSeulPourTousLesSitesActifs(produit, seuil)` — utilisées par l'import en masse (cf.
  ci-dessous) ;
- `valeurUniformePourSitesActifs(produit)` — audit d'import uniquement.

## Formulaire web (fiche produit)

`ProduitController::edit()` charge les sites **actifs** de l'organisation et la configuration
déjà enregistrée (disponibilité + alerte + seuil), exposées à `ProduitForm.vue` en deux sections
distinctes :

- **Disponibilité** — radio "Tous les sites" / "Sites sélectionnés" + cases à cocher par agence
  si "Sites sélectionnés" ;
- **Alerte de stock faible** — ligne Oui/Non + seuil par agence, limitée aux agences marquées
  **disponibles** (pas besoin de surveiller un site où le produit n'est de toute façon pas géré).

Aucune configuration par site n'est possible à la **création** (le produit n'a pas encore d'id) :
un message indique que les deux se configurent après création — disponible partout et sans
alerte par défaut.

`ProduitController::update()` accepte :
- `disponibilite_mode: 'tous'|'selection'` + `sites_disponibles: string[]` →
  `definirDisponibilitePourSites()` ; champ absent du payload = configuration inchangée ;
- `seuils_site: [{site_id, actif, seuil}]` → `definir()` par ligne ; site absent du tableau =
  inchangé.

L'API (`Store`/`UpdateProduitRequest`) n'expose ni l'un ni l'autre : la configuration par site
n'est disponible que depuis le formulaire web. Un produit créé via l'API est donc disponible
partout (défaut) mais sans aucune alerte active tant qu'un site n'a pas été explicitement
configuré depuis le web.

## Import en masse

Le fichier d'import produits ne porte toujours qu'**une seule valeur** par colonne
(`alerte_stock_active`, `seuil_alerte_stock`) par ligne — pas de colonne par site, et **aucune
colonne de disponibilité** (hors périmètre de l'import, reste au défaut "Tous les sites").
`ImportProduitsExecutor` traduit les deux colonnes existantes vers `produit_seuils_alerte`,
appliquées à **tous les sites actifs** de l'organisation, sans jamais réécrire
`produits.alerte_stock_active`/`seuil_alerte_stock` ni toucher `disponible` :
- les deux colonnes présentes sur la ligne → `definirPourTousLesSitesActifs()` (écrase
  uniformément activation + seuil) ;
- seule `alerte_stock_active` présente (ligne de mise à jour) → `activerPourTousLesSitesActifs()`
  (préserve les seuils déjà enregistrés par site) ;
- seule `seuil_alerte_stock` présente (ligne de mise à jour) → `definirSeuilSeulPourTousLesSitesActifs()`
  (ne touche jamais l'activation, ni ne crée de ligne).

## Visibilité multi-agences des administrateurs

Décision produit du 30/08/2026 — un `super_admin`/`admin_entreprise` (`User::isAdmin()`) voit
et est alerté sur les stocks faibles/ruptures de **toutes** les agences de son organisation,
jamais restreint à ses propres agences de rattachement. C'était déjà le cas pour l'affichage
(`ProduitController::index()`/`show()`, `StockController::index()` ne scopent `site_ids` que
pour les non-admins ; le badge sidebar `compterAlertesPourOrganisation()` est org-wide pour tout
le monde) — l'email/notification (ci-dessous) suit la même règle : jamais filtré par site pour
les administrateurs.

## Notification email/in-app aux administrateurs

En plus de l'affichage (badges/pages ci-dessus), un email et une notification in-app
(consommée par l'API mobile) sont envoyés à **tous** les `super_admin`/`admin_entreprise` de
l'organisation quand un couple PRODUIT × SITE **franchit** un état d'alerte — cf.
[`StockAlerteNotification`](../app/Notifications/StockAlerteNotification.php), déclenchée par
[`MouvementStockService::alerterSiFranchissementSeuil()`](../app/Services/MouvementStockService.php),
appelée depuis les deux points d'entrée de mutation du stock (`appliquer()` et
`annulerMouvement()`).

- **Portée** — Stock faible, Rupture et Stock négatif, tous éligibles, mais l'envoi requiert
  DEUX gardes indépendantes vraies (cf. STOCK-ALERTE-007) : le site doit être **disponible**
  pour ce produit ET avoir l'**alerte active**. `statutPour()` lui-même reste pur : ces deux
  gardes sont appliquées par `alerterSiFranchissementSeuil()`, jamais mélangées au calcul.
- **Fréquence** — une seule fois PAR FRANCHISSEMENT, jamais à chaque mouvement tant que le
  produit reste sous le seuil : comparaison du statut (`StockStatutService::statutPour()`) juste
  avant/après le mouvement pour ce couple produit × site ; un nouvel email repart seulement si
  le produit redevient d'abord Disponible puis rebascule en alerte.
- **Interrupteur d'organisation** — `Parametre::CLE_NOTIFICATIONS_STOCK_ACTIVES`
  (`isNotificationsStockActives()`, réglable depuis Paramètres > "Alertes de stock faible",
  défaut : actif) coupe l'envoi pour toute l'organisation quel que soit le produit/site. Reste
  distinct de la disponibilité, de l'activation et du seuil (par site) : ces quatre filtres
  s'appliquent en cascade (organisation → disponibilité du site → alerte du site → seuil du
  site).
- **Résilience** — un échec d'envoi (mail indisponible, etc.) est avalé et journalisé
  (`Log::error`), jamais rethrow : ne doit jamais interrompre le mouvement de stock ni
  l'opération métier appelante (même garantie que
  `CommissionEnveloppeGenerator::alerterCommissionManquante()`).

## Tests

- [`StockStatutServiceTest`](../tests/Unit/StockStatutServiceTest.php) — `statutPour()` pure
  (qte/seuil uniquement), `disponiblePourSite()`/`alerteActivePourSite()` défauts opposés (vrai
  vs faux), isolation entre organisations, `compterAlertesPourOrganisation()` exige disponibilité
  ET alerte indépendamment.
- [`ProduitSeuilAlerteServiceTest`](../tests/Unit/ProduitSeuilAlerteServiceTest.php) — les deux
  familles de méthodes (disponibilité / alerte) n'interfèrent jamais l'une avec l'autre,
  désactiver/restreindre ne supprime jamais l'autre configuration ni un seuil déjà enregistré,
  modes "Tous les sites"/"Sites sélectionnés", application en masse (import), isolation entre
  organisations.
- [`ProduitSeuilAlerteSiteTest`](../tests/Feature/ProduitSeuilAlerteSiteTest.php) — formulaire
  web (disponibilité et alerte, indépendamment), un site disponible sans alerte affiche son état
  réel (Stock faible/Rupture) sans jamais notifier/badge, un site non disponible n'affiche jamais
  de rupture (régression Cimenterie signalée en production le 02/09/2026), configuration
  inchangée après une modification du produit qui ne touche pas la section correspondante, rejet
  d'un site d'une autre organisation.
- [`StockAlerteMultiVarianteSiteTest`](../tests/Feature/StockAlerteMultiVarianteSiteTest.php) —
  scénario de référence multi-variantes × multi-sites, désactiver l'alerte OU restreindre la
  disponibilité d'un site sont deux gestes indépendants qui ne changent jamais l'état physique
  réel, seulement les drapeaux `alerte_active`/`disponible_sur_site` exposés.
- [`StockAlerteNotificationTest`](../tests/Feature/StockAlerteNotificationTest.php) —
  administrateurs alertés même hors de leur agence, aucun spam tant que le produit reste sous le
  seuil, ré-alerte après un retour à Disponible, aucune notification si le site est non
  disponible OU sans alerte (deux gardes testées indépendamment), coupure globale par
  `notifications_stock_actives`.
- [`StockIndexTest`](../tests/Feature/StockIndexTest.php) — le filtre "Rupture de stock" de la
  page Stock inclut un site sans alerte active (stock réel affiché) mais exclut un site non
  disponible.
- [`ProduitApiTest`](../tests/Feature/Api/ProduitApiTest.php) — `alerte_stock_active` résolu côté
  API pour le site par défaut de l'utilisateur authentifié (jamais un choix global).
