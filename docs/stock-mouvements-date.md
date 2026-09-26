# Date métier des mouvements de stock

Depuis le 18/09/2026, chaque mouvement de stock (`mouvements_stock`) porte une **date métier**
(`date`), distincte de `created_at` (horodatage technique, immuable, toujours "maintenant").

## Pourquoi deux dates

- `created_at` répond à « quand cette ligne a-t-elle été écrite en base ? » — jamais modifiable,
  garantit la traçabilité technique.
- `date` répond à « à quelle date cette opération de stock s'est-elle réellement produite ? » —
  pour un **ajustement manuel** (modale « Ajuster le stock »), c'est l'utilisateur qui la choisit
  (ex: rattraper la saisie d'un comptage physique effectué la veille). Pour un mouvement
  **automatique** (vente, transfert, réception), elle vaut systématiquement la date du jour — ces
  flux n'exposent aucun sélecteur de date à l'utilisateur.

## Règles métier (IDs)

- **STOCK-DATE-001** — `date` est **obligatoire** sur tout nouvel ajustement manuel de stock (Web,
  `AjusterStockProduitController`), avec le même message de validation qu'une date de dépense
  (`StoreDepenseRequest`) : « La date est obligatoire. »
- **STOCK-DATE-002** — `date` ne peut **jamais être dans le futur** (`before_or_equal:today`) — un
  ajustement de stock documente un événement déjà survenu, jamais planifié à l'avance. Même
  convention que `date_depense` (`StoreDepenseRequest`/`UpdateDepenseRequest`).
- **STOCK-DATE-003** — `date` n'affecte **jamais** le calcul `stock_avant`/`stock_apres` : ces
  deux valeurs restent basées sur l'état réel actuel de `VarianteStock` au moment de l'écriture,
  jamais rejouées à la date choisie. `date` est un rattachement temporel d'affichage/tri, pas une
  entrée dans le calcul du solde.
- **STOCK-DATE-004** — `MouvementStockService::appliquer()` retombe sur la date du jour
  (`now()->toDateString()`) quand `date` n'est pas fourni — tous les appelants automatiques
  existants (vente, transfert, réception, API mobile `ProduitController::ajusterStock`)
  continuent de fonctionner sans changement.
- **STOCK-DATE-005** — Le contre-mouvement d'une annulation (`annulerMouvement()`) reçoit
  toujours la date du jour, jamais la date métier du mouvement annulé : l'annulation est un
  événement à part entière, survenant "maintenant".

## Compatibilité des mouvements existants

Migration [`2026_09_18_100000_add_date_to_mouvements_stock_table`](../database/migrations/2026_09_18_100000_add_date_to_mouvements_stock_table.php) :
colonne `date` nullable ajoutée, puis backfill de tous les mouvements déjà en base avec
`DATE(created_at)` — aucun mouvement historique ne reste sans date métier après migration.

## Où `date` est affichée/triée

- **Modale « Ajuster le stock »** (`AjusterStockModal.vue`) — champ obligatoire, calendrier
  (PrimeVue `Calendar`, format `dd/mm/yy`), préempli à aujourd'hui, positionné juste après
  « Site » et avant « Augmenter/Diminuer ».
- **Page Stock** (`IndexStockController`, colonne « Dernier mouvement ») et **fiche produit**
  (`ShowProduitController`) — le mouvement le plus récent est désormais résolu par
  `orderByDesc('date')->orderByDesc('created_at')`, plus par `created_at` seul.
- **Historique du stock** (`HistoriqueProduitController`, `HistoriqueModal.vue`) — la colonne
  Date affiche la date métier (`d/m/Y`) en premier, avec l'horodatage technique de création en
  dessous (« Saisi le … ») pour ne jamais confondre les deux.

## Tests

- [`MouvementStockServiceTest`](../tests/Unit/MouvementStockServiceTest.php) — `date` optionnelle
  (repli sur aujourd'hui), `date` explicite persistée telle quelle sans affecter
  `stock_avant`/`stock_apres`.
- [`ProduitTest`](../tests/Feature/ProduitTest.php) (section « ajuster-stock : date métier ») —
  `date` obligatoire, rejet d'une date future ou invalide, date antérieure acceptée et distincte
  de `created_at`, exposition dans l'historique JSON.
