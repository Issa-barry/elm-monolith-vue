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

**Comptabilisation par wallet (depuis le 24/09/2026)** : `VenteComptabilisationService` transmet
`"mobile_money:<detail>"` à `CompteMappingResolver` (même pattern que `FicheComptabilisationService`
pour `PaiementFichePaiement.moyen_paiement_detail`). Le `<detail>` vient de
`OperateurMobileMoney::detailComptable()`, car les clés du plan comptable diffèrent des valeurs de
l'enum :

| Opérateur (`operateur_mobile_money`) | Clé `compta_mappings` | Compte par défaut |
|---|---|---|
| `orange_money` | `mobile_money:orange` | 561100 Orange Money |
| `momo` | `mobile_money:mtn` | 561200 MTN MoMo |
| `kulu`, `soutra_money`, `paycard` | `mobile_money:<valeur>` | aucun mapping dédié → repli 561000 |
| `autre` | `mobile_money` | 561000 générique |

Pour que l'argent soit visible dans la trésorerie, le support Mobile Money du site doit pointer
sur le compte de l'opérateur (ex. « Mobile Money de Matoto » sur 561100 reçoit l'Orange Money).
Les encaissements comptabilisés **avant** ce changement restent sur 561000 : aucun reclassement
automatique (cf. `encaissements:diagnostiquer-destination`, catégorie « Mobile Money sur compte
générique »).

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

## Comptabilisation : caisse dédiée de l'agent (espèces)

Décision du 2026-09-19 (ADR [0001](adr/0001-caisse-dediee-agent-sous-compte.md), phase 2). Chaque
encaissement génère une pièce `encaissement_vente_recu` (débit trésorerie / crédit client 411,
`VenteComptabilisationService::comptabiliserEncaissementVente()`). La trésorerie débitée est, **par
défaut**, celle du moyen de paiement (`compta_mappings` : espèces → 571000, virement/chèque → 521000,
Mobile Money → 561xxx). Elle devient le **sous-compte de la caisse dédiée de l'agent** quand
`App\Services\Tresorerie\CaisseAgentResolver` désigne une caisse — **toutes** ces conditions :

| Condition | Pourquoi |
|---|---|
| `mode_paiement = especes` | Seul l'argent physiquement détenu par l'agent alimente sa caisse. Mobile Money, virement et chèque gardent leurs supports habituels. |
| L'auteur de l'encaissement (`created_by`) a une caisse dédiée **active** (donc validée — un brouillon ne reçoit rien) | Sans caisse, un **nouvel** encaissement en espèces est refusé (cf. « Espèces : caisse dédiée obligatoire »). Seuls les encaissements déjà enregistrés, ou sans auteur, suivent encore le comportement historique (571000). |
| La caisse est celle du **site de la facture** | La pièce est déjà rattachée au site de la facture ; l'agent a au plus une caisse active par site, donc jamais d'ambiguïté. |
| Date de l'encaissement **≥** date de **mise en service** de la caisse (sa validation, `valide_le`) | Pas de reclassement d'un encaissement antidaté. |
| L'encaissement a été enregistré **après** la mise en service de la caisse | Filet pour les rattrapages comptables (`ComptabiliteRattrapageCommand`), qui repassent sur d'anciens encaissements : l'historique n'est jamais reclassé — y compris ceux enregistrés pendant que la caisse était encore en brouillon. |

- **Crédit** : toujours le compte client 411 — le produit est déjà constaté à la facturation.
- **Journal** : celui d'un encaissement en espèces (« Caisse ») — la ligne client n'en porte pas
  volontairement. Le moteur `EcritureComptableService` accepte pour cela l'option de ligne
  `journal_role` (compte imposé, journal tiré du mapping de ce rôle) ; sans effet pour les autres
  appelants.
- **Suppression d'un encaissement** : la contrepassation reprend les comptes de la pièce d'origine,
  donc l'extourne vise le même sous-compte. Depuis le 24/09/2026, la route
  `DELETE /encaissements/{id}` exige la permission `ventes.annuler_exceptionnel` (auparavant : aucune
  permission, seulement l'organisation). Pour défaire une commande saisie par erreur, utiliser
  l'annulation exceptionnelle (cf. [annulation-exceptionnelle.md](annulation-exceptionnelle.md)),
  qui ajoute les garde-fous caisse/commission/cashback absents de la suppression unitaire.
- **Situation vs Financement** : l'argent ainsi encaissé apparaît dans la Situation (solde de la
  caisse de l'agent) mais n'entre pas dans le « disponible » du Financement tant qu'il n'est pas
  versé à la caisse de l'agence (phase 3).
- Aucune colonne n'a été ajoutée à `encaissements_ventes` : la caisse destinataire se lit dans la
  pièce comptable (Journal financier, filtre par compte).

## Espèces : caisse dédiée obligatoire

Décision du 2026-09-23 (ADR [0001](adr/0001-caisse-dediee-agent-sous-compte.md)). Avant elle, un agent
sans caisse dédiée pouvait encaisser en espèces : l'argent retombait sur le compte partagé 571000 et
personne n'en était responsable. **Un encaissement en espèces exige désormais une caisse dédiée
active de l'auteur sur le site de la facture.**

| Point | Règle |
|---|---|
| Périmètre | `mode_paiement = especes` uniquement. Mobile Money, virement et chèque ne touchent jamais la caisse de l'agent et restent possibles sans caisse. |
| Qui | Tous les rôles, administrateurs et super admin compris : aucun contournement par le rôle (la caisse est celle de l'auteur de l'encaissement, `created_by`). |
| Garde serveur | `CaisseAgentResolver::garantirCaissePourEspeces()`, appelée par `StoreEncaissementVenteController` avant toute écriture. Erreur sur `mode_paiement` (aucune caisse active sur le site de la facture : brouillon, désactivée, autre site ou caisse d'un autre agent) ou sur `date_encaissement` (date antérieure à la mise en service de la caisse). |
| Cohérence | La garde réutilise les conditions du routage comptable : « accepté » ⇔ « comptabilisé dans une caisse dédiée ». Les deux ne peuvent pas diverger. |
| Interface | Le backend expose `peut_encaisser_especes` (fiche vente/distribution : `commande.peut_encaisser_especes` ; listes Ventes et Factures : par ligne). `PaymentCard` reçoit `:especes-disponibles` : l'option Espèces est désactivée, un message ambre permanent l'explique, et aucun autre mode n'est présélectionné à la place. |
| Message | « Vous ne disposez pas d'une caisse active … Contactez votre responsable pour qu'il vous en crée une. » |

- **Créer la caisse** reste une action manuelle : écran Trésorerie → Supports (création en
  brouillon puis validation, permission `tresorerie.valider_supports`, cf. ADR 0002).
- **Historique** : aucun encaissement déjà enregistré n'est reclassé. Le diagnostic des encaissements
  existants est en lecture seule (`php artisan encaissements:diagnostiquer-destination`, options
  `--organization`, `--depuis`, `--detail`, `--tout`, `--csv=`). Il lit la pièce comptable de chaque
  encaissement (compte de trésorerie réellement débité) et range chacun dans **une** catégorie, la
  première qui s'applique :

  | Catégorie | Signification | Statut |
  |---|---|---|
  | Aucun mouvement comptable / écriture contrepassée | Pas de pièce `encaissement_vente_recu` (rattrapage : `comptabilite:rattraper`) | À traiter |
  | Destination non identifiable | Pièce sans ligne de trésorerie débitée | À traiter |
  | Compte incohérent avec le moyen de paiement | Ex. espèces débitées sur un compte Mobile Money (attendu : espèces 571, Mobile Money 561, virement/chèque 521) | À traiter |
  | Montant comptabilisé ≠ montant encaissé | Autre anomalie | À traiter |
  | Espèces hors caisse dédiée | Espèces débitées sur un compte 571 partagé — cas d'avant la règle du 23/09/2026 ou encaissé avant la mise en service de la caisse | À traiter |
  | Compte sans support de trésorerie | Le solde existe au grand livre mais reste invisible dans Situation/Financement (les supports se lisent par compte **et** par site) | À traiter |
  | Mobile Money sur compte générique | Débité sur 561000 : un support existe mais l'opérateur n'est pas distingué | À traiter |
  | Caisse dédiée de l'agent / support identifié | Destination claire et cohérente | Conforme |

  La sortie donne les totaux, les ventilations par agent, agence et moyen de paiement (avec le compte
  réellement débité), et la liste des agents à équiper d'une caisse. Toute régularisation (contrepassation,
  réaffectation) est une décision métier explicite, jamais automatique : elle doit être tracée
  (auteur, date, ancienne et nouvelle destination, motif).

## Backend comme source de vérité

La validation (référence/opérateur obligatoires selon le mode) est portée exclusivement par
`StoreEncaissementVenteController` (validation inline, pas de FormRequest dédié) — point de
création unique (`$facture->encaissements()->create()`), quel que soit l'écran appelant. Chaque
frontend reproduit ces règles pour l'UX (champs conditionnels, bouton désactivé) mais n'est jamais
la seule protection.

## Retour de livraison avant encaissement

Cf. [retour-commande.md](retour-commande.md). Tant qu'aucun encaissement n'a eu lieu, un utilisateur
habilité (`ventes.enregistrer_retour`) peut enregistrer le retour de tout ou partie de la marchandise
chargée : la facture est recalculée sur la quantité **livrée** (chargée − retournée), donc le restant
dû et le plafond d'un encaissement (`montant ≤ montant_restant`) suivent automatiquement. Un retour
**total** passe la commande en `retournee` et **annule** sa facture : plus aucun encaissement n'est
possible (`StoreEncaissementVenteController` refuse une facture annulée), et `isEncaissable()` exclut
ce statut. Un retour est refusé dès le premier encaissement (commande `livree`, ou montant encaissé
non nul) — il n'existe pas de mécanisme d'avoir/remboursement pour un retour **après** encaissement.

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
