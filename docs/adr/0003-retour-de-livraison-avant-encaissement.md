# ADR 0003 — Retour de livraison avant encaissement : stock réintégré, commission réajustée

- **Date** : 2026-09-23
- **Statut** : accepté et livré le 2026-09-23
- **Périmètre** : Ventes standard (`CommandeVente` en livraison, avant tout encaissement) — cf.
  [retour-commande.md](../retour-commande.md)

## Contexte

Un livreur part avec une commande et revient parfois avec tout ou partie de la marchandise. Aucune
notion de retour n'existait : ni statut, ni quantité, ni mouvement de stock. La seule brique voisine
était l'**écart de réception** (`validerReception()`), réservée aux commandes à réception explicite
(distribution client, Grossiste livré) — et qui, par décisions explicites du 30/08/2026, **ne
réintègre jamais le stock** (les unités refusées restent sorties) et n'affecte pas les commissions.
Pour une vente standard, rien ne permettait de constater ce que le client avait réellement reçu.

## Décision

1. **Le retour est un mouvement métier tracé**, jamais une modification de la quantité commandée :
   `quantité livrée = quantité chargée − quantité retournée`, ligne par ligne, avec motif obligatoire,
   auteur et date. Partiel ou total, en un ou plusieurs retours.
2. **Périmètre : toutes les ventes standard** en `livraison_en_cours` avant tout encaissement. Les
   commandes à réception explicite gardent leur mécanisme d'écart de réception et leurs règles.
3. **La marchandise retournée est réintégrée automatiquement au stock disponible**, par une ENTRÉE
   distincte de la SORTIE du chargement. **Cette règle est propre au retour** : elle ne modifie pas la
   décision du 30/08/2026 sur l'écart de réception (les deux mécanismes coexistent, chacun avec sa
   règle de stock).
4. **La commission de vente est calculée sur la quantité facturée** (chargée nette des retours) et
   **réajustée par un retour** : régénérée sur les quantités nettes (retour partiel) ou annulée (retour
   total). Cela **révise** la règle antérieure « jamais recalculée après le chargement » — pour ce seul
   événement. Un paramètre d'organisation modifié ne rend toujours jamais rien rétroactif.
5. **Un retour total passe la commande en `retournee`** (statut terminal, distinct d'`annulee` qui ne
   survient jamais après le départ du véhicule) et **annule la facture**.
6. **La facture déjà comptabilisée est régularisée par une pièce `vente_retour` par retour** (débit
   Ventes, crédit Client), jamais par contrepassation de la pièce d'origine. Comme `vente_facturee`,
   la comptabilisation est **shadow** : un échec ne bloque pas un retour déjà survenu physiquement,
   mais il est tracé (journal d'activité de la commande, avertissement utilisateur), visible dans
   `comptabilite:auditer` et rattrapable par `comptabilite:rattraper --type=retour`, sans double
   comptage (la régularisation n'est due que si la pièce de vente préexistait au retour).
7. **Permission dédiée `ventes.enregistrer_retour`**, backfillée aux rôles qui ont
   `ventes.valider_reception`.

## Décisions d'interprétation à connaître

- **Deux règles explicitement révisées** par le propriétaire du produit le 23/09/2026 : (a) un retour
  réintègre le stock, contrairement à l'écart de réception ; (b) un retour réajuste la commission,
  contrairement à la règle « jamais recalculée ». Les docblocks correspondants ont été mis à jour
  (`CommandeVenteService::validerChargement()`, `CommissionTriggerService`).
- **Commission déjà traitée = retour refusé.** Une commission sortie de `creee` (période de paiement
  validée), ajustée à la main, validée ou versée n'est jamais recalculée ni annulée en douce : le retour
  est refusé tant qu'elle n'est pas régularisée. Choix conservateur (jamais d'engagement effacé) au
  prix d'un blocage possible d'un retour tardif.
- **Pas de « produit endommagé » parmi les motifs** : la marchandise abîmée ne doit pas rejoindre le
  stock disponible ; elle se traite par un ajustement de stock (« Casse »).
- **Pas de double validation** : l'enregistrement par un utilisateur habilité vaut validation.
- **`quantite_livree` sert aux deux mécanismes** (réception explicite : saisie ; vente standard :
  dérivée du retour) : toute la lecture existante (`quantite_effective`, cashback, situation véhicule,
  API client) reflète donc les retours sans changement.

## Alternatives écartées

- **Étendre `validerReception()` aux ventes standard** : cela aurait imposé une étape de constat à
  chaque vente standard alors que le retour est l'exception, et importé la règle « pas de
  réintégration de stock » que le propriétaire du produit ne veut pas pour un retour.
- **Contrepasser la pièce comptable d'origine puis la re-poster au nouveau montant** : impossible
  avec l'idempotence `(organisation, source, événement)` de `compta_pieces`, et double comptage en
  cas de retours successifs.
- **Annuler l'enveloppe de commission et en créer une nouvelle** : l'unicité `(source, cible)` de
  `commission_enveloppes` l'interdit.
- **Réutiliser `MotifAjustementStock::RETOUR`** (ajustement manuel) : c'est un motif d'ajustement
  générique, pas un mouvement rattaché à une ligne de commande — il ne donne ni traçabilité ni
  idempotence par retour.

## Conséquences

- Nouvelle migration de schéma (`quantite_retournee`, tables de retours) et deux migrations de données
  non destructives (permission, mappings comptables `vente_retour`).
- Nouveau statut `retournee` : `StatutCommandeVente`, `StatusDot`, filtres de statut (automatique),
  situation véhicule.
- Les organisations existantes doivent avoir joué les migrations de données pour que la
  comptabilisation des retours fonctionne ; à défaut, les échecs sont journalisés sans bloquer le
  retour (mode shadow) — `php artisan comptabilite:bootstrap` rejoue le provisionnement.
- Reste à traiter séparément : retour après encaissement (avoir / remboursement), enregistrement
  depuis l'application mobile livreur.
