# ADR 0035 — La part du parrain est prise sur l'enveloppe Livreur

- **Date** : 2026-10-21
- **Statut** : accepté le 2026-10-20 (décisions métier de l'utilisateur) ; **lots 1 et 2 livrés le
  2026-10-21** (données, moteur de calcul, garde-fous serveur ; écrans et parcours combiné) ; lot 3
  (paiement, comptabilité, trésorerie) livré le 2026-10-23
- **Amende** : [commissions.md](../commissions.md) COMM-005 (la distribution d'un véhicule parrainé a
  son propre partage, le barème reste celui de Logistique) et COMM-019 (pas d'alignement
  automatique pour une équipe parrainée)
- **Détail fonctionnel** : [parrainage-vehicule.md](../parrainage-vehicule.md), règles PARRAIN-007 à
  PARRAIN-015

## Contexte

Le parrain d'un véhicule (phase 1, 07/09/2026) n'était qu'une information. Le métier veut qu'il
touche un montant par pack sur l'activité du véhicule, **sans augmenter la charge de commission** :
la somme doit provenir de l'enveloppe déjà versée à l'équipe de livraison. Le moteur V2 ajoute
chaque bénéficiaire (propriétaire, équipe, site, consultant) avec son propre barème ; une cible
« parrain » de plus aurait donc augmenté le total, et ne pouvait pas varier par véhicule (les
barèmes sont par catégorie et type de véhicule, jamais par véhicule).

## Décision

1. **Le parrain est une ligne du partage d'équipe** (`equipe_livraison_partages_categorie.parrain_id`),
   comme un livreur : GNF fixes par pack, par catégorie et par processus, somme exactement égale au
   barème Livreur. Le total de l'enveloppe ne change donc jamais (quantité × barème). Aucune
   nouvelle table, aucun nouveau barème.
2. **Opérations rémunérées** : toute commission livreur, sauf le transfert logistique entre sites
   (vente, transfert grossiste, distribution client).
3. **Distribution** : elle garde le barème Logistique (COMM-005, un seul barème à maintenir) mais a
   son propre partage pour un véhicule parrainé — le partage Logistique reste sans parrain. La
   génération lit ce partage s'il existe à la date de l'opération, sinon le partage Logistique.
4. **Granularité** : par catégorie, comme tous les bénéficiaires en production (vérifié en
   production le 20/10/2026 : aucun barème par produit). Des montants différents par format (350,
   500, 1 500 ml) seraient un chantier commun à tous les bénéficiaires.
5. **Garde-fou serveur** : la version active d'un partage étant fermée en bloc, toute nouvelle
   version d'un véhicule parrainé doit porter la part du parrain (0 GNF accepté), jamais complétée
   par le serveur ; une ligne parrain obsolète (autre parrain) rend le partage non conforme.
6. **Changements de parrain** : effet le jour même, jamais rétroactif. Ajout : lignes à 0 GNF (et
   partage Distribution copié du partage Logistique). Une part nulle suit le parrain ; **une part
   positive impose de refaire et valider la répartition complète** — refus sinon.
7. **Changement de barème** : une équipe parrainée n'est jamais alignée automatiquement ; une équipe
   sans parrain garde l'alignement automatique (COMM-019).
8. **Validation et paiement** : part validée d'office ; payée sur une fiche « Parrain » à la
   quinzaine (P1 du 1er au 15, P2 du 16 à la fin du mois), comme les livreurs. Cumul de rôles
   autorisé.

## Lot 1 — livré le 2026-10-21

- **Base** : `parrain_id` sur le partage d'équipe et sur les partages préparés d'un brouillon de
  barème (avec `processus_id` pour le partage Distribution), `livreur_id` facultatif ; reprise des
  véhicules déjà parrainés (lignes à 0 GNF, partage Distribution), rejouable.
- **Moteur** : `CommissionEnveloppeGenerator` (part `parrain`, validée d'office),
  `CommissionPartageLivraisonCategorieChecker` (partage selon l'opération, parrain requis, ligne
  obsolète), `PartageLivraisonVersionService` (garde-fou), `PartageParrainService` (ajout,
  remplacement, retrait), `ReconfigurationPartagesService`, `PartageConformiteVehiculesService`,
  `EquipeLivraisonController` (`part_parrain` accepté et exigé), diagnostic des partages.
- Le lot 1 ne devait pas être déployé seul : l'interface d'alors n'envoyait pas la part du parrain.

## Lot 2 — livré le 2026-10-21

- **Parcours combiné** : remplacer (`POST vehicules/{vehicule}/parrain`) ou retirer
  (`DELETE vehicules/{vehicule}/parrain`, nouvelle action) un parrain qui reçoit une part joint la
  nouvelle répartition complète de chaque partage concerné (`repartitions`), validée et enregistrée
  avec le changement dans une seule transaction ; refus sinon, rien n'est enregistré. Les partages à
  refaire sont exposés par `GET vehicules/{vehicule}/parrain/repartitions`. Joindre une répartition
  exige `equipes-livraison.update` en plus de `vehicules.update` (vérifié par le serveur, boutons
  masqués sinon).
- **Écrans** : éditeur unique `CommissionMontantFixeEditor` (parrain, totaux pendant la frappe,
  prorata) dans la modale Équipe, le transfert de véhicule et le parcours combiné ; grille de
  reconfiguration ; onglet Parrain (part par processus et catégorie) ; onglet Distribution des
  véhicules parrainés.
- Le lot 2 ne devait pas être déployé sans le lot 3 (parts de parrain sur aucune fiche).

## Lot 3 — livré le 2026-10-23

- **Période et fiche** : type `parrain` (quinzaine, abréviation `PAR`), `calculerParrains()` (sans
  déduction, agence = site du véhicule, réaffectation ADR 0020), validation automatique.
- **Comptabilité** (choix du 23/10/2026) : compte de charge dédié **622500 « Commissions
  parrains »**, dette 467210, avances 467220 ; événements `fiche_parrain_validee` et
  `paiement_parrain`, réaffectation d'agence ; migration de mise en place idempotente.
- **Trésorerie** : colonnes Parrains P1 / P2 de la prévision (`ParrainObligationContributor`, base
  commune `FichesQuinzaineObligationContributor` partagée avec les livreurs).
- **Écrans** (version minimale, choix du 23/10/2026) : Comptabilité → Commissions → Parrains
  (`fiches/parrains`), périodes Parrains ; pas d'écran « Commissions → Parrains » détaillé.
- **Garde-fou** : la relance d'une génération partielle refuse aussi une période « Parrain »
  clôturée quand l'enveloppe porte une part de parrain.
- **Hors périmètre** : régularisation de clôture comptable (comme sites et consultants, la fiche
  étant validée d'office).

## Lot 4 — vérification du parcours (2026-10-23)

- **E2E** `tests/e2e/commissions/parrain-commission.spec.ts` (organisation dédiée,
  `CommissionE2eFixturesController::parrainCommission`) : ajout du parrain, répartition
  500 / 300 / 200 dans la modale Équipe, vente (part du parrain prise sur l'enveloppe), transfert
  entre sites sans part, période Parrains validée d'office, paiement partiel par la fiche, journal,
  soldes 622500 / 467210 / 467220, Financement de l'agence, fiche complémentaire, fiches livreurs.
- **Tests serveur** (`ParrainFichePaiementTest`) pour les cas difficiles à piloter à l'écran :
  relance d'une génération partielle (part créée une seule fois), période Parrain clôturée qui
  bloque la relance, réaffectation du véhicule vers une autre agence (fiche et dette déplacées).
- **Liste Financement des agences** (E2E) : colonnes Parrains P1 / P2 en « Mois complet », seule la
  colonne de la quinzaine filtrée sinon ; le reste dû du parrain ne se mêle pas aux livreurs.
- **Changement de véhicule d'un livreur** (`EquipeLivraisonTransfertLivreurTest`) : départ d'un
  véhicule parrainé (la part du parrain reste dans la nouvelle répartition de départ), refus en
  bloc si cette part manque, arrivée sur un véhicule parrainé, départ du dernier livreur (équipe
  dissoute, parrain conservé sur le véhicule).
- **Avant tout déploiement** : exécuter en production le contrôle des numéros de compte
  (`SELECT organization_id, numero, libelle FROM compta_comptes WHERE numero IN
  ('622500','467210','467220') AND libelle NOT LIKE '%arrain%';` — résultat attendu : aucune ligne).

## Lot 5 — part du parrain « à définir » (2026-10-23)

Constat : à l'ajout d'un parrain, sa part est à 0 GNF et tous les partages restent « Fait »
(PARRAIN-010, inchangé) ; rien ne distinguait une part pas encore décidée d'une part volontairement
nulle, et la répartition se faisait processus par processus dans la modale Équipe.

Décision (validée le 23/10/2026, PARRAIN-021, révisée par le lot 6) : un état métier « À
définir » / « Définie » — non bloquant dans cette première version, voir le lot 6. Une part
nulle n'est « définie » que si une personne l'a **confirmée** (confirmation portée par la ligne du
parrain depuis le lot 6, vide pour les lignes existantes, perdue à tout changement de parrain). Une
grille unique dans l'onglet Parrain
remplace le passage par la modale Équipe ; enregistrement transactionnel avec les contrôles du
changement de parrain (aucun second moteur). Repérage dans la liste des véhicules (filtre et
repère). Tests : `ParrainPartADefinirTest`, `PartParrainDialog.spec.ts`, parcours E2E mis à jour.

## Lot 6 — garde-fou des ventes (2026-10-23, PARRAIN-022)

Constat : une ligne de parrain à 0 GNF créée par le système rend le partage « Fait » ; rien ne
distinguait une répartition réellement décidée d'une étape oubliée.

Décision (validée le 23/10/2026 ; remplace « aucune opération n'est bloquée » de PARRAIN-012 et
« jamais bloquant » de PARRAIN-021) :
1. Une part à 0 GNF n'est une décision que si une personne l'a confirmée
   (`equipe_livraison_partages_categorie.part_parrain_confirmee_at`) ; une part positive l'est
   toujours. Aucune décision attribuée rétroactivement (lignes existantes vides).
2. Blocage **par partage** (processus × catégorie), porté par le juge commun des partages :
   création, modification, précommande, aperçu, chargement ; jamais le transfert entre sites, ni
   les encaissements, ni les véhicules non parrainés.
3. Confirmation explicite dans la modale Équipe et dans la grille de la part du parrain.
4. **Mise en conformité progressive** : date d'activation par organisation (Paramètres → Ventes),
   vide par défaut ; avant elle, « À répartir » s'affiche sans bloquer. À fixer après comptage des
   véhicules parrainés en production.
5. Les commandes créées avant l'activation sont contrôlées au chargement.

La colonne `vehicules.parrain_part_confirmee_at` du lot 5 (jamais déployée) est abandonnée au profit
de la confirmation par ligne. Tests : `ParrainRepartitionGardeFouTest`, `ParrainPartADefinirTest`.

## Lot 7 — date de début des droits du parrain (2026-10-24, PARRAIN-023)

Besoin : un parrain créé le 5 peut avoir droit à sa part depuis le 1er (ou seulement à partir du 16).

Décision (validée le 24/10/2026 ; remplace « jamais rétroactif » de PARRAIN-012) :
1. Date de début des droits choisie dans la grille de la part du parrain ; future = effet à cette
   date ; passée = répartition antidatée et recalcul **automatique** des commandes depuis.
2. **Option B** : commissions non payées seulement, validées et ajustées à la main comprises ;
   périodes validées rouvertes (contrepassation puis revalidation, mécanisme commun).
3. **Équipe actuelle** appliquée à toute la période rétroactive.
4. Limite : toute période non clôturée (Livreurs et Parrains).

Mise en œuvre : `PartageLivraisonVersionService::versionner()` accepte une date d'effet passée ou
future (versions postérieures bornées à leur début, jamais supprimées) ; régénération ciblée de la
seule enveloppe Livreur (`CommissionEnveloppeGenerator::regenererEnveloppeEquipe()`, même principe
que le retour partiel) ; `RecalculRetroactifParrainService` (exclusions, bilan, réouverture des
périodes). Tests : `ParrainDroitsRetroactifsTest`, `PartParrainDialog.spec.ts`.

Conséquence assumée : un livreur parti de l'équipe pendant la période perd sa part non payée sur
les commandes recalculées ; un ajustement manuel non payé est remplacé par le recalcul.

Revue avant intégration (24/10/2026), corrections : (A1) réouverture des périodes en mode strict
dans la même transaction — un échec annule tout ; (A2) une répartition planifiée n'est remplacée
que depuis la grille, après confirmation ; (A3) journal d'audit des décisions humaines remplacées ;
(A4) recalcul sans effet quand la commission serait identique ; (A6) verrou de la commande pendant
la régénération. Point de procédure (A5) : les parts Livreur recalculées sont à revalider, la
période Livreurs reste ouverte jusque-là.

## Conséquences

- Les commissions existantes ne sont ni recalculées ni modifiées ; sans parrain, rien ne change.
- Le partage Distribution d'un véhicule parrainé est une grille de plus à tenir, alignée sur le
  barème Logistique (le brouillon Logistique le fait reconfigurer).
- Une équipe déplacée sur un autre véhicule garde des lignes désignant l'ancien parrain : ses
  partages deviennent non conformes jusqu'à une nouvelle répartition (refus explicite, jamais un
  paiement au mauvais parrain lors d'une nouvelle opération).
