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

## Conséquences

- Les commissions existantes ne sont ni recalculées ni modifiées ; sans parrain, rien ne change.
- Le partage Distribution d'un véhicule parrainé est une grille de plus à tenir, alignée sur le
  barème Logistique (le brouillon Logistique le fait reconfigurer).
- Une équipe déplacée sur un autre véhicule garde des lignes désignant l'ancien parrain : ses
  partages deviennent non conformes jusqu'à une nouvelle répartition (refus explicite, jamais un
  paiement au mauvais parrain lors d'une nouvelle opération).
