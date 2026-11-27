# Tableau de bord du stock

> **Statut** : en place (25/11/2026, lot S1) · Menu **Tableau de bord → Stat-Stock**
> (`/backoffice/tableau-de-bord/stock`, route `dashboard.stock`).

Vue **strictement consultative** du stock : elle lit l'état (`variante_stocks`) et le journal
(`mouvements_stock`) sans rien modifier. Elle ne crée aucune règle : disponibilité, seuils,
statuts et périmètre sont ceux de la page Stock ([stock-alertes.md](stock-alertes.md)), motifs
ceux de l'historique du stock.

| Élément | Fichier |
|---|---|
| Contrôleur | `app/Http/Controllers/Divers/IndexDashboardStockController.php` |
| Agrégations | `app/Services/Stock/TableauDeBordStockService.php` |
| État du stock, partagé avec la page Stock | `app/Support/Stock/EtatStock.php` (utilisé aussi par `IndexStockController`) |
| Motifs des mouvements, partagés avec l'historique | `app/Services/MouvementStockMotifService.php` (`classify`, `expressionSql`) |
| Page | `resources/js/pages/DashboardStock.vue` |
| Cartes, graphiques et tableaux | `resources/js/components/dashboard/stock/` |
| Filtres (partagés avec les autres tableaux de bord) | `SelecteurPeriode.vue`, `SelecteurAgence.vue`, `TranchesPeriode` |
| Tests | `tests/Feature/DashboardStockTest.php`, `tests/Feature/Periodes/RegistreFiltresPeriodeTest.php`, `resources/js/components/dashboard/stock/__tests__/`, `resources/js/pages/__tests__/DashboardStock.spec.ts` |

## Accès et périmètre

- **Droit** : `produits.read`, module Produits actif, comme la page Stock. Aucune permission dédiée.
- **Périmètre** : celui de la page Stock (`EtatStock::sitesConsultables`) : toutes les agences pour
  la vision toutes agences, sinon les agences de rattachement.
- **Filtre agence** : ces agences ; « Toutes les agences » par défaut ; une agence hors périmètre
  est refusée avec un message. Le sélecteur n'est pas affiché avec une seule agence.
- **Filtre article** : les articles de la page Stock (variantes actives des produits dont le type
  gère le stock), le premier par défaut. Un article inconnu ou hors périmètre est refusé avec un
  message, jamais remplacé en silence.
- **Filtre période** : celui des autres tableaux de bord (`Periode::TABLEAU_DE_BORD`, dates libres
  comprises, bornes incluses), « Ce mois » par défaut. **Il ne concerne que les flux** : l'état du
  stock (disponible, réservé, en transit, alertes) est toujours celui du jour.

Chaque filtre conserve les deux autres.

## Unités : jamais de total entre articles

Le catalogue n'a pas d'unité commune (pack 350 ml, pack 1,5 L, sachets, rouleaux…). Les quantités
ne s'additionnent donc **jamais entre articles** :

- cartes, graphique des entrées et sorties et stock par agence portent sur **l'article choisi** ;
- la synthèse présente chaque article sur sa ligne, **sans ligne de total** ;
- seul le **nombre d'alertes** (couples article × agence) se compte tous articles confondus.

S1 n'affiche aucune valeur financière du stock et ne gère pas le surstock (décisions du
25/11/2026).

## Cartes

Présentation commune à Stat-Stock et Stat-RH (`components/dashboard/shared/LignesIndicateursApollo.vue`,
27/11/2026) : carte `.card` d'Apollo, quatre par ligne, titre de section en tête de chaque ligne ;
icône de l'indicateur dans l'angle du titre, chiffre, détail court sur une ligne, en ambre pour une
alerte à traiter (standard A8, rien n'est bloqué). Ici : la carte « Alertes stock » dès qu'une alerte existe.

| Carte | Formule | Source |
|---|---|---|
| Stock disponible | physique − réservé, somme des agences du filtre | `variante_stocks` via `EtatStock` |
| Stock réservé | `qte_reservee` (commandes confirmées non chargées) | idem |
| En transit | quantités chargées des transferts au statut Transit vers les agences du filtre ; nombre de transferts | `transfert_lignes`, `transferts_logistiques` |
| Alertes stock | couples article × agence **disponibles et à alerte active** dont le statut n'est pas « Disponible » ; ruptures, stocks faibles, négatifs | règle des badges (STOCK-ALERTE-007) |
| Entrées / Sorties | mouvements de la période, hors paires annulées | `mouvements_stock` (date métier `date`) |
| Pertes et casses | sorties aux motifs Perte et Casse | idem |
| Écarts de réception | manquants et surplus des transferts arrivés sur la période (`date_arrivee_reelle`) vers les agences du filtre : reçu + retourné − chargé | `TransfertLigne::ecart`, jamais remis en stock (ADR 0032) |

- **Statut** : `StockStatutService::statutPour(disponible, seuil)` — négatif, rupture, stock faible
  (seuil du produit sur l'agence, sinon seuil de l'organisation), disponible. Aucun calcul propre au
  tableau de bord.
- **Stock physique, réservé et disponible** sont toujours distingués ; le physique figure dans le
  détail de la carte « Stock disponible », l'infobulle du stock par agence et la synthèse.
- **Annulations** : un mouvement annulé (`annule_par_id` renseigné) et son contre-mouvement sont
  tous deux exclus des flux, quelle que soit la période où chacun tombe. L'état du stock, lui, est
  déjà net des deux.
- **Transit et écarts** : les transferts supprimés sont exclus ; un transfert annulé n'a pas
  d'écart.

## Graphiques et tableaux

- **Entrées et sorties** (article choisi) : barres empilées par motif, une barre par jour jusqu'à
  31 jours, par mois au-delà (`TranchesPeriode`) ; entrées au-dessus de zéro (nuances de la couleur
  primaire), sorties en dessous (nuances de rose). Motifs : ceux de l'historique du stock (vente,
  transfert, retour, réception d'achat, après production, perte, casse, don, correction…).
- **Stock par agence** (article choisi) : barres horizontales empilées disponible + réservé (somme
  = physique), du plus gros au plus petit stock physique. Une agence où l'article n'est pas
  disponible n'apparaît que si elle en a encore en stock, signalée dans l'infobulle. Au-delà de la
  hauteur du graphique voisin (une dizaine d'agences), la liste défile dans la carte, légende en
  tête.
- **Synthèse par article** : physique, réservé, disponible, en transit, entrées, sorties, pertes et
  casses, écarts, sans total. L'article choisi est en gras.
- **Alertes de stock** (tous articles) : couples article × agence **disponibles** en stock négatif,
  rupture ou stock faible (règle des écrans opérationnels), les plus graves d'abord ; colonne
  « Alerte » active ou non (seule l'alerte active déclenche badges et notifications). Le bouton
  **Historique** ouvre l'historique existant de la page Stock pour ce couple
  (`HistoriqueModal`, `/backoffice/produits/{produit}/historique`).

Le compteur d'alertes et le tableau diffèrent donc volontairement : le compteur suit la règle des
badges (disponibilité + alerte active), le tableau celle des écrans opérationnels (disponibilité
seule), comme la page Stock.

## Performances

Une seule lecture du journal, quel que soit le volume : les mouvements de la période sont regroupés
par article, tranche du graphique (jour, ou mois au-delà de 31 jours), sens et motif — quelques
milliers de lignes au plus. Les flux de la synthèse et le graphique de l'article choisi en sont
tirés sans relire les mouvements. S'y ajoutent l'état (requête de la page Stock), le transit et les
écarts : six requêtes au total. Aucun mouvement n'est chargé.

Sans migration (décision du lot S1), trois réglages de `TableauDeBordStockService::mouvements()` :

- **Borne de saisie** : la date métier n'est jamais postérieure au jour de saisie
  ([stock-mouvements-date.md](stock-mouvements-date.md), STOCK-DATE-002, 004 et 005 ; anciens
  mouvements : `date = DATE(created_at)`). La condition `created_at >= début de la période`, toujours
  vraie pour les mouvements retenus, ne change pas le résultat ; elle permet de lire la période dans
  l'index `(organization_id, created_at)`, imposé (`FORCE INDEX`), la date métier n'ayant pas
  d'index.
- **Index écarté** : pour « Toutes les périodes », l'index `annule_par_id` (presque toujours NULL)
  est écarté (`IGNORE INDEX`) : MySQL le croisait avec un autre et relisait tout le journal par
  accès aléatoires.
- **Annulations** : la sous-requête qui écarte les contre-mouvements est bornée aux lignes annulées
  (`annule_par_id IS NOT NULL`, impliqué par l'égalité) : MySQL ne matérialise plus que les
  annulations au lieu de tout l'index.

Les indications d'index sont comprises par MySQL et MariaDB ; SQLite (tests) garde son plan.

Mesures du 25/11/2026, MySQL 8.4 local (pool InnoDB de 128 Mo, lecture complète de la table en
8,8 s), base temporaire de 1,5 million de mouvements dont **1 million pour l'organisation mesurée**
(20 agences, 30 articles, ≈ 950 mouvements par jour depuis janvier 2024, 1 % de paires annulées,
30 000 transferts), médiane de 3 appels du service :

| Scénario | Première version | Version livrée |
|---|---|---|
| Admin · Ce mois | 42 248 ms | 993 ms |
| Admin · Cette année | 32 400 ms | 18 210 ms |
| Admin · Toutes les périodes | 44 360 ms | 36 223 ms |
| Admin · Cette année · 1 agence | — | 6 733 ms |
| Responsable (2 agences) · Ce mois | — | 321 ms |
| Responsable (2 agences) · Cette année | — | 6 153 ms |

Le temps croît avec le nombre de mouvements lus : la période par défaut (« Ce mois ») reste sous la
seconde à ce volume ; une année entière ou « Toutes les périodes » sur un million de mouvements
dépasse plusieurs secondes. Un **index couvrant** n'est pas possible aujourd'hui : le motif d'un
ajustement manuel n'est lu que dans `notes` (TEXT), qu'aucun index ne peut contenir. Une colonne de
motif (hors S1) permettrait un index `(organization_id, date, site_id, produit_variante_id, type,
motif, annule_par_id, quantite)` lu sans accès à la table. Pour comparaison, la base locale de
développement ne compte que quelques dizaines de mouvements.

## Limites connues

- Pas de valeur financière du stock ni de surstock (hors S1).
- Les motifs d'ajustement manuel sont lus dans le libellé des notes (aucune colonne de motif, pas
  de migration en S1), comme dans l'historique du stock : une note libre non reconnue est classée
  « Autre motif ».
- L'état du stock n'a pas d'historique : impossible d'afficher le stock à une date passée.
- Longues périodes sur un très gros journal : plusieurs secondes (voir Performances).
- Écarts de réception : `transferts_logistiques` n'a pas d'index sur `date_arrivee_reelle` ; la
  lecture parcourt les transferts de l'organisation (0,4 s à 2,2 s pour 30 000 transferts).
