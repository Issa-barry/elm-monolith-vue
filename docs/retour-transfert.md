# Retour au site source sur un transfert logistique

Décision produit du **16/10/2026** — cf. [ADR 0029](adr/0029-retour-au-site-source-sur-transfert-logistique.md).
Équivalent, pour les transferts, du [retour de livraison d'une vente](retour-commande.md).

## Règle centrale

> Une marchandise partie sur un transfert et revenue au site source est réintégrée au stock du site
> source par une entrée distincte. Les quantités chargée et reçue ne sont jamais modifiées : la
> quantité retournée est cumulée à part. Un retour peut être partiel ou total, en un ou plusieurs
> retours.

## Quand un retour est possible

`TransfertLogistique::raisonRetourImpossible()` est la source de vérité unique (Policy, écran,
service).

| Statut du transfert | Retour possible | Quantité retournable par ligne |
|---|---|---|
| Brouillon, En chargement | non | rien n'est parti |
| Livraison en cours (`transit`) | oui | chargée − retournée |
| Réceptionné (`reception`) | oui | chargée − reçue − retournée |
| Clôturé, Annulé, Retourné | non | — |

En TRANSIT, une quantité reçue déjà saisie (brouillon de réception, reliquat d'une réception
invalidée) n'est pas déduite : la réception n'est pas encore effective.

## Effets d'un retour

Une seule transaction (`TransfertRetourService::enregistrer()`), sous verrou du transfert :

1. **Quantités** — `transfert_lignes.quantite_retournee` cumulée. Les quantités demandée, chargée et
   reçue restent celles d'origine.
2. **Stock** — une ENTRÉE au **site source** par ligne de retour
   (`MouvementStockService::reintegrerRetour()`, source `TransfertRetourLigne`), sans doublon possible
   pour une même ligne de retour. La SORTIE du départ reste intacte : `SORTIE transfert −30`, puis
   `ENTRÉE retour +10`. Journal de stock : « Retour transfert — TRF-… » (filtre `retour_transfert`).
3. **Statut** — si toute la marchandise chargée est revenue alors que le transfert est en TRANSIT,
   il passe en **Retourné** (`retourne`, statut terminal, point orange). En RECEPTION, le statut ne
   change pas.
4. **Traçabilité** — `transfert_retours` (qui, quand, motif, commentaire, site de réintégration,
   quantité, retour total) et `transfert_retour_lignes` ; journal d'activité du transfert
   (`retour_enregistre` / `retourne`).

## Réception et écarts

- **Écart = reçue + retournée − chargée** : négatif = manquant, positif = surplus, 0 = conforme. Sans
  retour, c'est l'ancien écart reçue − chargée.
- **Réception bornée** : dès qu'une ligne a un retour, la quantité reçue ne peut dépasser
  chargée − retournée (`TransfertLogistiqueService::checkReception()`). Sans retour, un surplus reste
  permis, comme avant.
- **Invalidation de la réception** (« renvoyer en livraison ») : toujours permise. Les retours
  restent acquis ; la nouvelle réception est soumise à la même borne.
- **Saisies par étape** : la quantité chargée n'est modifiable qu'en chargement, la réception qu'en
  livraison en cours.

## Commissions (COMM-007 maintenue)

Aucun retour ne recalcule ni n'annule une commission :

- déclencheur **À la réception** (`RECEPTION_EFFECTUEE`, défaut) : la commission porte sur la quantité
  reçue, déjà nette des retours. Un transfert retourné n'est jamais réceptionné : aucune commission ;
- déclencheur **Au chargement** (`CHARGEMENT_VALIDE`) : la commission générée au départ sur la
  quantité chargée **reste due, même si toute la marchandise revient** (choix assumé, provisoire) ;
- monitoring des commissions non générées : un transfert retourné n'est jamais relancé.

## Motif obligatoire

`App\Enums\MotifRetourTransfert` : refus de réception, erreur de chargement, livraison ou transfert
impossible, autre (**commentaire obligatoire**). **Pas de « produit endommagé »** : la marchandise
retournée rejoint le stock disponible ; une marchandise abîmée en est sortie par un ajustement de
stock « Casse » (ou constatée en écart `CASSE` à la réception).

## Permission et autorisations

- Permission dédiée **`logistique.enregistrer_retour`** (Logistique > Chargement / Réception).
  Préréglages : `admin_entreprise`, `manager`. La migration
  `2026_10_16_100100_backfill_logistique_enregistrer_retour_permission` l'accorde aux rôles existants
  qui ont `logistique.update`.
- `TransfertLogistiquePolicy::enregistrerRetour()` : permission + même organisation +
  `isRetournable()` + rattachement au **site source** (ou `isAdmin()`). Le `Gate::before` du super
  admin contourne la Policy : l'écran reçoit `can_enregistrer_retour` revérifié et le service
  revérifie toutes les conditions métier.
- Pas de double validation, pas de suppression ni d'annulation d'un retour. Une erreur se corrige par
  un ajustement de stock tracé.

## Point d'entrée

`POST /backoffice/logistique/{transfert}/retour` (`logistique.retour.store`,
`Logistique\EnregistrerRetourTransfertController`) — `motif`, `commentaire`, `lignes[] = {id,
quantite}`. La fiche transfert (`Logistique/Show`) reçoit `can_enregistrer_retour`, `motifs_retour`,
`transfert.retours[]` et, par ligne, `quantite_retournee`, `quantite_retournable`,
`quantite_recevable_max`.

## Écran

`Logistique/Show.vue` (lot 2, 16/10/2026) :

- bouton **Enregistrer un retour** dans l'en-tête et le menu mobile, icône colis (`PackageMinus`) —
  jamais un simple « Retour » avec une flèche, qui se confondrait avec la navigation vers la page
  précédente ; affiché seulement avec `can_enregistrer_retour` ;
- dialogue `partials/RetourTransfertDialog.vue` : quantité par produit plafonnée au retournable,
  « Tout retourner », motif, commentaire (obligatoire pour « Autre »), colonne « Reste à livrer »
  (livraison) ou « Non justifié » (après réception), avertissement de retour total en livraison ;
  fermeture bloquée pendant l'envoi ;
- onglet **Lignes produits** : colonne « Retourné » dès le premier retour, carte « Retours au site
  source » (motif, date, auteur, site, détail par produit, commentaire) ;
- statut **Retourné** : bandeau orange à la place du suivi des étapes ;
- `partials/ReceptionDialog.vue` (fiche et liste) : quantité reçue pré-remplie à chargé − retourné,
  plafonnée par `quantite_recevable_max`, colonne « Retourné », écart et type d'écart calculés sur
  reçu + retourné − chargé.

## Non couvert

- Renvoi de marchandise depuis la destination après réception : transfert inverse, à étudier.
- Correction / annulation d'un retour.
- Saisie depuis l'application mobile (l'API expose seulement `quantite_retournee` par ligne).

## Fichiers clés

`app/Services/TransfertRetourService.php`, `app/Models/TransfertRetour.php`,
`app/Models/TransfertRetourLigne.php`, `app/Enums/MotifRetourTransfert.php`,
`TransfertLogistique::raisonRetourImpossible()`, `TransfertLigne::quantite_retournable` /
`quantite_recevable_max` / `ecart`, `TransfertLogistiqueService::checkReception()`. Tests :
`tests/Feature/TransfertLogistiqueRetourTest.php`, `tests/Feature/CommissionTriggerLogistiqueTest.php`
(section retour).
