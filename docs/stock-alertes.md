# Alerte de stock faible

Le stock est décliné par **VARIANTE × SITE** (`variante_stocks`) depuis longtemps déjà. Le
**seuil** qui déclenche l'alerte « stock faible » l'est aussi (29/08/2026), et depuis le
01/09/2026 l'**activation elle-même** (« être alerté ? ») l'est également : chaque site actif
d'une organisation active ou non l'alerte pour un produit donné, indépendamment des autres
sites, avec repli sur le seuil global de l'organisation quand aucun seuil spécifique n'est
défini sur un site actif.

Décision produit du 01/09/2026 — **en remplacement** du choix global unique par produit
(`produits.alerte_stock_active`, appliqué jusque-là uniformément à tous les sites), pas une
évolution compatible. Un produit peut n'être vendu/géré que dans certains sites : l'alerte ne
doit alors être générée QUE pour ces sites-là, jamais pour l'ensemble de l'organisation par
défaut.

## Règles métier (IDs)

- **STOCK-ALERTE-001** — Le seuil effectif d'un produit **pour un site donné** est : le seuil
  spécifique défini pour ce couple (produit, site) s'il existe, sinon le seuil global de
  l'organisation (`Parametre::CLE_SEUIL_STOCK_FAIBLE`, 10 par défaut).
- **STOCK-ALERTE-002** — Le seuil d'un site ne s'applique **jamais** à un autre site du même
  produit — même en l'absence de seuil spécifique sur ce dernier (repli direct sur le seuil
  global, jamais sur le seuil d'un site voisin).
- **STOCK-ALERTE-003** — L'état (Disponible / Stock faible / Rupture) reste calculé pour
  **chaque couple VARIANTE × SITE** indépendamment (règle préexistante, inchangée) — un stock
  élevé ailleurs (autre variante, autre site, total organisation) ne doit jamais masquer une
  alerte locale.
- **STOCK-ALERTE-004** — RUPTURE reste un fait de disponibilité, calculé indépendamment du choix
  « être alerté ». STOCK_FAIBLE n'est calculé que si l'alerte est **active pour CE SITE**
  (`ProduitSeuilAlerte.actif`, cf. STOCK-ALERTE-006 ci-dessous) — jamais un choix global au
  niveau produit depuis le 01/09/2026.
- **STOCK-ALERTE-005** — Un champ de seuil vidé dans le formulaire, sur un site resté actif,
  signifie « utiliser le seuil par défaut de l'organisation », jamais `0` implicite et jamais
  bloquant. Un site désactivé (organisationnellement, `Site.statut`) n'apparaît plus dans le
  formulaire de paramétrage, mais ses éventuelles lignes de configuration ne sont pas
  supprimées (pas de perte de configuration en cas de réactivation ultérieure du site).
- **STOCK-ALERTE-006** — L'activation de l'alerte est un choix **explicite par site**, jamais
  une case cochée automatiquement : l'absence de ligne `produit_seuils_alerte` pour un couple
  (produit, site) signifie alerte **INACTIVE** sur ce site — ne JAMAIS considérer par défaut
  qu'un site est concerné par un produit. Désactiver un site (`actif = false`) ne supprime
  jamais un seuil spécifique déjà enregistré (même garantie que STOCK-ALERTE-005) : seule la
  ligne est marquée inactive, prête à être réactivée sans ressaisie.

## Champs

| Champ | Table | Rôle |
|---|---|---|
| `alerte_stock_active` | `produits` | **Historique / figé** — ancien choix global « être alerté ? » appliqué à tous les sites. Conservé en base (migration non destructive), plus jamais lu ni écrit par le code applicatif à partir du 01/09/2026. |
| `seuil_alerte_stock` | `produits` | **Historique / figé** — ancien seuil unique appliqué à tous les sites. Conservé en base (migration non destructive), plus jamais lu ni écrit par le code applicatif à partir du 29/08/2026. |
| `actif` | `produit_seuils_alerte` | Alerte activée pour ce couple (produit, site) précis. Absence de ligne = INACTIF (jamais implicite, cf. STOCK-ALERTE-006). Ajoutée par [`add_actif_to_produit_seuils_alerte_table`](../database/migrations/2026_09_01_120000_add_actif_to_produit_seuils_alerte_table.php), avec bascule non destructive : tout produit ayant déjà l'alerte globalement active reçoit une ligne `actif=true` pour chaque site actif de son organisation, afin de préserver le comportement effectif au moment du basculement. |
| `seuil_alerte_stock` | `produit_seuils_alerte` | Seuil spécifique pour un couple (produit, site) précis, exploité seulement si `actif=true` sur cette même ligne. Absence de valeur (`null`) = repli sur le seuil global de l'organisation. Ajoutée par [`create_produit_seuils_alerte_table`](../database/migrations/2026_08_29_120000_create_produit_seuils_alerte_table.php). |
| `seuil_stock_faible` | `parametres` | Seuil global de l'organisation (`Parametre::getSeuilStockFaible()`, 10 par défaut) — inchangé. |

## Résolution de l'activation et du seuil effectifs

Sources uniques, sur [`StockStatutService`](../app/Services/StockStatutService.php) :
- `alerteActivePourSite(Produit $produit, string $siteId): bool`
- `seuilEffectifPourSite(Produit $produit, string $siteId): int`

```
alerte_active(produit, site) = actif de la ligne (produit, site) si elle existe, sinon FAUX

seuil_effectif(produit, site) = seuil spécifique (produit, site) si présent
                                 sinon seuil global de l'organisation
```

Utilisées ensemble par :
- `StockStatutService::detailParVarianteEtSite()` / `statutPourVarianteStock()` / `compterAlertesPourOrganisation()` (badge sidebar) ;
- `ProduitController::index()`/`show()` (Web) ;
- `StockController::stockQuery()` (page Stock — jointure SQL sur `produit_seuils_alerte`, plus sur `produits.alerte_stock_active`/`seuil_alerte_stock`) ;
- `MouvementStockService::alerterSiFranchissementSeuil()` (notification email/in-app) ;
- `ProduitResource` (API) — résolues pour le site **par défaut de l'utilisateur authentifié** (repli sur le seuil global / `false` si l'utilisateur n'a aucun site), l'API n'ayant pas de contexte de site explicite par requête.

## Gestion de la configuration par site

[`ProduitSeuilAlerteService`](../app/Services/ProduitSeuilAlerteService.php) est le seul point
d'écriture :
- `definir(produit, siteId, actif, seuil)` — active/désactive et fixe le seuil d'UN site.
  Désactiver (`actif=false`) ne supprime jamais un seuil déjà enregistré (marqué inactif
  seulement) ; activer avec `seuil=null` replie sur le seuil par défaut, jamais bloquant ;
- `definirPourTousLesSitesActifs(produit, actif, seuil)` — applique la même activation + seuil à
  tous les sites actifs de l'organisation, utilisé **uniquement** par l'import en masse (une
  seule valeur par produit dans le fichier Excel, cf. ci-dessous) ;
- `activerPourTousLesSitesActifs(produit, actif)` — bascule l'activation sur tous les sites SANS
  toucher aux seuils déjà enregistrés (import : ligne de mise à jour qui ne renseigne que la
  colonne activation) ;
- `definirSeuilSeulPourTousLesSitesActifs(produit, seuil)` — met à jour le seuil des sites déjà
  configurés SANS activer/désactiver ni en créer de nouveaux (import : ligne de mise à jour qui
  ne renseigne que la colonne seuil) — un seuil seul, sans activation déjà explicite, n'a aucun
  effet ;
- `valeurUniformePourSitesActifs(produit)` — seuil commun si tous les sites actifs ET activés
  partagent le même seuil spécifique, sinon `null` (mixte/absent) — utilisé uniquement pour
  l'audit d'import, jamais pour le calcul de l'état de stock.

## Formulaire web (fiche produit)

`ProduitController::edit()` charge les sites **actifs** de l'organisation
(`Site::scopeActives()`) et la configuration déjà enregistrée pour ce produit, exposées à
`ProduitForm.vue` (section « Alerte de stock faible ») sous forme d'une ligne compacte par
agence (site + Oui/Non + champ numérique visible seulement si Oui, placeholder = seuil par
défaut de l'organisation).

- Aucune configuration par site n'est possible à la **création** (le produit n'a pas encore
  d'id) : un simple message indique que l'alerte se configure agence par agence après la
  création — aucune agence n'est activée par défaut (cf. STOCK-ALERTE-006).
- `ProduitController::update()` accepte un tableau `seuils_site: [{site_id, actif, seuil}]` :
  chaque ligne → `definir()` avec cette activation/ce seuil ; site absent du tableau → inchangé.
- L'API (`Store`/`UpdateProduitRequest`) n'expose ni l'activation ni le seuil : la configuration
  par site n'est disponible que depuis le formulaire web. Un produit créé via l'API n'a donc
  aucune alerte active tant qu'un site n'a pas été explicitement configuré depuis le web.

## Import en masse

Le fichier d'import produits ne porte toujours qu'**une seule valeur** par colonne
(`alerte_stock_active`, `seuil_alerte_stock`) par ligne — pas de colonne par site (le nombre et
la liste des sites varient par organisation). `ImportProduitsExecutor` traduit ces deux colonnes
vers `produit_seuils_alerte`, appliquées à **tous les sites actifs** de l'organisation,
préservant le comportement historique des anciennes colonnes produit (« s'applique à tous les
sites ») sans jamais réécrire `produits.alerte_stock_active`/`seuil_alerte_stock` :
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
le monde) — ce chantier ajoute l'email/notification correspondant (ci-dessous), avec la même
règle : jamais filtré par site pour les administrateurs.

## Notification email/in-app aux administrateurs

En plus de l'affichage (badges/pages ci-dessus), un email et une notification in-app
(consommée par l'API mobile) sont envoyés à **tous** les `super_admin`/`admin_entreprise` de
l'organisation quand un couple PRODUIT × SITE **franchit** un état d'alerte — cf.
[`StockAlerteNotification`](../app/Notifications/StockAlerteNotification.php), déclenchée par
[`MouvementStockService::alerterSiFranchissementSeuil()`](../app/Services/MouvementStockService.php),
appelée depuis les deux points d'entrée de mutation du stock (`appliquer()` et
`annulerMouvement()`).

- **Portée** — Stock faible (seuil configuré, respecte l'activation PAR SITE) **et** Rupture/
  Stock négatif (toujours calculée, cf. STOCK-ALERTE-004 : la rupture reste un fait de
  disponibilité, indépendant du choix "être alerté").
- **Fréquence** — une seule fois PAR FRANCHISSEMENT, jamais à chaque mouvement tant que le
  produit reste sous le seuil : comparaison du statut (`StockStatutService::statutPour()`) juste
  avant/après le mouvement pour ce couple produit × site ; un nouvel email repart seulement si
  le produit redevient d'abord Disponible puis rebascule en alerte.
- **Interrupteur d'organisation** — `Parametre::CLE_NOTIFICATIONS_STOCK_ACTIVES`
  (`isNotificationsStockActives()`, réglable depuis Paramètres > "Alertes de stock faible",
  défaut : actif) coupe l'envoi pour toute l'organisation quel que soit le produit/site. Reste
  distinct de l'activation (par site) et du seuil (par site) : les trois filtres s'appliquent en
  cascade (organisation → site → seuil du site).
- **Résilience** — un échec d'envoi (mail indisponible, etc.) est avalé et journalisé
  (`Log::error`), jamais rethrow : ne doit jamais interrompre le mouvement de stock ni
  l'opération métier appelante (même garantie que
  `CommissionEnveloppeGenerator::alerterCommissionManquante()`).

## Tests

- [`StockStatutServiceTest`](../tests/Unit/StockStatutServiceTest.php) — résolution de
  l'activation ET du seuil effectifs par site (`alerteActivePourSite()`/
  `seuilEffectifPourSite()`), absence de ligne = inactif jamais implicite, isolation entre
  organisations.
- [`ProduitSeuilAlerteServiceTest`](../tests/Unit/ProduitSeuilAlerteServiceTest.php) — activation/
  désactivation + seuil par site, désactiver ne supprime jamais un seuil déjà enregistré,
  application en masse (sites actifs uniquement) et ses variantes ciblées
  (`activerPourTousLesSitesActifs()`/`definirSeuilSeulPourTousLesSitesActifs()`), valeur
  uniforme, isolation entre organisations.
- [`ProduitSeuilAlerteSiteTest`](../tests/Feature/ProduitSeuilAlerteSiteTest.php) — formulaire
  web (édition, sauvegarde), un site activé (ex. CBA) et un autre laissé désactivé (ex. Lambanyi)
  sur le même produit, seuils différents par site, un site désactivé ne génère jamais d'alerte
  même à stock très faible, configuration inchangée après une modification du produit qui ne
  touche pas `seuils_site`, rejet d'un site d'une autre organisation.
- [`StockAlerteMultiVarianteSiteTest`](../tests/Feature/StockAlerteMultiVarianteSiteTest.php) —
  scénario de référence multi-variantes × multi-sites avec activation/seuils différents par site.
- [`StockAlerteNotificationTest`](../tests/Feature/StockAlerteNotificationTest.php) —
  administrateurs alertés même hors de leur agence, aucun spam tant que le produit reste sous le
  seuil, ré-alerte après un retour à Disponible, rupture alertée même site désactivé pour
  l'alerte, stock faible non alerté si le site est désactivé, coupure globale par
  `notifications_stock_actives`.
- [`ProduitApiTest`](../tests/Feature/Api/ProduitApiTest.php) — `alerte_stock_active` résolu côté
  API pour le site par défaut de l'utilisateur authentifié (jamais un choix global).
