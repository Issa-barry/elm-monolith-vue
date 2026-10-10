# ADR 0023 — Usage « Grossiste » du véhicule, indépendant de la logistique

- **Date** : 2026-10-09
- **Statut** : accepté le 2026-10-09
- **Remplace** : la règle du 05/09/2026 « Transfert grossiste = véhicules qui font de la logistique,
  jamais un usage propre » ([docs/grossiste.md](../grossiste.md), chantier « Transfert grossiste »)
- **Détail fonctionnel** : [docs/grossiste.md](../grossiste.md), [docs/commissions.md](../commissions.md) (COMM-011)

## Contexte

Le processus de commission `transfert_grossiste` (livraison d'un client grossiste par un véhicule de
flotte) était rattaché à l'usage **Logistique** du véhicule. Deux incohérences en découlaient,
constatées sur la vérification des partages en production du 08/10/2026 :

1. **Les camions de transfert** (Logistique seule) devaient avoir un partage Transfert grossiste —
   9 camions en anomalie « aucun partage » — alors qu'ils ne pouvaient même pas être choisis pour une
   livraison grossiste : la saisie d'une vente proposait aux grossistes la liste des véhicules
   **Vente**.
2. **Les véhicules Vente seule** (tricycles) pouvaient livrer un grossiste sans qu'aucun partage
   grossiste ne soit contrôlé à la création ; la part Livreur restait ensuite « à régulariser ».

## Décision

1. **Troisième usage `livraison_grossiste`** sur le véhicule, à côté de `livraison_vente` et
   `livraison_logistique`. La fiche véhicule décide seule s'il fait de la vente, de la logistique
   (transfert, distribution) ou de la livraison grossiste — un, deux ou trois usages.
2. **`transfert_grossiste` ↔ `livraison_grossiste`** (`CommissionProcessusDefaults::usageVehiculeRequis()`).
   Vente et Transfert logistique/Distribution client sont inchangés.
3. **Saisie d'une vente** : un client grossiste se voit proposer la liste des seuls véhicules
   d'usage Grossiste (création, précommande, modification, passage d'une précommande en livraison).
   Le serveur refuse une livraison grossiste par un véhicule sans cet usage
   (`CommandeVenteFormBuilder::ensureVehiculeAutorisePourGrossiste()`), et l'éligibilité aux
   commissions figée sur la commande suit cet usage (`VehiculeCommandeContextResolver`).
4. **Reprise des véhicules existants** (décision utilisateur du 09/10/2026) : la migration coche
   l'usage Grossiste sur les véhicules déjà **Vente ET Logistique** — ceux qui étaient à la fois
   proposés pour une livraison grossiste et soumis au partage grossiste —, **sur tout véhicule dont
   l'équipe a un partage Transfert grossiste en vigueur**, et **sur tout véhicule qui a déjà livré
   une commande grossiste** (ex. tricycle Vente seule). La reprise ne retire jamais à un véhicule
   une pratique existante ni un partage déjà saisi. C'est une valeur de départ, pas une règle :
   chaque fiche peut ensuite cocher ou décocher l'usage.
4bis. **Transferts logistiques et distributions non concernés** : ils restent rattachés à l'usage
   Logistique (sélection du véhicule, partage, application logistique), inchangés.
5. **Compatibilité** : le champ est facultatif à l'enregistrement d'un véhicule (absent = non coché à
   la création, inchangé en modification) ; les fichiers d'import flotte et de mise à jour des
   véhicules acceptent une colonne `vehicule_livraison_grossiste` facultative.

## Conséquences

- Les 9 camions Logistique seule n'ont plus de partage grossiste exigé (statut « non applicable »).
- Les véhicules Vente seule qui livraient déjà des grossistes gardent l'usage (reprise) ; leur
  partage Transfert grossiste devient exigé comme pour tout autre processus et apparaît « à faire »
  dans la colonne Partages de la liste des véhicules tant qu'il n'est pas saisi.
- Les commandes et commissions déjà enregistrées ne changent pas (instantanés figés).
- Les partages Transfert grossiste déjà saisis restent en base : ils ne sont simplement plus exigés
  pour un véhicule qui n'a pas l'usage.

## Vérification

`tests/Feature/VehiculeUsageGrossisteTest.php` (fiche, liste, saisie, refus serveur, isolation
organisation, reprise), `VehiculeProcessusApplicablesParUsageTest`, `VehiculePartagesCommissionListeTest`,
`CommandeVenteGrossisteCommissionTest`, `useDistributionVehiculePool.spec.ts`.
