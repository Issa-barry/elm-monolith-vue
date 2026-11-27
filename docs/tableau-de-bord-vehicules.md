# Tableau de bord du parc (Stat-Véhicules)

> **Statut** : en place (27/11/2026, V1) · Menu **Tableau de bord → Stat-Véhicules**
> (`/backoffice/tableau-de-bord/vehicules`, route `dashboard.vehicules`).

Vue **strictement consultative** du parc : elle lit les véhicules, leurs capacités et leurs équipes,
les ventes, les transferts et les dépenses, sans rien modifier. **Aucune migration** : la V1 n'utilise
que les données existantes. Elle ne crée aucune règle : « opérationnel » reprend les contrôles que le
serveur applique déjà à la saisie d'une vente ou d'un transfert.

| Élément | Fichier |
|---|---|
| Contrôleur | `app/Http/Controllers/Divers/IndexDashboardVehiculesController.php` |
| Agrégations | `app/Services/Vehicules/TableauDeBordVehiculesService.php` |
| Page | `resources/js/pages/DashboardVehicules.vue` |
| Cartes, tableaux, graphique | `resources/js/components/dashboard/vehicules/` ; cartes : `shared/LignesIndicateursApollo.vue`, partagé avec Stat-Stock et Stat-RH |
| Filtres (partagés avec les autres tableaux de bord) | `SelecteurPeriode.vue`, `SelecteurAgence.vue` |
| Tests | `tests/Feature/DashboardVehiculesTest.php`, `tests/Feature/Periodes/RegistreFiltresPeriodeTest.php`, `resources/js/components/dashboard/vehicules/__tests__/`, `resources/js/pages/__tests__/DashboardVehicules.spec.ts`, `resources/js/components/__tests__/AppSidebarTableauDeBord.spec.ts` ; hors CI : `tests/ui/dashboard-vehicules.spec.ts` |

## Lots

| Lot | Contenu | État |
|---|---|---|
| V1 | Parc, opérationnels et à configurer, en mission, rotations, utilisation, capacités et chargements par catégorie, retours et écarts de réception, dépenses et coût par rotation | **En place** |
| V2 | Indisponibilités (panne, immobilisation) avec historique : taux de disponibilité, durée d'immobilisation | À faire (migration) |
| V3 | Documents administratifs et échéances | À faire (migration) |
| V4 | Maintenance structurée, kilométrage, litres de carburant | À faire (migration) |
| V5 | Historique des affectations (agence, équipe) | À faire (migration) |
| V6 | GPS | À étudier (matériel et fournisseur) |

## Décisions (27/11/2026)

1. **Agence selon le bloc** : l'état du parc suit l'**agence actuelle** du véhicule
   (`vehicules.site_id`) ; l'activité suit l'**agence de l'opération** : agence de la vente
   (`commandes_ventes.site_id`), agence de **départ** du transfert (`site_source_id`), agence de la
   dépense (`depenses.site_id`).
2. **Périmètre** : les agences de l'utilisateur, comme Stat-Stock et Stat-RH (plus strict que la
   liste Véhicules, qui montre toute l'organisation).
3. **Finances** : dépenses seulement. Le chiffre d'affaires et les commissions restent dans
   Stat-Ventes et Stat-Commissions.
4. **Opérationnel** : les conditions du serveur, opération par opération, sans critère ajouté.

## Accès et périmètre

- **Module** Véhicules actif et permission **`vehicules.read`** (celle de la liste des véhicules).
  Aucune permission dédiée.
- **Un bloc par source** : chaque bloc d'activité ou de dépenses n'est **ni calculé ni envoyé** sans
  la permission et le module de sa source.

  | Bloc | Condition |
  |---|---|
  | Parc, opérations possibles, capacités, véhicules à configurer | `vehicules.read` |
  | Ventes : rotations de vente, de distribution et de livraison grossiste, quantités, retours, « en mission » en vente | `ventes.read` et module Ventes |
  | Transferts : rotations, quantités, retours, écarts de réception, « en mission » en transfert | `logistique.read` et module Logistique |
  | Dépenses, coût par rotation | `depenses.read`, module Dépenses et `DepensePerimetre` |

  Sans aucune des deux sources d'activité, la ligne Activité, le graphique et les colonnes
  d'activité ne sont pas affichés ; la page indique les sources non affichées faute d'accès.
- **Agences** : toutes les agences de l'organisation avec la vision de toutes les agences
  (`User::voitToutesLesAgences()`), sinon les agences de rattachement (`SiteScopeService`). Une
  agence hors périmètre demandée dans l'URL est refusée avec un message. Le sélecteur n'est pas
  affiché avec une seule agence.
  - Parc : véhicules dont l'agence actuelle est dans le périmètre (et l'agence choisie).
  - Activité : opérations enregistrées dans une agence du périmètre (et l'agence choisie).
  - « En mission » : véhicules du parc ; opérations limitées au périmètre de leur module (ventes :
    agence de la vente ; transferts : départ ou arrivée dans une agence de l'utilisateur).
  - Dépenses : `DepensePerimetre` **et** agences du tableau de bord ; la permission de lire les
    dépenses de toutes les agences n'élargit pas un tableau de bord limité à ses agences.
- **Période** : celle des autres tableaux de bord (`Periode::TABLEAU_DE_BORD`, dates libres
  comprises), « Ce mois » par défaut. Elle s'applique aux rotations, aux quantités, aux retours et
  aux dépenses ; l'état du parc est toujours celui du jour.
- Toujours limité à l'organisation de l'utilisateur.

## Règles de calcul

### État du parc (à ce jour)

Un véhicule supprimé n'est jamais dans le parc. Seul statut existant : **actif** ou **inactif**
(`is_active`). Pour un véhicule actif, chaque opération est possible ou non selon les contrôles du
serveur :

| Opération | Usage | Conditions supplémentaires (contrôle serveur) |
|---|---|---|
| Vente standard | Vente | Capacités obligatoires renseignées (ADR 0042, `VehiculeCapaciteService::capacitesObligatoiresManquantes`) ; **aucun chauffeur exigé** |
| Distribution client | Logistique | Équipe active avec un chauffeur actif (`CommandeVenteFormBuilder::motifChauffeurIndisponible`) ; capacités obligatoires |
| Livraison grossiste | Grossiste (ADR 0023) | Capacités obligatoires |
| Transfert | Logistique | Aucune (équipe facultative) |

- **Opérationnel** : actif, au moins un usage, et chaque opération de ses usages possible.
- **À configurer** (orange, standard A8) : actif, mais aucun usage, ou au moins une opération
  refusée. Les motifs affichés sont ceux des formulaires.
- Actifs = opérationnels + à configurer.
- Distribution et transfert reposent tous deux sur l'usage Logistique : un camion sans chauffeur
  actif peut transférer mais pas distribuer ; il est « à configurer ».
- **En mission** : véhicule actif du parc avec une vente en chargement ou en livraison, ou un
  transfert en chargement ou en transit. Une vente reste « en livraison » jusqu'à son premier
  encaissement (`livree_at`) : ce chiffre compte aussi les livraisons faites mais pas encore
  encaissées.

### Rotations (période)

Une **rotation** est un départ chargé du véhicule :

- **vente** avec un véhicule, partie (statuts Livraison en cours, Livrée, À encaisser, Clôturée,
  Retournée), datée du **chargement validé** (`chargement_valide_at`), ou, pour une vente au
  comptoir (PDV) partie en livraison dès sa création, de sa **validation** (`validated_at`). Les
  ventes non parties, annulées ou annulées pour erreur de saisie ne comptent pas ;
- **transfert** avec un véhicule, au **départ réel** (`date_depart_reelle`, au jour près), hors
  annulés.

Type d'une vente, même règle que les commissions (`CommissionProcessusDefaults::identiteCodePourVente`) :
distribution client, sinon **livraison grossiste** (client grossiste, nature figée sur la vente ou à
défaut celle de la fiche client, en mode livraison), sinon vente standard. Une livraison grossiste
faite avant l'ADR 0023 (09/10/2026) par un véhicule sans l'usage Grossiste reste comptée en livraison
grossiste.

- **Jours d'activité** (jours-véhicule) : couples (véhicule, jour) distincts, toutes opérations
  confondues. Un véhicule qui distribue et transfère le même jour compte **un** jour.
- **Taux d'utilisation** : véhicules **actifs du parc** ayant au moins une rotation comptée ÷
  véhicules actifs du parc. Le dénominateur est le parc du jour.
- **Rotations avec retour** : vente retournée, ou avec un retour de livraison
  (`commande_vente_retours`, nature `retour_livraison`) ; transfert retourné, ou avec un retour au
  site source (`transfert_retours`). Une **régularisation** après encaissement (ADR 0043) corrige une
  erreur de saisie : ce n'est pas un retour.
- **Écarts de réception** : lignes de transfert réceptionnées dont reçu + retourné − chargé est
  différent de zéro (même règle que Stat-Stock, ADR 0032), pour les transferts **partis** dans la
  période (Stat-Stock les date à la réception).

### Quantités par catégorie

Les quantités ne s'additionnent **jamais** d'une catégorie de produit à l'autre (sachets,
bouteilles…) : une ligne par catégorie, sans total.

- **Capacité du parc** : somme des capacités maximales (`vehicule_capacites`) des véhicules actifs
  du parc ; nombre de véhicules plafonnés pour la catégorie.
- **Chargé** : vente, quantité chargée, sinon demandée (même règle que le stock), moins la quantité
  retirée par une régularisation ; transfert, quantité chargée.
- **Remplissage** : chargé ÷ capacité, sur les seuls chargements contenant la catégorie et faits
  par un véhicule plafonné pour elle. Un véhicule sans capacité pour la catégorie n'entre pas dans le
  calcul (ni au numérateur ni au dénominateur).
- **Retourné** (hors régularisation) et taux de retour (retourné ÷ chargé).
- Un produit sans catégorie forme la ligne « Sans catégorie », sans capacité.

### Dépenses

Dépenses au statut **Validé**, bénéficiaire Véhicule, datées dans la période (`date_depense`),
agence de la dépense. **Coût par rotation** = dépenses validées ÷ rotations de la période dans les
mêmes agences ; tiret sans accès à l'activité ou sans rotation. Une dépense n'est pas répartie entre
catégories.

## Contenu de la page

- **Cartes** (`LignesIndicateursApollo`) :
  - Parc : véhicules actifs, opérationnels, en mission, à configurer ;
  - Activité : rotations, taux d'utilisation, jours d'activité, rotations avec retour ;
  - Dépenses : dépenses validées, coût par rotation, véhicules concernés.
- **Disponibilité et activité par opération** : par opération, véhicules qui ont l'usage, opérations
  possibles et bloquées, rotations, véhicules sortis, jours-véhicule. Une note explique la livraison
  grossiste et dit s'il n'y a aucun véhicule d'usage Grossiste ou aucune livraison sur la période,
  plutôt qu'un zéro sans explication.
- **Rotations** : barres empilées par opération, par jour jusqu'à 31 jours, par mois au-delà
  (`TranchesPeriode`).
- **Chargement par catégorie** : capacité du parc, chargé, remplissage, retourné, écarts.
- **Véhicules** : un par ligne, les plus actifs d'abord, lien vers la fiche, état en point coloré
  (`StatusDot` : `operationnel`, `a_configurer`, `inactif`). Y figurent les véhicules du parc et ceux
  d'une autre agence qui ont une activité ou une dépense comptée dans les agences du filtre.
- **Véhicules à configurer** : motifs du serveur, en orange.

## Performances

Tout est agrégé en SQL (`GROUP BY` véhicule, type d'opération et jour, ou véhicule et catégorie),
sans charger les ventes en PHP. Le parc (quelques centaines de véhicules) est chargé avec ses
capacités et ses chauffeurs pour appliquer les règles du serveur véhicule par véhicule. Les retours
sont lus dans les tables de retours, bien plus petites que les lignes de vente.

La date de départ (`chargement_valide_at`, `date_depart_reelle`) n'a **pas d'index** (V1 sans
migration). Laissé libre, MySQL lisait toutes les ventes de l'organisation dans l'ordre de l'index
unique `(organization_id, reference)`, par accès aléatoires. Sous MySQL et MariaDB, le service
écarte donc les index `(organization_id, reference)`, `(organization_id, created_at)` et
`(organization_id, statut)` des ventes et des transferts : un parcours de la table est environ six
fois plus rapide. Ces noms d'index sont ceux des migrations ; SQLite (tests) garde son plan.

Mesure du 27/11/2026 sur une base MySQL 8.4 temporaire : 300 véhicules, 250 000 ventes sur deux ans
(500 000 lignes), 30 000 transferts, 40 000 dépenses, poste local avec 128 Mo de cache InnoDB
(valeur par défaut, défavorable) :

| Période | Rotations | Temps | Dont quantités des ventes |
|---|---|---|---|
| Ce mois | 7 665 | 4,2 s | 2,1 s |
| Ce mois, une agence | 1 555 | 3,1 s | 1,3 s |
| Cette année | 97 654 | 30 s | 17 s |
| Toutes les périodes | 216 096 | 66 s | 36 s |

Le coût suit le nombre de lignes de vente de la période : sur une longue période, la lecture des
lignes domine, quel que soit l'ordre de jointure. Un index sur la date de départ réduirait la lecture
des ventes ; seul un index couvrant sur les lignes accélérerait vraiment les longues périodes. Les
deux seraient des migrations, hors V1.

**Mesure en production (27/11/2026, lecture seule, phpMyAdmin)** : MariaDB 11.8.9, tables InnoDB
(format Dynamic), cache InnoDB du serveur d'environ 100 Go (les tables tiennent en mémoire).
13 340 ventes (activité depuis septembre 2026, environ 6 200 ventes et 7 600 lignes par mois),
environ 14 300 lignes de vente, 1 079 transferts, 168 véhicules. Les six index écartés existent avec
les colonnes attendues. Laissé libre, MariaDB lit déjà la table des ventes en entier : l'exclusion
d'index est sans effet sur MariaDB et reste utile sous MySQL.

| Requête la plus lourde (quantités par véhicule et catégorie) | Durée |
|---|---|
| Novembre 2026 | 0,07 s |
| Année 2026 (toutes les ventes à ce jour) | 0,08 s |
| Lecture complète des ventes, pour comparaison | 0,06 s |

Décision : **aucun index ni migration** à ce volume (seuils retenus : novembre ≤ 1 s et année ≤ 5 s).
Mesure à refaire, avec les mêmes requêtes, vers 100 000 ventes ou dans six mois.

## Limites connues

- **Aucune donnée de panne, d'entretien, de document ni de position GPS** : « opérationnel » ne
  tient compte que de la configuration du véhicule. Un véhicule en panne reste opérationnel tant
  qu'il n'est pas désactivé (V2).
- Capacités **non historisées** : le remplissage d'une période passée utilise la capacité actuelle.
- Catégorie du produit **actuelle** : un produit changé de catégorie déplace son historique.
- L'état du parc est celui du jour ; aucun historique des affectations : un véhicule changé
  d'agence apparaît dans le parc de sa nouvelle agence, son activité passée reste à l'agence de
  l'opération.
- Les transferts sont datés au jour près ; `livree_at` est le premier encaissement, pas la
  livraison physique (non utilisé ici).

## Points relevés pendant la V1, non modifiés

- **Transfert avec un véhicule inactif** : le formulaire ne propose que des véhicules actifs, mais la
  validation serveur du web (`TransfertLogistiqueController`, règle `vehicule_id` à la création et à
  la modification) vérifie l'organisation et l'usage Logistique, pas `is_active`. L'API back-office
  (`Api/Backoffice/Logistique/TransfertsController`) accepte un véhicule facultatif de
  l'organisation, sans contrôle de l'usage ni de l'état. À traiter dans un correctif séparé.
