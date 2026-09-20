# Encaissements de vente

## Modes de paiement — `App\Enums\ModePaiement` (4 valeurs stables, ne pas étendre)

`especes`, `mobile_money`, `virement`, `cheque`. **Ne jamais y ajouter une valeur par opérateur**
(Orange Money, Kulu...) — cet enum est partagé bien au-delà des encaissements de vente :

- Comptabilisation : `PlanComptableBootstrapService` seede des comptes de trésorerie (571000
  Caisse, 521000 Banque, 561000 Mobile Money générique + 561100/561200/561300 pour des wallets
  dédiés) et des lignes `compta_mappings` explicitement autour de ces 4 valeurs.
  `CompteMappingResolver` résout le compte via une chaîne de repli sur le motif `"mode:detail"`
  (ex: `"mobile_money:orange"` → compte dédié si configuré → `"mobile_money"` générique → compte
  par défaut de l'événement). `TypeSupportTresorerie::fromMoyenPaiement()` classe le support
  (Caisse/Banque/Mobile Money) à partir de ces mêmes valeurs.
- Paiements sans rapport avec les ventes : commissions (vente/site/propriétaire/consultant),
  cashback, paiement de fiches (livreur/propriétaire), salaires — tous consomment
  `ModePaiement::options()` via `resources/js/components/PaymentDialogCompact.vue`.

**Conséquence directe** : stocker `mode_paiement = "orange_money"` sur un encaissement ferait
échouer la résolution du compte de trésorerie dédié (`CompteMappingResolver` ne reconnaît pas
cette valeur, aucun `":"` à dépouiller) et retomberait sur le compte de trésorerie **par défaut de
l'événement — la Caisse (571000)** : de l'argent réellement reçu en Mobile Money serait compté
comme de l'espèce en caisse. C'est l'erreur qu'a faite une première implémentation de ce chantier
(2026-09-15, corrigée le jour même) — la piste "un seul enum fusionné" doit être écartée pour
cette raison, indépendamment de toute préférence UX.

## Opérateur Mobile Money — `App\Enums\OperateurMobileMoney` (champ séparé)

Champ `operateur_mobile_money` (nullable) sur `encaissements_ventes`, requis uniquement quand
`mode_paiement = mobile_money`. Valeurs : `orange_money`, `kulu`, `soutra_money`, `momo`,
`paycard`, `autre`.

Volontairement séparé de `mode_paiement`, pour deux raisons cumulées :
1. Le marché guinéen compte plusieurs fintechs Mobile Money actives ou émergentes — en faire des
   valeurs de `mode_paiement` obligerait à modifier cet enum partagé à chaque nouvel entrant.
2. Voir la section précédente : la comptabilisation attend `mode_paiement` ∈ {especes,
   mobile_money, virement, cheque}.

**Non câblé dans la comptabilisation pour l'instant** : `VenteComptabilisationService` transmet
toujours `mode_paiement` seul (générique `"mobile_money"`) à `CompteMappingResolver`, qui résout
donc le compte Mobile Money générique (561000), jamais un wallet dédié (561100 Orange Money...)
même si `operateur_mobile_money` est renseigné. Composer un `moyen_paiement` du type
`"mobile_money:".$operateur` (comme le fait déjà `FicheComptabilisationService` pour
`PaiementFichePaiement.moyen_paiement_detail`) est une amélioration possible, non traitée ici —
nécessite une revue dédiée de `VenteComptabilisationService` avant d'être appliquée.

## Référence de paiement obligatoire

Champ `reference_paiement` (nullable, string 190) sur `encaissements_ventes`, requis côté backend
(`StoreEncaissementVenteController`) pour **Mobile Money** et **Virement**. Facultatif pour
**Chèque** et **Espèces**. Le champ `note` reste un commentaire libre, distinct de la référence.

## Une seule liste déroulante côté UI — `resources/js/components/payment/PaymentCard.vue`

L'utilisateur ne voit jamais de sélection en deux temps ("Mobile Money" puis "Opérateur") : une
seule liste "Mode de paiement" propose directement Espèces, Orange Money, Kulu, Soutra Money,
MOMO, PayCard, Virement bancaire, Chèque. En interne, choisir "Orange Money" envoie
`mode_paiement="mobile_money"` + `operateur_mobile_money="orange_money"` au backend — la
distinction UI/API ci-dessus est un choix d'implémentation, pas quelque chose que l'utilisateur a
besoin de comprendre.

`PaymentCard` est utilisé par les quatre écrans qui permettent d'encaisser un paiement de vente
(consolidation du 2026-09-15, remplaçant 4 pop-up dupliquées identifiées le même jour) :

- `resources/js/pages/Ventes/Show.vue`
- `resources/js/pages/Ventes/Index.vue` (avec `info-rows` pour afficher Commande/Montant total)
- `resources/js/pages/Distributions/Show.vue` (même contrôleur backend que Ventes/Show.vue,
  `ShowCommandeVenteController`, `nature_operation` différente)
- `resources/js/pages/Factures/Index.vue`

Toute future page d'encaissement de vente doit réutiliser ce composant plutôt que recréer un
dialog ad hoc.

**`resources/js/components/PaymentDialogCompact.vue` est un composant différent**, partagé par des
paiements sans rapport (commissions, salaires, paie, cashback, paiement de fiches — cf. liste
ci-dessus). Ne pas le confondre avec `PaymentCard`, ne pas lui ajouter la logique de référence de
paiement décrite ici.

## Backend comme source de vérité

La validation (référence/opérateur obligatoires selon le mode) est portée exclusivement par
`StoreEncaissementVenteController` (validation inline, pas de FormRequest dédié) — point de
création unique (`$facture->encaissements()->create()`), quel que soit l'écran appelant. Chaque
frontend reproduit ces règles pour l'UX (champs conditionnels, bouton désactivé) mais n'est jamais
la seule protection.

## Historique affiché

Chaque contrôleur qui expose une liste d'encaissements (`ShowCommandeVenteController`,
`IndexCommandeVenteController`, `IndexFactureVenteController`) inclut `operateur_mobile_money_label`
et `reference_paiement` dans le payload, affichés dans l'historique correspondant (ex: "Mobile
Money (Orange Money)" + colonne Référence).

## Statut affiché de la commande après encaissement complet

Une vente directe reste au statut brut `facturation` (libellé « À encaisser ») jusqu'à sa clôture
automatique, qui attend aussi le versement des commissions (`CommandeVente::cloturerSiComplete()`).
Entre l'encaissement complet et ce versement, la facture est « Payée » alors que la commande
affichait encore « À encaisser » — contradiction corrigée le 19/09/2026.

- `CommandeVente::statutAffichage()` (source unique) renvoie `{value, label}` : pour une commande
  `facturation` dont la facture est `payee`, `commissions_a_verser` / « Commissions à verser »
  (point bleu, comme l'étape « Commissions » de la frise) ; sinon le statut brut et son libellé
  habituel. Une facture non soldée, annulée ou absente garde « À encaisser ».
- Exposé sous `statut_affichage` par `ShowCommandeVenteController` (fiche Ventes et Distributions),
  `IndexCommandeVenteController` (liste desktop et mobile) et la colonne « Statut » de l'export
  `VenteListExport`.
- **Inchangés** : le statut brut `statut`, `statut_label` (API client, mobile, scan), le workflow
  et la règle de clôture. Le filtre « Statut commande » de la liste et de l'export reste basé sur
  le statut brut : filtrer sur `facturation` inclut donc aussi les ventes « Commissions à verser ».
