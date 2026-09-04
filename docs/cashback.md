# Cashback client

Le cashback fonctionne comme une **commission qui appartient au client**, jamais un avantage
global de l'organisation. Chaque client éligible porte son propre montant fixe gagné par pack —
il n'existe pas de montant unique partagé entre tous les clients d'une organisation.

Décision produit du 28/08/2026 — **en remplacement** du modèle précédent (seuil d'achat cumulé
+ gain fixe par organisation, cf. « Historique » ci-dessous), pas une évolution compatible.

## Règles métier (IDs)

- **CASHBACK-001** — Un Revendeur est obligatoirement éligible au cashback.
- **CASHBACK-002** — Tout client éligible possède un montant cashback par pack propre au
  client (`Client::cashback_montant_par_pack`), jamais un montant global d'organisation.
- **CASHBACK-003** — Le montant appliqué est snapshoté lors de la génération
  (`CashbackTransaction::montant_unitaire_snapshot`/`quantite_eligible_snapshot`).
- **CASHBACK-004** — Une modification du montant n'est jamais rétroactive : les gains déjà
  générés conservent le montant qui était en vigueur au moment de leur génération.
- **CASHBACK-005** — La désactivation du cashback (`cashback_eligible = false`) empêche
  uniquement les nouvelles générations.
- **CASHBACK-006** — La désactivation ne supprime, n'annule ni ne recalcule jamais le cashback
  déjà acquis (solde et transactions historiques inchangés).

## Champs

| Champ | Table | Rôle |
|---|---|---|
| `cashback_eligible` | `clients` | Actif/inactif — booléen, existait déjà avant ce chantier. |
| `cashback_montant_par_pack` | `clients` | Montant fixe en GNF gagné par pack éligible pour CE client. `NULL` = non configuré (distinct de `0`). Ajouté par [`add_cashback_montant_par_pack_to_clients_table`](../database/migrations/2026_08_28_181204_add_cashback_montant_par_pack_to_clients_table.php). |
| `montant` | `cashback_transactions` | Montant total du gain (déjà existant) — désormais toujours égal à `montant_unitaire_snapshot × quantite_eligible_snapshot`. |
| `montant_unitaire_snapshot` | `cashback_transactions` | Montant par pack au moment du gain. Ajouté par [`add_snapshot_to_cashback_transactions_table`](../database/migrations/2026_08_28_181210_add_snapshot_to_cashback_transactions_table.php). `NULL` sur les transactions antérieures à ce chantier (ancien modèle à seuil). |
| `quantite_eligible_snapshot` | `cashback_transactions` | Quantité de packs ayant compté pour ce gain. Idem, `NULL` sur l'historique antérieur. |

## Règle Revendeur (CASHBACK-001)

Un client de nature `revendeur` :
- ne peut jamais être enregistré avec `cashback_eligible = false` ;
- ne peut jamais être enregistré avec `cashback_montant_par_pack` vide ou `≤ 0`.

Garanti côté backend par [`CashbackEligibiliteService`](../app/Services/CashbackEligibiliteService.php),
seul point d'entrée appelé par `ClientController::store()`/`update()` — jamais une règle
dupliquée côté frontend uniquement. `ClientForm.vue` reflète cette règle en n'affichant même
plus de choix Oui/Non pour un Revendeur (juste un badge verrouillé « Cashback actif »), pour ne
jamais laisser croire que « Non » serait sélectionnable.

Pour Externe/Distributeur, le cashback est **désactivé par défaut** (`cashback_eligible = false`,
valeur par défaut de la colonne) et reste facultatif — l'utilisateur l'active explicitement au cas
par cas. S'il est activé, le montant par pack redevient obligatoire (même service, même règle,
sans le caractère automatique). Côté formulaire (`ClientForm.vue`), tout changement de nature
réinitialise le toggle à sa valeur par défaut (`true` pour Revendeur, `false` pour Externe/
Distributeur) afin qu'un « Oui » hérité d'un Revendeur quitté ne se propage jamais silencieusement
vers une autre nature.

## Génération — moment et formule

**Moment (inchangé, CASHBACK non concerné)** : le cashback naît au paiement complet de la
facture (`EncaissementVenteController`, transition `!étaitPayée && estPayéeMaintenant`), gardé
derrière `Feature::CASHBACK`. Ce déclencheur préexistait à ce chantier et n'a pas été modifié —
seule la **formule** de calcul change.

**Formule** (`CashbackService::processVente()`) :

```
montant_unitaire   = Client::cashback_montant_par_pack
quantite_eligible  = somme, sur les lignes FABRICABLES de la commande,
                     de (quantite_livree ?? quantite_demandee)
montant_total      = quantite_eligible × montant_unitaire
```

Aucune génération si : pas de client, client non éligible, montant par pack absent/nul, ou
quantité éligible nulle (ex. commande composée uniquement de produits non fabricables).

### Quantité éligible — pourquoi "fabricable"

La notion de "pack" n'est pas déclarée explicitement sur `Produit` — ce chantier réutilise le
repère métier déjà existant pour la tarification par nature de client
(`PrixVenteNatureResolver::estFabricable()`, `ProduitType.code === 'fabricable'`) plutôt que
d'inventer un second indicateur : dans ce catalogue, "fabricable" désigne exactement les
produits vendus par pack (packs de bouteilles/sachets). Un produit matériel ou service facturé
accessoirement sur la même commande n'est jamais compté.

`quantite_livree` prime sur `quantite_demandee` quand elle existe (commande avec véhicule,
chargement confirmé) — sinon `quantite_demandee` fait foi (vente directe client, sans étape de
chargement/livraison).

## Indépendance

Le cashback ne lit ni n'influence : le prix appliqué (`PrixVenteNatureResolver`), la
solvabilité/dérogation impayés (`SolvabiliteService`), ni la commission de livraison/propriétaire
(`CommissionEnveloppeGenerator`). Un client Revendeur en dérogation impayés active, livré par un
véhicule avec équipe, génère normalement les trois : dette client, commission équipe, cashback —
cf. `VenteRevendeurDerogationIntegrationTest::test_cashback_genere_a_lencaissement_sans_perturber_prix_dette_ni_commission()`.

## Historique — modèle précédent (remplacé)

Avant ce chantier, `CashbackService::processVente()` incrémentait un cumul d'achats
(`CashbackSolde::cumul_achats`) par client et déclenchait un gain **fixe** dès que ce cumul
atteignait un **seuil global d'organisation** (`Parametre::CLE_CASHBACK_SEUIL_ACHAT` /
`CLE_CASHBACK_MONTANT_GAIN`), puis remettait le cumul à zéro. Ce modèle ignorait la notion de
pack/produit et ne portait aucune configuration par client.

Ces deux paramètres (`cashback_seuil_achat`, `cashback_montant_gain`) sont désormais **inertes** :
plus aucun code ne les lit. Ils n'ont pas été supprimés de `Parametre` ni de la page de
réglages génériques (`settings/Parametres.vue`) — en pratique aucune organisation réelle ne
semble les avoir configurés (aucun contrôleur métier ne les écrivait, seuls les tests les
provisionnaient explicitement), donc le risque de configuration résiduelle visible est faible ;
un nettoyage complet de ce vestige reste un chantier de dette technique séparé si besoin.

`CashbackSolde::cumul_achats` reste alimenté (somme du `total_commande` de chaque vente
traitée) mais devient un indicateur purement informatif — il ne déclenche plus rien et n'est
plus jamais remis à zéro.
