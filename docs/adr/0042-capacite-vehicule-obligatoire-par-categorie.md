# ADR 0042 — Capacité véhicule obligatoire pour vendre une catégorie

- **Date** : 2026-10-30
- **Statut** : accepté — livré le 2026-10-30 (non commité)
- **Périmètre** : Produits → Catégories, Véhicules, Ventes (web, précommande, PDV) — cf.
  [capacites-vehicule.md](../capacites-vehicule.md)

## Contexte

La capacité de chargement d'un véhicule se configure par catégorie de produit
(`vehicule_capacites`, décision du 17/08/2026). Une catégorie sans plafond sur un véhicule était
considérée **illimitée** pour ce véhicule.

Incident du 30/10/2026 (VTE-301026-029) : le véhicule Lansana était plafonné à 600 pour les
bouteilles, mais rien pour les sachets. 2 700 packs de sachets ont été saisis au lieu de 270 ; la
vente (16 200 000 GNF) a été acceptée, chargée et encaissée sans aucun contrôle.

## Décision

1. Nouvel indicateur sur la catégorie : **`categories.capacite_vehicule_obligatoire`** (« Capacité
   véhicule obligatoire pour vendre »), réglé dans Produits → Catégories. Faux par défaut, pour toutes
   les catégories existantes : l'organisation l'active elle-même.
2. Quand il est actif, une **vente** de produits de cette catégorie avec un véhicule qui n'a **aucune
   capacité configurée pour elle** est refusée côté serveur
   (`VehiculeCapaciteService::verifier(..., exigerCapaciteObligatoire: true)`), pour tous les rôles.
   Appliqué à : création et modification de commande (vente, distribution client, grossiste),
   changement de mode de remise d'une précommande, PDV.
3. **Pas concernés** : les transferts logistiques (ce n'est pas une vente), les catégories sans
   l'indicateur (absence de plafond = illimité, inchangé), les produits sans catégorie.
4. Le réglage se pose sur la **catégorie**, pas sur le produit : la capacité est déjà portée par la
   catégorie (Produit → Catégorie → Capacité véhicule), un nouveau produit de la catégorie suit la
   règle sans configuration.
5. L'enregistrement d'un véhicule n'est **pas** bloqué par une capacité obligatoire manquante (import
   flotte, véhicules logistique) : la fiche et l'éditeur de capacités l'indiquent, la vente est
   refusée tant qu'elle manque.
6. Les commandes existantes ne sont pas modifiées ; le contrôle s'applique aux nouvelles opérations
   et aux modifications.

## Conséquences

- La règle « plafond respecté » et le chargement complet obligatoire (paramètre
  `autorisation_saisie_dessous_qte_max`) restent inchangés : une catégorie obligatoire est contrôlée
  exactement comme une catégorie plafonnée, en plus du refus quand le plafond manque.
- Activer l'indicateur bloque immédiatement les véhicules non configurés : l'écran Catégories affiche
  le nombre de véhicules actifs pouvant vendre (usage Vente, Logistique ou Grossiste) sans capacité
  pour la catégorie, avant et après activation.
- Le formulaire de vente reçoit, par véhicule, la liste `capacites_manquantes` et bloque la
  soumission avec un message rouge ; le serveur reste l'autorité.
