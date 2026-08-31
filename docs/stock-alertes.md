# Alerte de stock faible

Le stock est décliné par **VARIANTE × SITE** (`variante_stocks`) depuis longtemps déjà. Le
**seuil** qui déclenche l'alerte « stock faible » l'est désormais aussi : chaque site actif
d'une organisation peut définir son propre seuil pour un produit donné, avec repli sur le seuil
global de l'organisation quand aucun seuil spécifique n'est défini pour ce site.

Décision produit du 29/08/2026 — **en remplacement** du seuil unique par produit
(`produits.seuil_alerte_stock`, appliqué jusque-là uniformément à tous les sites), pas une
évolution compatible.

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
  « être alerté » (`alerte_stock_active`, toujours au niveau produit — inchangé par ce
  chantier). STOCK_FAIBLE n'est calculé que si `alerte_stock_active` est vrai.
- **STOCK-ALERTE-005** — Un champ de seuil vidé dans le formulaire signifie « utiliser le seuil
  par défaut de l'organisation », jamais `0` implicite. Un site désactivé n'apparaît plus dans le
  formulaire de paramétrage, mais ses éventuelles lignes de seuil spécifique ne sont pas
  supprimées (pas de perte de configuration en cas de réactivation ultérieure du site).

## Champs

| Champ | Table | Rôle |
|---|---|---|
| `alerte_stock_active` | `produits` | Choix explicite « être alerté si stock faible » — booléen, au niveau produit, inchangé par ce chantier. |
| `seuil_alerte_stock` | `produits` | **Historique / figé** — ancien seuil unique appliqué à tous les sites. Conservé en base (migration non destructive), plus jamais lu ni écrit par le code applicatif à partir du 29/08/2026. |
| `seuil_alerte_stock` | `produit_seuils_alerte` | Seuil spécifique pour un couple (produit, site) précis. Absence de ligne = repli sur le seuil global de l'organisation. Ajoutée par [`create_produit_seuils_alerte_table`](../database/migrations/2026_08_29_120000_create_produit_seuils_alerte_table.php), avec bascule non destructive : tout produit ayant déjà un seuil unique reçoit une ligne équivalente pour chaque site actif de son organisation, afin de préserver le comportement effectif au moment du basculement. |
| `seuil_stock_faible` | `parametres` | Seuil global de l'organisation (`Parametre::getSeuilStockFaible()`, 10 par défaut) — inchangé. |

## Résolution du seuil effectif

Source unique : [`StockStatutService::seuilEffectifPourSite(Produit $produit, string $siteId): int`](../app/Services/StockStatutService.php).

```
seuil_effectif(produit, site) = seuil spécifique (produit, site) si présent
                                 sinon seuil global de l'organisation
```

Utilisé par :
- `StockStatutService::detailParVarianteEtSite()` / `statutPourVarianteStock()` / `compterAlertesPourOrganisation()` (badge sidebar) ;
- `ProduitController::index()`/`show()` (Web) ;
- `StockController::stockQuery()` (page Stock — jointure SQL sur `produit_seuils_alerte`, plus sur `produits.seuil_alerte_stock`) ;
- `ProduitResource` (API) — résolu pour le site **par défaut de l'utilisateur authentifié** (repli sur le seuil global si l'utilisateur n'a aucun site), l'API n'ayant pas de contexte de site explicite par requête.

## Gestion des seuils par site

[`ProduitSeuilAlerteService`](../app/Services/ProduitSeuilAlerteService.php) est le seul point
d'écriture :
- `definir(produit, siteId, seuil)` — crée/modifie/supprime (si `seuil = null`) le seuil d'UN site ;
- `definirPourTousLesSitesActifs(produit, seuil)` — applique la même valeur à tous les sites
  actifs de l'organisation, utilisé **uniquement** par l'import en masse (une seule valeur par
  produit dans le fichier Excel, cf. ci-dessous) ;
- `valeurUniformePourSitesActifs(produit)` — valeur commune si tous les sites actifs partagent
  le même seuil spécifique, sinon `null` (mixte/absent) — utilisé uniquement pour l'audit
  d'import, jamais pour le calcul de l'état de stock.

## Formulaire web (fiche produit)

`ProduitController::edit()` charge les sites **actifs** de l'organisation
(`Site::scopeActives()`) et les seuils déjà enregistrés pour ce produit, exposés à
`ProduitForm.vue` (section « Alerte de stock faible ») sous forme d'une ligne compacte par
agence (site + champ numérique, placeholder = seuil par défaut de l'organisation).

- Aucune configuration par site n'est possible à la **création** (le produit n'a pas encore
  d'id) : seul le choix Oui/Non est proposé, avec la mention que le seuil par défaut de
  l'organisation s'appliquera à toutes les agences jusqu'à la première édition.
- `ProduitController::update()` accepte un tableau `seuils_site: [{site_id, seuil}]` : valeur
  renseignée → `definir()` avec cette valeur ; valeur vide → `definir()` avec `null`
  (suppression, repli sur le défaut) ; site absent du tableau → inchangé.
- L'API (`Store`/`UpdateProduitRequest`) n'expose plus de champ de seuil : la configuration par
  site n'est disponible que depuis le formulaire web.

## Import en masse

Le fichier d'import produits ne porte toujours qu'**une seule valeur** `seuil_alerte_stock` par
ligne (pas de colonne par site — le nombre et la liste des sites varient par organisation).
`ImportProduitsExecutor` applique cette valeur à **tous les sites actifs** de l'organisation via
`definirPourTousLesSitesActifs()`, préservant le comportement historique de l'ancienne colonne
produit (« s'applique à tous les sites ») sans jamais réécrire `produits.seuil_alerte_stock`.

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

- **Portée** — Stock faible (seuil configuré, respecte `alerte_stock_active`) **et** Rupture/
  Stock négatif (toujours calculée, cf. STOCK-ALERTE-004 : la rupture reste un fait de
  disponibilité, indépendant du choix "être alerté").
- **Fréquence** — une seule fois PAR FRANCHISSEMENT, jamais à chaque mouvement tant que le
  produit reste sous le seuil : comparaison du statut (`StockStatutService::statutPour()`) juste
  avant/après le mouvement pour ce couple produit × site ; un nouvel email repart seulement si
  le produit redevient d'abord Disponible puis rebascule en alerte.
- **Interrupteur d'organisation** — `Parametre::CLE_NOTIFICATIONS_STOCK_ACTIVES`
  (`isNotificationsStockActives()`, réglable depuis Paramètres > "Alertes de stock faible",
  défaut : actif) coupe l'envoi pour toute l'organisation quel que soit le produit/site. Reste
  distinct de `alerte_stock_active` (par produit) et du seuil (par site) : les trois filtres
  s'appliquent en cascade (organisation → produit → seuil du site).
- **Résilience** — un échec d'envoi (mail indisponible, etc.) est avalé et journalisé
  (`Log::error`), jamais rethrow : ne doit jamais interrompre le mouvement de stock ni
  l'opération métier appelante (même garantie que
  `CommissionEnveloppeGenerator::alerterCommissionManquante()`).

## Tests

- [`StockStatutServiceTest`](../tests/Unit/StockStatutServiceTest.php) — résolution du seuil
  effectif par site, repli sur le seuil global, isolation entre organisations.
- [`ProduitSeuilAlerteServiceTest`](../tests/Unit/ProduitSeuilAlerteServiceTest.php) — création/
  modification/suppression d'un seuil, application en masse (sites actifs uniquement), valeur
  uniforme, isolation entre organisations.
- [`ProduitSeuilAlerteSiteTest`](../tests/Feature/ProduitSeuilAlerteSiteTest.php) — formulaire
  web (édition, sauvegarde, suppression), rejet d'un site d'une autre organisation, contrôle du
  stock avec le bon `site_id` (même quantité sur deux sites, un seul en alerte selon son propre
  seuil).
- [`StockAlerteMultiVarianteSiteTest`](../tests/Feature/StockAlerteMultiVarianteSiteTest.php) —
  scénario de référence multi-variantes × multi-sites avec seuils différents par site.
- [`StockAlerteNotificationTest`](../tests/Feature/StockAlerteNotificationTest.php) —
  administrateurs alertés même hors de leur agence, aucun spam tant que le produit reste sous le
  seuil, ré-alerte après un retour à Disponible, rupture alertée même `alerte_stock_active`
  désactivé, stock faible non alerté si désactivé, coupure globale par
  `notifications_stock_actives`.
