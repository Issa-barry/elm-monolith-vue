# Régularisation d'une vente après encaissement

Décision produit du **30/10/2026** — cf. [ADR 0043](adr/0043-regularisation-vente-apres-encaissement.md).

## Pourquoi

Incident VTE-301026-029 : 2 700 packs de sachets saisis au lieu de 270. La vente a été chargée,
livrée et encaissée (16 200 000 GNF), la commission générée et en partie payée. Aucun outil ne
permettait de la corriger : le retour de livraison n'existe qu'avant encaissement, l'annulation
exceptionnelle défait toute la vente et refuse une commission déjà payée.

La prévention est traitée à part : capacité véhicule obligatoire par catégorie
([capacites-vehicule.md](capacites-vehicule.md), ADR 0042).

## Périmètre (lot 1)

**Baisse de quantité** sur une vente déjà encaissée. Pas de hausse, pas de correction de prix ni
de mode de paiement.

| Condition | Pourquoi |
|---|---|
| Vente standard livrée par un véhicule, sans validation de réception | même périmètre que le retour de livraison |
| Statut `livree` ou `cloturee` | la vente est terminée |
| Au moins un encaissement (hors acompte), facture non annulée | sinon c'est un retour ou une modification |
| Pas une précommande | elle a ses propres actions (retour, remboursement du trop-perçu) |
| Pas de cashback généré | non recalculé dans ce lot : à régulariser d'abord à la main |
| Quantité totale après correction > 0 | une vente ramenée à zéro relève de l'annulation exceptionnelle |

Source de vérité : `RegularisationVenteService::raisonNonEligible()` et les refus du récapitulatif.

## Permission et confirmation

- Permission **`ventes.regulariser_apres_encaissement`** (Ventes > Cycle de vente), accordée au seul
  `super_admin` par la migration `2026_10_30_130100_backfill_ventes_regulariser_apres_encaissement_permission`.
  Une organisation peut la déléguer dans Rôles.
- Contrôle d'organisation explicite (le `Gate::before` du super admin court-circuite les Policies).
- **Même confirmation que l'annulation exceptionnelle** (Paramètres → Ventes) : code à usage unique
  par e-mail (défaut) ou confirmation simple. Motif obligatoire (10 caractères minimum). L'empreinte
  du récapitulatif est revérifiée sous verrou à la confirmation : si un encaissement, une commission
  ou une quantité a changé entre-temps, l'opération est refusée.

## Parcours

Fiche commande → **Régulariser la vente** (`partials/RegularisationDialog.vue`) :

1. Saisie de la **quantité réellement vendue** par ligne. L'aperçu est calculé par le serveur :
   facture avant/après, excédent encaissé, encaissements réduits, stock réintégré, commissions
   avant/après et écarts.
2. Si l'encaissé dépasse la facture corrigée, choix obligatoire :
   - **« Non, il n'a jamais été reçu »** : l'encaissement est ramené au montant corrigé ;
   - **« Oui, il a été reçu »** : l'encaissement est conservé, l'excédent devient un trop-perçu à
     rembourser.
3. Motif, puis code (ou confirmation simple).

## Effets (une seule transaction)

1. **Quantités** — un mouvement tracé `commande_vente_retours` de nature `regularisation` (motif
   « Régularisation après encaissement ») : `quantite_retournee` augmente, `quantite_livree` =
   quantité corrigée, `total_ligne` recalculé. Les quantités demandée et chargée d'origine ne
   changent jamais. Facture et total de la commande recalculés.
2. **Stock** — ENTRÉE de la quantité retirée sur le site de la commande (journal de stock :
   « Retour livraison — VTE-… (régularisation) »).
3. **Comptabilité** — pièce `vente_retour` sur la valeur retirée (débit ventes 701, crédit client
   411), libellé « Régularisation après encaissement — facture … ». Mode shadow comme le retour : un
   échec est inscrit au journal d'activité et rattrapable (`comptabilite:rattraper --type=retour`).
4. **Excédent « jamais reçu »** — du plus récent encaissement au plus ancien : un encaissement
   réduit est **remplacé** par un encaissement du montant restant (même date, mode, compte, agence,
   référence et auteur) puis supprimé ; ses pièces sont contrepassées, jamais effacées. La caisse ou
   le compte diminue de l'excédent. La facture ne quitte jamais « Payée ». Refusé si les fonds ont
   déjà quitté la caisse ou le compte (versement) ou si l'encaissement est engagé dans un règlement
   inter-agences.
5. **Excédent « reçu »** — rien n'est retiré ; la facture affiche un **trop-perçu** (onglet
   Facturation) remboursé par **« Rembourser le trop-perçu »** : décaissement réel depuis un support
   (permission `ventes.rembourser`, même opération que pour une précommande, pièce
   `remboursement_client` débit 411). La commande ne se clôture pas tant qu'il reste un trop-perçu.
6. **Commissions** — voir ci-dessous.
7. **Trace** — ligne `regularisations_ventes` (auteur, motif, méthode de confirmation, montants
   avant/après, récapitulatif complet, opérations), audit `updated` et `encaissement_deleted`,
   activité « a régularisé la vente après encaissement ». Carte « Régularisations après
   encaissement » dans l'onglet Produits.

Le statut de la commande ne change pas.

## Commissions : recalcul immédiat, paiements intacts

Règle validée le 30/10/2026 : la commission est **recalculée immédiatement** sur la quantité
corrigée, avec le moteur officiel, **à la date de gain d'origine** (mêmes barèmes, même partage
d'équipe). Le recalcul et la régularisation financière sont deux opérations distinctes.

- **Part non engagée** (rien versé, pas sur une fiche payée, période non clôturée) : remplacée par
  la part recalculée. La période concernée est rouverte et recalculée par le mécanisme commun ; une
  validation ou un ajustement manuel de cette part est à refaire (montants avant/après conservés
  dans la régularisation).
- **Part engagée** (versement reçu, fiche payée même en partie, ou période clôturée) : la part est
  conservée avec ses paiements. Son montant théorique est recalculé en place (un montant ajusté à la
  main suit la même proportion). L'écart avec le déjà-versé est enregistré dans
  `commission_ecarts_regularisation` :
  - `ecart = versé − recalculé` : positif = **trop-perçu à régulariser**, négatif = **complément à
    payer** ;
  - statut **À régulariser** tant qu'il y a un écart ; **Sans objet** sinon.
- **Jamais** de commission négative, de compensation entre bénéficiaires, ni de modification ou
  suppression d'un paiement.
- **Reste à payer de la fiche corrigé** (audit du 30/10/2026) : les montants d'une fiche payée
  restent figés (ADR 0010), mais `paiement_fiches.reduction_regularisation` retire de son reste ce
  qui n'est plus dû. Pour chaque commission recalculée de la fiche :
  `ligne − max(recalculé, déjà versé)` (`PaiementFiche::recalculReductionRegularisation()`, recalculé
  avec le statut de la fiche à chaque paiement ou annulation). Le paiement de la fiche est plafonné à
  ce reste corrigé — aucun double paiement possible, aucune somme non contestée bloquée.
  Exemples (fiche de 500, commission ramenée à 200) : 300 déjà versés → reste 0 (trop-perçu de 100
  suivi) ; 100 déjà versés → reste 100 (complément). Un trop-perçu n'est jamais compensé sur les
  autres commissions de la même fiche : elles restent dues en entier.
- **Complément** (versé < recalculé) : il se paie par la fiche, comme tout reste dû. Il est soldé
  automatiquement dès que la part a reçu son montant recalculé, et rouvert si ce paiement est annulé
  (`RegularisationCommissionService::synchroniserComplements()`, appelé par l'allocation des
  paiements). Il ne se marque jamais à la main : il pourrait être payé deux fois.
- **Trop-perçu** : sa récupération (retenue, remboursement par le bénéficiaire…) se fait hors de cet
  écran ; on la constate par **« Marquer régularisé »** sur la fiche commande, avec un commentaire
  obligatoire (même permission). Aucun montant ni paiement n'est modifié.

## Limites connues (lot 1)

- Hausse de quantité, correction de prix, de mode de paiement ou de date : non couvertes.
- Cashback : la régularisation est refusée s'il en a été généré.
- Précommandes, ventes à réception explicite (distribution, Grossiste + Livraison), ventes sans
  véhicule : non couvertes.
- Une part engagée dont le bénéficiaire n'apparaît plus dans le recalcul (équipe modifiée depuis)
  est recalculée au prorata de l'enveloppe.
- L'encaissement réduit est recréé : il change d'identifiant et d'heure de création (date
  d'encaissement conservée).
- La retenue d'un trop-perçu sur une fiche suivante n'est pas automatisée : l'écart est suivi, sa
  régularisation constatée à la main.
- Une part engagée seulement parce que sa période est clôturée, sans fiche payable, garde son
  complément « À régulariser » (cas non rencontré : un complément suppose un versement partiel).

## Fichiers clés

- `app/Services/Ventes/Regularisation/RegularisationVenteService.php` — éligibilité, récapitulatif,
  code, exécution.
- `app/Services/Ventes/Regularisation/RegularisationCommissionService.php` — plan et application du
  recalcul, écarts, solde automatique des compléments.
- `PaiementFiche::recalculReductionRegularisation()` / `montantDu()` — reste à payer corrigé d'une
  fiche figée.
- `CommissionEnveloppeGenerator::simulerPourCommandeVente()` — calcul sans écriture.
- `app/Http/Controllers/Ventes/Regularisation/*` — récapitulatif, code, confirmation, remboursement,
  écart régularisé.
- `app/Models/RegularisationVente.php`, `app/Models/CommissionEcartRegularisation.php`, migration
  `2026_10_30_130000_create_regularisations_ventes_tables`.
- `resources/js/pages/Ventes/partials/RegularisationDialog.vue`, `resources/js/pages/Ventes/Show.vue`.
- Tests : `tests/Feature/RegularisationVenteTest.php`.
