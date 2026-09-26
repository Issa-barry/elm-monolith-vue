# Rapports — rapport d'activité et « Ma situation »

Lot 1 livré le 2026-09-26. Décisions : [ADR 0007](adr/0007-rapport-activite-perimetre-et-regles-de-calcul.md).

## Où

| Écran | Route | Permission | Pour qui |
|---|---|---|---|
| **Ma situation** | `GET /backoffice/ma-situation` (`ma-situation`) | `rapports.read_own` | Chaque utilisateur : ses propres ventes, encaissements, créances et caisse |
| **Rapport d'activité** | `GET /backoffice/rapports/activite` (`rapports.activite`) | `rapports.read` | Responsables : agences accessibles, tous les agents ou un agent |
| Exports | `…/export?format=xlsx\|pdf` (`ma-situation.export`, `rapports.activite.export`) | idem écran | Mêmes filtres, même périmètre que l'écran |

Menu : **Tableau de bord** reste en tête (limité aux agences de l'utilisateur, cf. RAP-010). Groupe
« Pilotage » : **Rapports › Ma situation** et **Rapports › Rapport d'activité** (chaque sous-entrée
selon sa permission ; le menu Rapports n'apparaît que si l'une des deux est accordée). « Ma situation »
reste en accès direct dans le menu mobile `MobileQuickMenu`. Ce n'est jamais un filtre du rapport
d'activité : c'est une vue distincte dont l'agent est imposé côté serveur. Stock, Achats et Dépenses
viendront s'ajouter au menu Rapports (lot 2).

Les deux écrans sont la **même page** (`Rapports/Activite.vue`) alimentée par le **même moteur**
(`App\Services\Rapports\RapportActiviteService`) ; seul le périmètre change.

## Périmètre (RAP-001) — imposé côté serveur

`App\Services\Rapports\RapportPerimetreResolver` est le seul point qui transforme une requête en
périmètre. Les paramètres du navigateur (`site_ids[]`, `agent_id`) ne font que **restreindre** ce que
les droits autorisent ; un export passe par le même résolveur (le nom de route choisit le mode, jamais
un paramètre).

- **Ma situation** : agent = utilisateur connecté, quoi qu'envoie le navigateur ; toutes les agences
  de l'organisation (ce sont ses propres opérations). Ni filtre Agence ni filtre Agent.
- **Rapport d'activité** : agences = `SiteScopeService` (le même mécanisme que la trésorerie : toute
  l'organisation pour un administrateur, ses agences `user_sites` sinon). Une agence demandée hors de
  ce périmètre est ignorée. Filtre Agent = utilisateurs rattachés à ces agences **ou** qui y ont créé
  une vente / enregistré un encaissement ; un agent hors périmètre est ignoré.
- Toujours limité à l'organisation de l'utilisateur.

Barre de filtres (standard `DataFilters`) : **[Agence] → [Agent] → [Période]**.

## Période (RAP-002)

Raccourcis **Aujourd'hui / Hier / Cette semaine / Ce mois** + **Période personnalisée**
(`date_from`, `date_to`, bornes incluses). Résolus **côté serveur** par `SituationPeriode` (la même
classe que l'onglet Situation d'un véhicule), fuseau de l'application (`UTC` = heure de Conakry),
**semaine du lundi au dimanche**. Défaut : Aujourd'hui ; dates invalides ou inversées → Aujourd'hui.
Dans `DataFilters`, c'est le type de champ `period` (paramètre `periode`).

## Blocs — chacun indépendant (RAP-003)

> **Règle** : aucun chiffre n'est déduit par différence entre deux blocs. En particulier, le « reste à
> encaisser » n'est **jamais** `ventes de la période − encaissements de la période` : un agent encaisse
> souvent d'anciennes créances, et l'agent qui encaisse n'est pas forcément celui qui a vendu.

### Ventes (RAP-004)

- **Date d'une vente = date de création de sa facture** (`factures_ventes.created_at`), la même règle
  que le tableau de bord. Un brouillon n'a pas de facture : ce n'est pas une vente. La facture naît à
  la confirmation (commande → « À charger »), à la facturation directe ou au PDV.
- **Agent = créateur de la commande** (`commandes_ventes.created_by`).
- **Hors chiffre d'affaires** : facture annulée, commande annulée, annulée pour erreur de saisie ou
  entièrement retournée — comptées à part (« Annulées / retournées »). Un **retour partiel** est déjà
  déduit : `CommandeVenteService::recalculerTotaux()` réduit le montant net de la facture. Conséquence :
  une annulation ou un retour postérieur modifie le chiffre de la période d'origine.
- Une facture « Créée » (commande confirmée, pas encore livrée) est une vente.
- Encaissé / reste de ces ventes = **état actuel** (paiements reçus après la période compris).

### Encaissements (RAP-005)

- **Date = `date_encaissement`** ; **agent = auteur de l'encaissement** (`created_by`), quelle que
  soit la date ou le vendeur de la vente.
- Totaux par moyen **réellement utilisé** (Espèces, chaque opérateur Mobile Money, Virement, Chèque) ;
  un Mobile Money historique sans opérateur apparaît comme « Mobile Money (opérateur non renseigné) ».
- `date_encaissement` n'a pas d'heure : la colonne **« Saisi le »** vient de `created_at` (moment de
  la saisie, **pas** l'heure du paiement). La date d'encaissement est libre à la saisie
  (`StoreEncaissementVenteController`) : un encaissement saisi un autre jour est **signalé** (orange),
  et peut modifier après coup la situation d'un jour passé.

### Dettes clients — créances (RAP-006)

- Libellé affiché à l'écran et dans les exports : **« Dettes clients »** (ce que les clients doivent
  encore à l'entreprise), terme compris sur le terrain ; « créance » reste le terme du code et de la
  comptabilité (décision du 26/09/2026, simple changement de libellé, aucune règle modifiée).
- Factures **impayées** ou **partielles**, **état actuel, toutes dates confondues** : la période ne
  s'y applique pas, pour que les vieilles dettes restent visibles. Agent = créateur de la vente.
- Une facture « Créée » n'est pas encore une créance (elle devient impayée à la livraison).
- Historique « créances au 15/09 » (reconstitution à une date passée) : hors lot 1.

### Mobile Money (RAP-007)

Encaissements Mobile Money de la période, par opérateur, avec contrôle des références :

| Contrôle | Règle | Affichage |
|---|---|---|
| Référence absente | Encaissement **saisi à partir du 14/09/2026** sans référence (obligatoire depuis cette date) | Avertissement (orange) |
| Sans référence, antérieur | Saisi **avant** le 14/09/2026 : pas une anomalie | Neutre |
| Référence déjà utilisée | Même référence (espaces et casse ignorés) **pour le même opérateur**, **n'importe où dans l'organisation**, même hors période, agence ou agent filtrés | Avertissement (orange) |

Une autre utilisation n'est détaillée (facture, date) que si elle est dans le périmètre de
l'utilisateur ; sinon elle est seulement comptée (« N hors de votre périmètre »). Aucun rapprochement
automatique avec les relevés des opérateurs (hors lot 1).

### Caisse (RAP-008)

Fiche de chaque **caisse dédiée** du périmètre, calculée par `App\Services\Tresorerie\FicheCaisseService`
(composant `components/tresorerie/CaisseFiche.vue`) — **le même calcul et le même composant** serviront
à la fiche caisse (phase 4 du chantier caisses dédiées) : un seul affichage de caisse.

- **Tableau de caisse tiré du grand livre** : solde au début (`soldePourSupport(veille du début)`)
  + mouvements de la période = solde à la fin (`soldePourSupport(fin)`). Jamais « encaissements −
  versements de la période ».
- Mouvements regroupés selon l'**événement réel** de leur pièce : Encaissements espèces, Versements
  envoyés, Versements renvoyés à l'agent (contrepassation d'un versement retourné), Encaissements
  annulés (contrepassation, ex. annulation exceptionnelle), autres contrepassations ; tout autre
  événement garde son libellé comptable.
- **Solde actuel = « à remettre (théorique) »** : aucun comptage physique n'existe encore.
- Versements : en cours (envoyés, hors solde), contestés, versements de la période, dernier versement
  et son ancienneté.
- Vue agence (aucun agent choisi) : une ligne par caisse, sans le détail des écritures ; détail quand
  un agent est ciblé (Ma situation, filtre Agent).
- **Agent sans caisse dédiée** : « Aucune caisse dédiée », jamais un solde à zéro.
- Caisses listées : actives, ou inactives encore concernées (solde, mouvement ou versement en attente).

## Écran et exports

- Chiffres clés : ventes, encaissé, créances en cours, caisse (solde théorique ou « Aucune caisse »).
- Onglets **Ventes / Encaissements / Créances / Mobile Money / Caisse** (onglet porté par l'URL,
  `?tab=`). Au plus **300 lignes** par onglet à l'écran (les plus récentes ; les plus anciennes pour
  les créances) ; les totaux portent toujours sur tout le périmètre ; l'export contient toutes les
  lignes.
- Statuts de facture et de versement : `StatusDot`.
- **Excel** : une feuille par onglet + une feuille Résumé (périmètre, agences, agent, période).
  **PDF** : paysage, mêmes sections.

## Permissions (RAP-009)

| Permission | Rôles types (seeder) | Migration `2026_09_26_100000_backfill_rapports_permissions` |
|---|---|---|
| `rapports.read_own` | admin_entreprise, manager, commerciale, comptable | **Tous les rôles existants**, système et personnalisés |
| `rapports.read` | admin_entreprise, manager, comptable | Rôles types super_admin, admin_entreprise, manager, comptable **seulement** — aucun rattrapage des rôles personnalisés : attribution explicite dans /backoffice/roles |

Domaine « Rapports » dans l'écran des rôles (`PermissionCatalog::DOMAINS`).

## Hors lot 1

Clôture journalière, comptage de caisse, billetage, écart de caisse officiel et sa régularisation
(lot 3 : écart = espèces comptées − solde théorique **figé au moment du comptage**) ; import des relevés
Mobile Money ; rapports Stock / Achats / Dépenses (lot 2) ; créances reconstituées à une date passée.

## Tableau de bord — périmètre d'agence (RAP-010, corrigé le 2026-09-26)

Jusqu'au 26/09/2026, `IndexDashboardController` ne filtrait que sur `organization_id` : un utilisateur
limité à une agence voyait le chiffre d'affaires de toute l'organisation. Corrigé dans un chantier
séparé du lot 1 : le tableau de bord applique le même périmètre que les rapports (`SiteScopeService`) :

- **Administrateur** (super admin, administrateur entreprise) : toute l'organisation, inchangé.
- **Autre utilisateur** : uniquement ses agences (`user_sites`) — statistiques de factures, encaissé,
  reste à encaisser, évolutions mensuelle et quotidienne, CA par site, par type de véhicule et par
  produit (agence de la commande). Une ligne « Chiffres de vos agences : … » l'indique sous l'en-tête.
- Une facture sans agence n'apparaît que dans la vue organisation.
- Pas de filtre Agence sur le tableau de bord : le périmètre découle des droits.

**Changement visible** dès la mise en production : un responsable qui voyait l'organisation entière
ne voit plus que ses agences.
