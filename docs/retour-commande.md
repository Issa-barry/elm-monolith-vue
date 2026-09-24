# Retour de livraison avant encaissement (vente standard)

Décision produit du **23/09/2026** — cf. [ADR 0003](adr/0003-retour-de-livraison-avant-encaissement.md).

## Règle centrale

> Un retour enregistré avant encaissement réduit la quantité effectivement livrée et entraîne la
> régularisation de la facture. La marchandise retournée est réintégrée au stock disponible et la
> commission de vente est réajustée sur la quantité facturée. Un retour peut être partiel ou total.

**Quantité livrée = quantité chargée − quantité retournée**, ligne par ligne. Les quantités
**demandée** et **chargée** d'origine ne sont jamais modifiées : un retour est un mouvement métier
tracé, pas une correction silencieuse de la commande.

## Périmètre

- **Toutes les ventes standard** en `livraison_en_cours` : celles dont la livraison est confirmée par
  le premier encaissement (`CommandeVenteService::passerEnLivree()`).
- **Hors périmètre — commandes à réception explicite** (`CommandeVente::requiertReceptionExplicite()` :
  `distribution_client`, Grossiste + Livraison). Elles constatent déjà ce que le client a accepté à
  la **validation de réception** (`quantite_livree`, écart de réception) et gardent leurs règles :
  **pas de réintégration de stock** (décision du 30/08/2026) et commission générée sur le réceptionné
  (cf. [commissions.md](commissions.md) COMM-004, [grossiste.md](grossiste.md)). Le retour leur est
  refusé (`raisonRetourImpossible()`).
- **Hors périmètre — vente directe** (sans véhicule, statut `facturation`) : pas de livreur, donc pas
  de retour de livraison.

## Quand un retour est possible

`CommandeVente::raisonRetourImpossible()` est la source de vérité unique (Policy, écran et service
la relisent). Un retour n'est possible que si :

| Condition | Pourquoi |
|---|---|
| statut `livraison_en_cours` | la marchandise est partie et n'a pas encore été confirmée livrée |
| vente sans réception explicite | voir Périmètre |
| facture non annulée **et** aucun encaissement (`montant_encaisse = 0`) | « avant encaissement » — le premier encaissement fait passer la commande en `livree` ; le montant encaissé est aussi contrôlé directement (un encaissement peut être créé hors du contrôleur : import, API) |
| au moins une ligne avec une quantité encore retournable | chargé − déjà retourné > 0 |
| la commission de la commande est encore **régularisable** | voir Commission |

Un retour n'est **pas** possible sur une commande `a_charger` / `chargement_en_cours` (rien n'est parti),
déjà `livree` / `facturation` / `cloturee` (encaissement commencé), `annulee` ou `retournee`.

Un retour **partiel** laisse la commande en `livraison_en_cours` : d'autres retours peuvent suivre,
jusqu'à épuisement des quantités chargées. Le **retour total** (plus rien de livré sur aucune ligne,
en un ou plusieurs retours) passe la commande en **`retournee`** (statut terminal).

## Effets d'un retour

Tout est fait dans **une seule transaction** (`CommandeVenteRetourService::enregistrer()`), sous verrou
de la commande : deux retours concurrents se sérialisent.

1. **Quantités et facture** — pour chaque ligne retournée : `quantite_retournee` cumulée,
   `quantite_livree = quantite_chargee − quantite_retournee`, `total_ligne = quantite_livree × prix
   unitaire facturé` (même mode de tarification que la commande). Le total de la commande et de la
   facture (`montant_brut`/`montant_net`) sont recalculés par `CommandeVenteService::recalculerTotaux()`.
   Le client n'est facturé que sur ce qui a été livré. Retour total : facture **annulée**
   (`statut_facture = annulee`), aucun encaissement possible (`StoreEncaissementVenteController`
   refuse une facture annulée).
2. **Stock** — une **ENTRÉE** de stock par ligne retournée, distincte de la SORTIE du chargement
   (`MouvementStockService::reintegrerRetour()`, source `CommandeVenteRetourLigne`). La sortie du
   chargement reste intacte dans le journal : `SORTIE chargement −10`, `ENTRÉE retour +3`. Site = site
   de la commande. Ignore les produits qui ne gèrent pas de stock (type service). Idempotent par
   ligne de retour. Le journal de stock affiche « Retour livraison — CMD-… »
   (`MouvementStockMotifService`, filtre `retour_livraison`).
3. **Commission** — voir ci-dessous.
4. **Comptabilité** — voir ci-dessous.
5. **Traçabilité** — `commande_vente_retours` (qui, quand, motif, commentaire, quantité, montant,
   retour total ou non) et `commande_vente_retour_lignes` (produit, quantité, prix unitaire figé,
   montant) ; entrée dans le journal d'activité de la commande (`retour_enregistre` / `retournee`).

### Commission

La commission d'une vente standard est calculée sur la **quantité facturée** : la quantité chargée
**nette des retours** (`CommandeVenteLigne::quantite_nette_chargee`, lue par
`CommissionEnveloppeGenerator` pour toute commande sans réception explicite).

- **Aucune commission générée à ce stade** (déclencheur `FACTURE_ENCAISSEE`, ou génération en échec) :
  rien à faire, la génération à venir se base déjà sur la quantité nette.
- **Retour partiel** : les enveloppes encore `creee` sont **supprimées puis régénérées** sur les
  quantités nettes, en conservant leur **date de gain d'origine** (donc leur barème et leur période de
  paiement). Suppression plutôt qu'« annulée + nouvelle » : la contrainte d'unicité (source, cible)
  des enveloppes interdit d'en garder deux pour la même cible ; l'historique des tentatives de
  génération (`commission_generation_attempts`) reste conservé. Les bénéficiaires connectés reçoivent
  une nouvelle notification « commission générée » au montant réajusté.
- **Retour total** : parts et enveloppes passent à `annulee`, sans régénération — même traitement que
  l'annulation d'une commande (`CommandeVenteService::annulerCommissionsAssociees()`).
- **Garde-fou** : le retour est **refusé** (erreur `retour`) si une part de commission de la commande
  a déjà fait l'objet d'une décision humaine — sortie de `creee` (période de paiement validée), montant
  ajusté, validée ou versée (`CommissionTriggerService::raisonCommissionsNonRegularisables()`).
  Recalculer effacerait ou dupliquerait un engagement déjà pris envers un bénéficiaire ; la commission
  doit d'abord être régularisée. Cas rare : la période de paiement couvrant la commande doit avoir été
  validée avant le retour de la marchandise.

### Comptabilité

La facture a déjà été comptabilisée à la validation du chargement (`vente_facturee`, débit Client /
crédit Ventes). Chaque retour poste une pièce **`vente_retour`** — écriture inverse sur la seule valeur
retournée (débit `produit_vente` 701, crédit `client` 411) —
(`VenteComptabilisationService::comptabiliserRetourVente()`). **Une pièce par retour**, jamais une
contrepassation de la pièce d'origine : des retours partiels successifs se cumuleraient sinon en double
(la somme des pièces `vente_retour` d'une commande intégralement retournée annule la pièce d'origine).
Sans effet si la facture n'avait jamais été comptabilisée avant le retour
(`VenteComptabilisationService::retourARegulariser()`, source unique de cette règle).

**Échec de la comptabilisation.** Mode « shadow » comme `vente_facturee` : un retour est une opération
physique déjà survenue (la marchandise est revenue), le refuser parce qu'une écriture échoue (mapping
manquant, période comptable clôturée) la laisserait sans trace. Le retour, la facture, le stock et la
commission restent donc validés — mais l'échec n'est **jamais silencieux** :

- journalisé (`laravel.log`, avec `retour_id`) ;
- inscrit dans le **journal d'activité de la commande** (« Comptabilisation du retour en échec — à
  régulariser », action `comptabilisation_retour_echouee`) ;
- signalé à l'utilisateur par un avertissement orange à l'enregistrement du retour ;
- **retrouvable** : `php artisan comptabilite:auditer` compte les retours dont la pièce manque (ligne
  « Retours de livraison », code retour ≠ 0) ;
- **rattrapable** : `php artisan comptabilite:rattraper --type=retour` (`--dry-run` pour simuler)
  comptabilise les retours manquants, à leur date réelle, de façon idempotente.

Le rattrapage ne double jamais un retour : la régularisation n'est due que si la pièce de vente
existait déjà **quand le retour a été enregistré** (`created_at` de la pièce ≤ celui du retour). Une
vente dont la comptabilisation avait échoué et qui est rattrapée (`--type=vente`) **après** le retour
est comptabilisée directement au montant net — y ajouter la pièce de retour compterait le retour deux
fois. Lancer `vente` avant `retour` (ordre par défaut) est donc sans risque.

Déploiement : les mappings `vente_retour` de chaque organisation existante sont créés par la migration
de données `2026_09_23_100200_backfill_compta_mapping_vente_retour` (copie des mappings
`vente_facturee`) ; les nouvelles organisations les reçoivent via `PlanComptableBootstrapService`.

## Motif obligatoire

`App\Enums\MotifRetourCommande` : client absent, le client a refusé la commande, quantité non acceptée
par le client, erreur de préparation, problème de livraison, autre (**commentaire obligatoire** pour
« autre »). Un commentaire libre est toujours possible.

**« Produit endommagé » est volontairement absent** : la marchandise retournée est remise en stock
disponible ; une marchandise abîmée doit être sortie du stock par un ajustement de stock (motif
« Casse »), jamais masquée par un retour.

## Permission et autorisations

- Permission dédiée **`ventes.enregistrer_retour`** (`PermissionCatalog::STANDALONE`, domaine Ventes >
  « Cycle de vente »), indépendante de `ventes.update` : un retour réduit la facture, réintègre le stock
  et réajuste la commission. Préréglages du seeder : `admin_entreprise` et `manager`. La migration
  `2026_09_23_100100_backfill_ventes_enregistrer_retour_permission` l'accorde à tous les rôles existants
  qui ont déjà `ventes.valider_reception` (ceux qui constatent la livraison).
- `CommandeVentePolicy::enregistrerRetour()` : permission + même organisation + `isRetournable()`. Le
  `Gate::before` de `super_admin` contourne la Policy : le bouton est donc aussi conditionné à
  `isRetournable()` côté contrôleur (`can_enregistrer_retour`) et **le service revérifie toutes les
  conditions métier**, jamais uniquement la Policy.
- Isolation organisationnelle : Policy (`sameOrganization`) + tests d'isolation.
- **Pas de double validation** (déclaration puis validation) : l'enregistrement par un utilisateur
  habilité vaut validation ; le contrôle passe par la permission dédiée. Une étape de validation
  séparée pourrait être ajoutée plus tard si l'organisation le demande.

## Écran

`Ventes/Show.vue` : bouton **Retour** (visible uniquement avec `can_enregistrer_retour`), dialogue
`partials/RetourDialog.vue` (quantité par produit avec plafond, « Tout retourner », motif, commentaire,
aperçu du montant retourné et de la nouvelle facture, avertissement de retour total), colonnes
**Retournée** / **Livrée** dans l'onglet Produits dès le premier retour, carte « Retours de livraison »
(qui, quand, motif, lignes). Statut **Retournée** : point orange (`StatusDot`, attention et non erreur).
Les montants affichés dans le dialogue sont une prévisualisation : le montant définitif est toujours
recalculé côté serveur.

## Non couvert (volontairement)

- Application mobile livreur : l'enregistrement se fait depuis le back-office.
- Retour **après** encaissement (avoir / remboursement) : il n'existe aucun mécanisme d'avoir ; hors
  périmètre de cette règle.
- Étiquette de retour sur le ticket imprimé et l'export des ventes.
- Le raccourci `VehiculeSituationVentesService` exclut les commandes `retournee` (comme `annulee`) ;
  « quantité vendue » y lit `quantite_livree` et reflète donc les retours partiels.

## Fichiers clés

`app/Services/CommandeVenteRetourService.php`, `app/Models/CommandeVenteRetour.php`,
`app/Models/CommandeVenteRetourLigne.php`, `app/Enums/MotifRetourCommande.php`,
`app/Http/Controllers/Ventes/EnregistrerRetourCommandeVenteController.php` (route
`POST /backoffice/ventes/{commande_vente}/retour`, nom `ventes.retour.store`),
`CommissionTriggerService::onRetourEnregistre()`, `MouvementStockService::reintegrerRetour()`,
`VenteComptabilisationService::comptabiliserRetourVente()`. Tests :
`tests/Feature/CommandeVenteRetourTest.php`.
