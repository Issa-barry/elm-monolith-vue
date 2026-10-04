# Précommandes — spécification fonctionnelle et technique V1

- **Date** : 2026-10-04
- **Statut** : **validée le 2026-10-04** (D1 à D12 acceptées, § 13 ; pas d'interrupteur d'activation
  en V1). Développement par lots (§ 15), lot 1 en cours.
- **Décision structurante** : [ADR 0019](adr/0019-precommande-vente-avec-acompte-et-reservation.md)
- **Périmètre** : Ventes (`CommandeVente`), stock (réservations), encaissements, trésorerie,
  comptabilité, commissions, cashback, rapports.

---

## 1. Objet

Permettre à un agent du back-office d'enregistrer, pour un client présent au comptoir, une commande
**payée en tout ou en partie à l'avance** dont la marchandise est **réservée** puis **préparée**, et
remise plus tard au client, soit par **retrait sur site**, soit par **livraison**.

## 2. Règles métier validées

| # | Règle |
|---|---|
| R1 | Une précommande **est une commande de vente** (`CommandeVente`), pas une nouvelle entité ni une nouvelle `NatureOperation`. Nature, tarification, commissions et préfixe `VTE-`/`DST-` sont calculés exactement comme aujourd'hui. |
| R2 | Le caractère « précommande » est un indicateur distinct (`est_precommande`), fixé à la création et **jamais modifiable**. |
| R3 | Deux points d'entrée distincts (« Nouvelle vente » / « Nouvelle précommande »), deux routes, une permission dédiée. Le backend distingue les deux parcours, jamais l'interface seule. |
| R4 | Précommande : client, date prévue et mode de remise obligatoires ; acompte selon R5. Bouton « Enregistrer la précommande ». |
| R5 | **Acompte paramétrable par organisation** (révisé le 04/10/2026, D6) : « Acompte obligatoire : Oui / Non » et « Taux d'acompte (%) » **saisi librement**, aucun taux imposé. **Oui** : taux strictement supérieur à 0 % et au plus 100 % ; acompte à la création ≥ ce taux du total. **Non** : précommande possible **sans acompte** (taux 0 % accepté, sinon indicatif) ; un acompte éventuellement versé suit les mêmes règles (avance client, R6). **Jamais configuré : création de précommandes bloquée** (révision du 04/10/2026), avec un message renvoyant vers Paramètres → Ventes → Précommandes. Paiement partiel toujours autorisé ; acomptes complémentaires jusqu'au total. |
| R6 | L'acompte est une **avance client (4191)**, pas un encaissement de facture réalisée. La facture ne passe jamais « Payée » avant la remise. Commissions et cashback ne sont jamais déclenchés par un acompte. |
| R7 | Le stock est réservé par le mécanisme existant (`StockReservation` / `qte_reservee`). La colonne « Engagé » de l'écran Stock est renommée **« Réservé »**. |
| R8 | Opérations **atomiques** à la création : stock vérifié et réservé, acompte (s'il y en a un) vérifié et enregistré, précommande créée — tout ou rien. Jamais d'argent encaissé sans réservation. |
| R9 | **Pas de réservation à découvert** : précommande refusée si le disponible est insuffisant. Le stock « Entrant » n'est pas utilisé en V1. |
| R10 | Deux modes de remise : **retrait sur site** et **livraison** (circuit logistique existant). |
| R11 | Étape de **préparation** réelle : Réservée → À préparer → Préparée. |
| R12 | La quantité **réellement remise** (retrait) ou **réellement chargée / livrée** (livraison) détermine le montant final. Les quantités non remises sont libérées et ne sont pas facturées. |
| R13 | **Trop-perçu remboursé** (acompte supérieur à la valeur réellement remise). |
| R14 | Le **solde n'est pas obligatoire** à la remise : le reste dû devient une créance soumise aux règles existantes (solvabilité, impayés, dérogation) — aucune règle nouvelle. |
| R15 | Le client veut **plus** : la précommande ne change pas, l'excédent fait l'objet d'une **nouvelle vente normale**. |
| R16 | Annulation : motif obligatoire, réservation libérée, sommes versées **remboursées** avec trace en trésorerie ; après préparation, procédure renforcée (cf. § 4, C1). |
| R17 | **Pas d'expiration automatique** : date dépassée ⇒ indicateur « En retard », la réservation reste active jusqu'à une décision. |
| R18 | **Prix figé** à la création (snapshot des lignes, mécanisme existant). |
| R19 | Préfixe de référence inchangé (`VTE-`/`DST-`), marqueur « Précommande » affiché partout. |
| R20 | Page dédiée **Ventes → Précommandes** pour le suivi (filtre sur `est_precommande`, pas une nouvelle entité). |

## 3. Existant réutilisé (audit du 04/10/2026)

| Besoin | Mécanisme existant | Où |
|---|---|---|
| Réserver / libérer / consommer du stock | `StockReservationService::reserver()/liberer()/consommer()`, idempotent par source, sous verrou `VarianteStock::lockOuCreer()` | `app/Services/StockReservationService.php` |
| Disponible = physique − réservé | `MouvementStockService::quantiteDisponible()` ; plancher « une sortie ne descend jamais sous le réservé » | `app/Services/MouvementStockService.php:92` |
| Colonne « Engagé » | `variante_stocks.qte_reservee` (Bloqué et Entrant affichés mais non calculés, `null`) | `IndexStockController.php:253` |
| Contrôle de disponibilité groupé | `CommandeVenteService::verifierDisponibiliteLignes()` | `CommandeVenteService.php:837` |
| Prix figé | `prix_vente_snapshot` / `prix_usine_snapshot` / `total_ligne` sur `commande_vente_lignes` | `CommandeVenteLigne` |
| Nature, tarif, mode Grossiste | `NatureOperation::deriverParDefaut()`, `VehiculeCommandeContextResolver`, `deriverModeRemiseGrossiste()` (véhicule ⇒ Livraison, sans véhicule ⇒ Enlèvement) | `StoreCommandeVenteController` |
| Contrôle des impayés à la création | `SolvabiliteService::enforcerOuEchouer()` (client prioritaire, véhicule en repli) | `SolvabiliteService.php` |
| Encaissement | `StoreEncaissementVenteController` + `PaymentCard` + `MoyensEncaissementResolver` + caisse dédiée obligatoire pour les espèces + référence Mobile Money unique (ADR 0014) | `docs/encaissements.md` |
| Décaissement depuis un support | ADR 0009 : `PaymentCard` en mode `decaissement`, `TresorerieDisponibiliteService::garantirSoldeSuffisant()` sous verrou | `docs/adr/0009-*` |
| Confirmation forte d'une action sensible | `OtpService` (code e-mail) + empreinte du récapitulatif (ADR 0004) | `AnnulationExceptionnelleService` |
| Liste filtrée dédiée | Précédent **Distribution** : `distributions.index` = `IndexCommandeVenteController` filtré + fiche dédiée | `routes/web.php:414` |
| Journal d'activité de la commande | `CommandeVenteActiviteService::log()` | — |
| Badge de statut | `StatusDot.vue` + `STATUS_COLOR_MAP` | CLAUDE.md règle 1 |
| Filtres de liste | `DataFilters.vue` (Agence `site_ids[]` → Statut → inline → Filtres) | CLAUDE.md règle 2 |

**Ce qui n'existe pas** : avance client / compte 4191, remboursement à un client, réduction partielle
d'une réservation, statut de préparation, date prévue de remise.

## 4. Conflits avec les règles existantes — à arbitrer

| # | Règle existante | Conflit | Proposition |
|---|---|---|---|
| **C1** | **ADR 0004** : l'annulation exceptionnelle est réservée aux **ventes fictives saisies par erreur** — « Pas d'avoir ni de remboursement : aucune vente n'a existé », statut `annulee_erreur_saisie`, `super_admin` seul. | Annuler une précommande réelle (client qui renonce, ne vient pas) **avec remboursement** est exactement ce que l'ADR 0004 a écarté. La réutiliser fausserait ses statistiques (« saisie fictive ») et son sens. | Procédure distincte **« Annuler la précommande »** : statut `annulee` (vente jamais réalisée, aucune marchandise sortie), remboursement obligatoire des sommes versées, motif obligatoire. Avant préparation : permission `ventes.annuler_precommande`. Après préparation : même procédure + permission renforcée + **confirmation paramétrable réutilisant le mécanisme de l'ADR 0004** (code e-mail / simple, empreinte du récapitulatif) — mécanisme réutilisé, sémantique non. L'ADR 0004 reste intact. |
| **C2** | Aucun encaissement avant validation du chargement (`CommandeVente::isEncaissable()`). | Un acompte est reçu avant toute remise. | Règle conservée pour toutes les ventes. Exception **unique** : une précommande non encore remise accepte un encaissement, qui est **par définition un acompte** (`est_acompte = true`), comptabilisé en 4191 et sans effet sur le statut de la facture (§ 8). |
| **C3** | **ADR 0007 §3** : date d'une vente = création de sa facture (`factures_ventes.created_at`). | La facture d'une précommande naît à la création (comme pour `confirmer()`), donc des jours avant la remise : la vente serait datée du jour de la précommande. | Pour une précommande, **date de vente = date de remise** (`remise_at`) ; statuts avant remise exclus du CA (`STATUTS_COMMANDE_HORS_CA`). Amende l'ADR 0007. |
| **C4** | **ADR 0003** : retour de livraison impossible dès qu'un encaissement existe. | Une précommande livrée avec acompte ne pourrait jamais faire l'objet d'un retour. | Pour une précommande, les **acomptes ne bloquent pas le retour** ; la valeur retournée devient un trop-perçu à rembourser (§ 8.4). Amende l'ADR 0003. |
| **C5** | `validerReception()` refuse un écart si l'encaissé dépasse le nouveau total (« Régularisez l'encaissement »). | Cas normal d'une précommande avec acompte élevé et réception partielle. | Pour une précommande : écart accepté, l'excédent devient un **trop-perçu à rembourser**. |
| **C6** | `Parametre::isVentesAutoriseesSansStock()` : une organisation peut autoriser la vente au-delà du disponible ; `reserver()` suit ce paramètre. | R9 interdit la réservation à découvert. | La précommande est **toujours stricte** (`allowNegative = false`), quel que soit le paramètre : une réservation payée doit correspondre à du stock réel. Exception explicite, documentée. |
| **C7** | Mode Grossiste et nature dérivés du **véhicule**, figés à la création (prix, commission, préfixe). | « Livraison » sans véhicule connu à la création rendrait la nature et le prix indéterminés, alors que l'acompte est calculé sur ce prix. | **Livraison ⇒ véhicule obligatoire à la création** (comme aujourd'hui, le véhicule n'est modifiable qu'en brouillon). Retrait ⇒ aucun véhicule. Le mode de remise est **dérivé du véhicule** (même principe que Grossiste, aucun second champ qui pourrait diverger) ; l'interface pose quand même la question « Retrait / Livraison » en premier. |
| **C8** | Plancher du stock physique = réservé : une sortie (ajustement de casse) ne peut pas entamer du stock réservé. | Marchandise réservée abîmée avant préparation : la casse ne peut pas être déclarée. | V1 : réduire d'abord la quantité à la **validation de préparation** (libère la réservation), puis déclarer la casse. Aucun contournement du plancher. |
| **C9** | Cashback déclenché **dans le contrôleur d'encaissement** au passage de la facture à « Payée » (`StoreEncaissementVenteController:209`), pas dans `FactureVente::recalculStatut()`. | Une précommande intégralement payée par acomptes devient « Payée » à la **remise**, hors de ce contrôleur : cashback jamais versé. | La cascade « facture payée » (cashback, clôture, passage livrée) est déclenchée aussi à la remise, par un point unique partagé. Aucun double déclenchement (idempotence existante de `processVente()`). |

## 5. Modèle de données minimal

Migrations **non destructives** uniquement.

| Table | Ajout | Rôle |
|---|---|---|
| `commandes_ventes` | `est_precommande` bool, défaut `false`, immuable | Indicateur R2 (historique : `false`) |
| | `date_remise_prevue` date, nullable (obligatoire si précommande) | Retard, tri, filtre |
| | `preparation_lancee_at`, `preparee_at`, `remise_at` datetime nullable | Horodatage des étapes ; `remise_at` = date de vente (C3) |
| `commande_vente_lignes` | `quantite_preparee` int nullable | Quantité préparée (≤ demandée) |
| `encaissements_ventes` | `est_acompte` bool, défaut `false` | Distingue l'acompte (4191) de l'encaissement de facture (411) ; historique : `false` |
| **nouvelle** `remboursements_ventes` | `organization_id`, `site_id`, `commande_vente_id`, `facture_vente_id`, `motif` (`annulation`/`trop_percu`), `montant`, `mode_paiement`, `operateur_mobile_money`, `compte_tresorerie_id`, `reference_paiement`, `date_remboursement`, `created_by` | Sortie de trésorerie vers le client (ADR 0009) |
| `parametres` | clés `ventes_precommande_acompte_obligatoire` et `ventes_precommande_acompte_min_pct` (absentes : précommandes bloquées) | Acompte (R5) |

Inchangés : mode de remise (dérivé du véhicule, C7), référence, nature.
La quantité **remise** d'un retrait est portée par `quantite_chargee` — même sens qu'aujourd'hui :
quantité **physiquement sortie du stock** (un client Externe « charge » déjà lui-même sa commande) ;
`decrementerStock()`, `quantite_effective` et les rapports la lisent déjà.

**Montant encaissé net** : `FactureVente::montant_encaisse` = Σ encaissements (acomptes compris)
− Σ remboursements. **Trop-perçu** = max(0, encaissé net − `montant_net`), dérivé, jamais stocké.

## 6. Cycle de vie

Trois statuts nouveaux dans `StatutCommandeVente`, uniquement **avant la remise** ; ensuite la
précommande rejoint le workflow existant sans aucun statut supplémentaire.

```text
Création (acompte selon R5, stock réservé, facture CREEE)
   │
   ▼
RESERVEE ──(Lancer la préparation)──► A_PREPARER ──(Valider la préparation : qté préparée)──┐
                                                                                            │
          ┌───────────────── Retrait (pas de véhicule) ─────────────────┐   Livraison (véhicule)
          ▼                                                              │         ▼
       PREPAREE ──(Valider le retrait : qté remise)──► FACTURATION ──► CLOTUREE   A_CHARGER ──► workflow
          (en attente du client)             (sortie stock, facture        (existant)   existant : chargement,
                                              activée, acomptes imputés)                  livraison, réception,
                                                                                          encaissement, clôture
RESERVEE / A_PREPARER / PREPAREE / A_CHARGER ──(Annuler la précommande + remboursement)──► ANNULEE
```

| Transition | Action | Permission | Effets |
|---|---|---|---|
| — → `reservee` | Enregistrer la précommande | `ventes.precommander` | Réservation (stricte), facture `CREEE`, acompte éventuel (§ 8.1) |
| `reservee` → `a_preparer` | Lancer la préparation | `ventes.preparer` | Horodatage (cf. D5) |
| `a_preparer` → `preparee` (retrait) / `a_charger` (livraison) | Valider la préparation | `ventes.preparer` | `quantite_preparee` ≤ demandée ; réservation **réduite** à la quantité préparée (nouvelle primitive `StockReservationService::reduire()`) ; total et facture recalculés |
| `preparee` → `facturation` | Valider le retrait | `ventes.valider_retrait` | `quantite_chargee` (remise) ≤ préparée ; réservation consommée + sortie de stock (`decrementerStock()` existant) ; total recalculé ; facture activée (§ 8.3) ; `remise_at` ; clôture si soldée |
| `a_charger` → … | Workflow existant | permissions existantes | Chargement : `quantite_chargee` ≤ préparée ; `remise_at` = validation du chargement |
| `reservee` → `annulee` | Annuler la précommande | `ventes.annuler_precommande` | Motif (≥ 10 caractères) ; remboursement de l'encaissé net dans la même opération ; réservation libérée ; facture annulée (jamais comptabilisée) |
| `a_preparer` / `preparee` / `a_charger` → `annulee` | Annuler la précommande — **procédure renforcée** (D1) | `ventes.annuler_precommande_preparee` | Idem + code e-mail si l'organisation l'exige (même réglage que l'annulation exceptionnelle). Jamais une fois le chargement démarré |

« Retirée » n'est pas un statut : la page Précommandes l'affiche pour toute précommande dont
`remise_at` est renseigné et la commande en `facturation`/`cloturee`. Le statut de paiement reste
porté par la facture (Payée / Partiel / Impayée), jamais mélangé au statut de commande.

**Livraison déjà soldée par les acomptes** : à la validation du chargement, la commande passe
directement « Livrée » (sauf réception explicite exigée) — aucun encaissement ne viendra plus la
faire passer « Livrée », règle habituelle d'une vente standard.

**En retard** : indicateur dérivé (`date_remise_prevue` < aujourd'hui et commande pas encore remise),
jamais un statut. Couleur **WARNING (ambre)**, pas DANGER : la précommande reste valide et
réalisable (CLAUDE.md § 10, cf. D8).

## 7. Création — parcours et garde-fous

### 7.1 Interface
- Page Ventes : **« Nouvelle vente »** (libellé actuel « Nouvelle commande ») et **« Nouvelle
  précommande »**, chacun affiché seulement avec sa permission (`v-if="can(...)"`).
- Route dédiée (`/backoffice/precommandes/create` → `StorePrecommandeController`), formulaire
  réutilisant les composants de `Ventes/Create.vue` (client, lignes, prix) avec un contexte figé :
  - titre « Nouvelle précommande », bandeau distinctif ;
  - **client obligatoire** ; question **Retrait / Livraison** en premier (Livraison ⇒ véhicule
    obligatoire) ; **date prévue** obligatoire (≥ aujourd'hui) ;
  - étape **Acompte** : `PaymentCard`, minimum affiché quand l'acompte est obligatoire ; quand il
    ne l'est pas, l'agent peut aussi « Enregistrer sans acompte » ;
  - bouton **« Enregistrer la précommande »** → fenêtre de confirmation récapitulative (client,
    total, acompte, reste, date, mode ; « le stock sera réservé, le client repart sans la
    marchandise »), avec chargement et boutons verrouillés pendant la requête.
- Le formulaire de vente normale n'a pas d'étape de paiement : un agent qui encaisse se retrouve
  forcément dans le parcours précommande. Quand l'acompte n'est pas obligatoire, ce garde-fou
  repose sur les deux points d'entrée, le titre, le bandeau et la confirmation.
- Le blocage « aucun produit vendable » (`siteAutoriseNouvelleCommande()`) s'applique aussi, en mode
  **strict** (C6).

### 7.2 Backend — ordre et atomicité (une seule transaction)
1. Autorisation `ventes.precommander`, organisation, site de l'utilisateur.
2. Validation : client, date, lignes, véhicule si livraison, montant d'acompte (facultatif si
   l'acompte n'est pas obligatoire).
3. Dérivations existantes (nature, mode Grossiste, contexte tarifaire) et garde-fous existants
   (capacité véhicule, politique de prix, partage commission).
4. Verrou véhicule (si livraison), **contrôle des impayés** (`SolvabiliteService`).
5. Calcul des lignes et du total (prix figés).
6. **Acompte**, paramètres relus côté serveur : obligatoire ⇒ ≥ `ceil(total × min %)` ; non
   obligatoire ⇒ 0 accepté ; toujours ≤ total ; s'il y en a un, moyen de paiement valide (support
   actif, caisse dédiée pour les espèces, référence obligatoire et unique).
7. **Réservation stricte** de chaque ligne (`reserver(..., allowNegative: false)`).
8. Création commande (`est_precommande = true`, `reservee`) + lignes + facture `CREEE`.
9. S'il y a un acompte : encaissement `est_acompte = true` + écriture 4191.
10. Audit + journal d'activité.

Tout échec (stock, solvabilité, moyen de paiement, référence en double, comptabilisation) annule
tout : ni commande, ni réservation, ni encaissement, ni écriture.

## 8. Paiements

### 8.1 Acompte à la création
Réglages dans **Paramètres → Ventes** (bloc « Précommandes »), jamais codés en dur (D6) :
- **Acompte obligatoire : Oui** — minimum = `ceil(total × ventes_precommande_acompte_min_pct / 100)` ; le
  taux, saisi par l'organisation, doit être compris entre 1 et 100 % ;
- **Acompte obligatoire : Non** — aucun minimum, la précommande peut être enregistrée sans
  acompte ; un taux éventuellement saisi n'est qu'indicatif.
- **Rien de choisi** (organisation qui n'a jamais configuré) — **aucune précommande possible** :
  bouton désactivé avec la raison, accès direct et envoi redirigés vers la liste avec le message. Jamais
  de comportement par défaut ; enregistrer d'autres paramètres de vente ne configure pas les
  précommandes à la place de l'organisation.

Un seul paiement à la création ; le client peut payer jusqu'au total. Les réglages s'appliquent à
la création uniquement : les modifier ne change rien aux précommandes existantes.

### 8.2 Acomptes complémentaires
Tant que la précommande n'est pas remise, « Ajouter un acompte » (`factures.encaisser`) crée un
encaissement `est_acompte = true`, dans la limite de Σ acomptes ≤ total courant. Même écran, mêmes
contrôles que l'encaissement. V1 : acompte encaissé uniquement par **l'agence de la précommande**
(l'encaissement inter-agences de l'ADR 0012 reste possible pour le solde après remise, cf. D11).

### 8.3 À la remise (retrait validé ou chargement validé)
1. Total recalculé sur la quantité remise / chargée.
2. Facture activée (et comptabilisée en vente) avec un statut **calculé depuis l'encaissé net**
   (Impayée / Partiel / Payée) — `PrecommandeService::activerFacture()`, appelé aussi par
   `CommandeVenteService::activerFacture()` au chargement validé d'une précommande en livraison.
3. Imputation des acomptes : écriture 4191 → 411 (§ 9).
4. Cascade « facture payée » si elle l'est : commission (déclencheur « facture encaissée »),
   cashback, clôture — point unique partagé avec le contrôleur d'encaissement (C9).
5. Le reste dû s'encaisse ensuite par l'écran existant (`est_acompte = false`).

### 8.4 Trop-perçu
Encaissé net > montant facturé (quantité remise inférieure, retour, écart de réception) : la fiche
affiche **« Trop-perçu à rembourser »**, la précommande apparaît dans le filtre correspondant, et la
**clôture est bloquée** tant qu'il n'est pas remboursé (cf. D10).

### 8.5 Remboursement
Action « Rembourser » (`ventes.rembourser`) : `PaymentCard` en mode `decaissement` (ADR 0009) —
espèces depuis la caisse dédiée du payeur, sinon support actif de l'agence ; référence obligatoire
selon le moyen ; **solde vérifié sous verrou** (`garantirSoldeSuffisant()`). Montant ≤ trop-perçu
(ou ≤ encaissé net en cas d'annulation). Trace dans `remboursements_ventes` + écriture.

**Implémentation (lot 2)** : le total remboursé est dénormalisé sur la facture
(`factures_ventes.montant_rembourse`, 0 pour tout l'historique). `FactureVente::encaisseNet()` =
encaissé − remboursé sert au statut (`recalculStatut()`), au reste à payer (`montant_restant`) et au
trop-perçu (`tropPercu()`), sans requête de plus par facture dans les listes. Pour une vente sans
remboursement, rien ne change.

## 9. Comptabilité

Nouveau compte bootstrapé **419100 « Clients — avances et acomptes reçus »** (et ajout pour les
organisations existantes, idempotent : migration `2026_10_04_200200_bootstrap_compte_avances_clients`).

| Fait | Écriture | Événement / rôle |
|---|---|---|
| Acompte reçu (avant remise) — **lot 1, livré** | D trésorerie (support / sous-compte caisse dédiée) / C **419100** | `encaissement_vente_recu`, rôle `avance_client` |
| Remise : vente constatée | D 411000 / C 701000 (inchangé) | `vente_facturee` |
| Remise : imputation des acomptes — **lot 2, livré** | D **419100** / C 411000 | `acompte_precommande_impute` |
| Solde encaissé après remise | D trésorerie / C 411000 (inchangé) | `encaissement_vente_recu`, rôle `client` |
| Remboursement avant remise (annulation, trop-perçu après préparation) — **lot 2, livré** | D **419100** / C trésorerie | `remboursement_client` |
| Remboursement d'un trop-perçu (après remise) — **lot 2, livré** | D 411000 / C trésorerie | `remboursement_client` |

**Choix d'implémentation (lot 1)** : l'acompte garde l'événement `encaissement_vente_recu` — seul le
rôle crédité change (`avance_client` → 419100 au lieu de `client` → 411000), selon
`encaissements_ventes.est_acompte`. Un événement distinct aurait obligé à adapter tous ses
consommateurs (contrepassation à la suppression, rattrapage comptable, journal financier, fiche de
caisse, annulation exceptionnelle, diagnostic des destinations) ; avec le rôle, ils restent valables
tels quels.

- Comptabilisation **bloquante** (même règle que l'encaissement) : un échec annule l'opération.
- Fiche de caisse (grand livre, ADR 0007 §6) : un acompte en espèces apparaît dans
  « Encaissements espèces », comme tout argent reçu ; un remboursement en espèces dans
  « Remboursements clients » (lot 2).
- Le solde 419100 d'une organisation = acomptes détenus pour des précommandes non remises
  (contrôle de cohérence possible).

## 10. Commissions et cashback

| Situation | Commission | Cashback |
|---|---|---|
| Acompte reçu | **Jamais** (facture reste `CREEE`) | **Jamais** |
| Retrait (sans véhicule) | Comme une vente directe : `onVenteDirecteFacturee()` (consultant Grossiste Enlèvement), sinon rien | À la facture payée (remise ou encaissement du solde) |
| Livraison, déclencheur « chargement validé » (défaut) | À la validation du chargement (inchangé) | À la facture payée |
| Livraison, déclencheur « facture encaissée » | Quand la facture devient réellement payée, **après** remise | idem |
| Distribution / Grossiste livré | À la validation de réception (inchangé) | idem |
| Retour / écart / annulation | Règles existantes (réajustement, annulation des commissions non soldées) | Gain en attente retiré si la facture n'est plus payée |

Garde-fou : `FactureVente::recalculStatut()` ne fait **jamais** sortir une facture de `CREEE`
(un encaissement sur une facture `CREEE` n'est possible que pour un acompte de précommande).

## 11. Stock

- Réservation à la création (quantité demandée), **réduite** à la préparation, **consommée** à la
  remise (sortie physique de la quantité remise ; le reste libéré par `consommer()`, comportement
  existant), **libérée** à l'annulation.
- Disponible = Physique − Réservé (− Bloqué quand il sera calculé).
- Écran Stock : libellé **« Réservé »** (au lieu d'« Engagé »), infobulle « commandes confirmées et
  précommandes non encore sorties du stock ». Le réservé inclut aussi les commandes véhicule « À charger ».
- Aucune réservation à découvert (C6) ; « Entrant » non utilisé (R9).

## 12. Écrans

| Écran | Changement |
|---|---|
| Ventes → **Précommandes** (nouveau menu, permission `ventes.read`, comme la liste Ventes) | Liste : Référence, Client, Agence, Date de précommande, Date prévue, Mode, Total, Acompte, Reste à payer, Quantité réservée, Statut (`StatusDot`), indicateurs En retard / Trop-perçu. `DataFilters` : Agence (`site_ids[]`) → Statut → filtres inline (Mode, En retard, Trop-perçu) → drawer (dates). Sur le modèle de la page Distribution. |
| Ventes (liste) | Boutons « Nouvelle vente » / « Nouvelle précommande » ; marqueur « Précommande » sur les lignes. |
| Nouvelle précommande | § 7.1. |
| Fiche commande | Badge « Précommande », date prévue, bloc Acomptes / Encaissé net / Reste / Trop-perçu, actions selon statut et permission : Lancer la préparation, Valider la préparation, Valider le retrait, Ajouter un acompte, Rembourser, Annuler la précommande. |
| Stock | « Engagé » → « Réservé ». |
| Paramètres → Ventes | Bloc « Précommandes » : « Acompte obligatoire » (Oui / Non) et « Taux d'acompte (%) », saisie libre ; tant que Oui / Non n'est pas choisi, un bandeau indique que les précommandes sont bloquées — refus serveur d'un taux de 0 % quand l'acompte est obligatoire — modifiables avec `parametres.update` (permission déjà contrôlée par cet écran). |
| `StatusDot.vue` | `reservee`, `a_preparer`, `preparee` ajoutés **uniquement** dans `STATUS_COLOR_MAP`. |
| API espace client (`commandes/mine`, `commandes/{id}`) | Les précommandes apparaissent avec leurs libellés de statut ; `docs/api-espace-client-contract.md` mis à jour. Pas de création depuis l'application en V1. |

Permissions nouvelles (`PermissionCatalog::STANDALONE`, domaine Ventes) : `ventes.precommander`,
`ventes.preparer`, `ventes.valider_retrait`, `ventes.annuler_precommande`, `ventes.rembourser` (+
celle de l'annulation après préparation selon D1). Attribution aux rôles existants **uniquement**
par migration de backfill explicite (ADR 0011), selon D12.

## 13. Décisions (validées le 04/10/2026)

Les principes R1 à R20 (§ 2) et la décision de l'ADR 0019 forment le socle. Le tableau ci-dessous ne
liste que les **questions encore ouvertes**. Chacune est marquée par le décideur métier
**Accepté / Refusé / À modifier** ; l'ADR 0019 passe en « accepté » une fois toutes les lignes
tranchées, et le développement ne démarre pas avant. **Numérotation de référence : celle-ci**, pour
qu'aucune règle ne soit interprétée différemment pendant le développement.

| # | Question | Proposition | Validation |
|---|---|---|---|
| D1 | Annulation après préparation : procédure distincte de l'ADR 0004 ? (C1) | Oui : « Annuler la précommande » + permission renforcée + confirmation paramétrable réutilisée | ☑ Accepté |
| D2 | Date de vente d'une précommande = date de remise ? (C3) | Oui, amendement de l'ADR 0007 | ☑ Accepté |
| D3 | Précommande toujours stricte même si la vente sans stock est autorisée ? (C6) | Oui | ☑ Accepté |
| D4 | Livraison : véhicule obligatoire à la création ? (C7) | Oui | ☑ Accepté |
| D5 | Passage Réservée → À préparer : action manuelle ou automatique (ex. veille de la date) ? | Action manuelle « Lancer la préparation » | ☑ Accepté |
| D6 | Paramétrage de l'acompte | **Validée le 04/10/2026, révisée deux fois le même jour** : dans **Paramètres → Ventes** (bloc « Précommandes »), protégés par `parametres.update`, jamais codés en dur ni portés par une permission : **Acompte obligatoire : Oui / Non** et **taux d'acompte en % saisi par l'entreprise** (aucun taux imposé). Oui ⇒ taux strictement supérieur à 0 % et au plus 100 %. Non ⇒ précommande possible sans acompte (0 %). Jamais configuré ⇒ création de précommandes bloquée (révision du même jour : pas de comportement par défaut). Entraîne la révision de R4, R5, R8, du § 7, du § 8.1 et du scénario 2 (+ 2 bis). | ☑ Accepté |
| D7 | Livraison impossible : nouvelle tentative ? | V1 : retour (total ou partiel) + remboursement du trop-perçu ; nouvelle tentative = nouvelle vente | ☑ Accepté |
| D8 | « En retard » en ambre (attention) plutôt qu'en rouge ? | Ambre (CLAUDE.md § 10) | ☑ Accepté |
| D9 | Retour autorisé malgré l'acompte (amendement ADR 0003) ? (C4) | Oui, l'excédent devient un trop-perçu | ☑ Accepté |
| D10 | Trop-perçu : remboursement différé (bloque la clôture) ou obligatoire dans la même opération que la remise ? | Différé, bloque la clôture | ☑ Accepté |
| D11 | Acomptes encaissés uniquement par l'agence de la précommande ? | Oui en V1 | ☑ Accepté |
| D12 | Rôles et permissions | **Validée le 04/10/2026** : droits gérés dans **Rôles & Permissions → Ventes** (`PermissionCatalog`, domaine Ventes), jamais dans les paramètres. `admin_entreprise` et `manager` : toutes les permissions de précommande (§ 12) ; `commerciale` : **`ventes.precommander` uniquement** (création, acompte initial compris). Attribution aux rôles existants par migration de backfill (ADR 0011). | ☑ Accepté |

## 14. Scénarios

| # | Scénario | Traitement |
|---|---|---|
| 1 | Stock insuffisant à la création | Refus groupé par ligne (`verifierDisponibiliteLignes()` puis `reserver()` strict sous verrou) ; rien n'est enregistré, aucun encaissement |
| 2 | Acompte < minimum (acompte obligatoire) | Refus serveur avec le minimum attendu ; rien n'est enregistré |
| 2 bis | Acompte non obligatoire, aucun versement | Précommande enregistrée et stock réservé, sans encaissement ni écriture ; reste à payer = total |
| 3 | Acompte complémentaire | Encaissement `est_acompte`, plafond = total courant ; 4191 |
| 4 | Totalement payée avant remise | Facture reste `CREEE` ; à la remise : activée directement « Payée », imputation, cascade (commission selon déclencheur, cashback, clôture) |
| 5 | Partiellement payée puis remise | Facture « Partiel » ; solde par l'écran d'encaissement ; créance soumise aux impayés |
| 6 | Quantité chargée / remise < précommandée | Total recalculé ; non-remis libéré ; trop-perçu éventuel |
| 7 | Trop-perçu | § 8.4–8.5 |
| 8 | Client veut plus | Nouvelle vente normale ; précommande intacte |
| 9 | Annulation avant préparation | `ventes.annuler_precommande` : réservation libérée, facture annulée, remboursement de l'encaissé net (D 419100) |
| 10 | Annulation après préparation | Même procédure, permission renforcée + confirmation (D1) |
| 11 | Client ne vient pas | « En retard » (ambre) ; aucune expiration ; annulation possible (9/10) |
| 12 | Livraison impossible | Retour (ADR 0003 amendé) ; trop-perçu remboursé ; nouvelle tentative = nouvelle vente (D7) |
| 13 | Livraison partielle | Retour partiel (vente standard) ou écart de réception (distribution / Grossiste) → trop-perçu |
| 14 | Produit réservé abîmé avant préparation | Réduire la quantité à la validation de préparation, puis ajustement de casse (C8) |
| 15 | Commissions | § 10 — jamais à l'acompte |
| 16 | Cashback | § 10 — jamais à l'acompte, une seule fois (idempotence existante) |
| 17 | Comptabilité de l'avance | § 9 |
| 18 | Dette / solvabilité | Contrôle des impayés à la création ; après remise, reste dû = créance ordinaire (`IMPAYEE`/`PARTIEL`, `montant_restant`) ; une facture `CREEE` n'est jamais comptée comme dette |

## 15. Découpage proposé

1. **Lot 1 — Socle et création** (**livré le 04/10/2026**, non déployable seul, cf. ci-dessous) :
   migrations, statut `reservee`, permission `ventes.precommander` + backfill, compte 419100,
   création atomique (retrait et livraison) avec acompte paramétrable, page Précommandes, marqueur,
   libellé « Réservé », paramètres d'acompte. Les colonnes du lot 2 (`quantite_preparee`,
   `preparation_lancee_at`, `preparee_at`, `remise_at`) sont créées mais pas encore utilisées ;
   `a_preparer` / `preparee` et leurs permissions arrivent avec leurs transitions.
   **À ne pas mettre en production avant le lot 2** : une précommande enregistrée ne peut encore ni
   être remise, ni annulée, ni remboursée.
2. **Lot 2 — Préparation, retrait, argent** (**livré le 04/10/2026**) : statuts `a_preparer` /
   `preparee`, préparation (réservation réduite sur place, `StockReservationService::reduire()`),
   retrait, activation de facture depuis l'encaissé (`PrecommandeService`), imputation 419100 → 411000,
   cascade cashback partagée (`FacturePayeeCascade`, C9), acomptes complémentaires, trop-perçu
   bloquant la clôture, remboursement (`remboursements_ventes`), annulation simple et renforcée (code
   e-mail selon le réglage de l'organisation), cinq permissions + backfill `admin_entreprise` /
   `manager`. Écran : carte « Précommande » de la fiche (`PrecommandeActions.vue`).
   **Limites connues, traitées au lot 3** : une précommande livrée avec acompte ne peut pas encore
   faire l'objet d'un retour (ADR 0003 pas encore amendé dans le code) ni d'un écart de réception
   inférieur aux acomptes (refus actuel) — le flux bloque, il ne fausse rien.
3. **Lot 3 — Livraison** : retour et écart de réception avec acomptes (C4, C5). Déjà en place au
   lot 2 : passage à charger à la validation de la préparation, chargement ≤ préparé, activation de
   la facture et passage « Livrée » d'une livraison soldée par ses acomptes.
4. **Lot 4 — Rapports** : date de vente = remise (C3), statuts hors CA, situation véhicule (une
   facture `CREEE` de précommande n'est pas un impayé), catégories de la fiche de caisse.

Tests attendus (par lot) : atomicité (échec de réservation ⇒ aucun encaissement ni écriture),
acompte minimum et plafond quand l'acompte est obligatoire, création sans acompte quand il ne
l'est pas (aucun encaissement, aucune écriture), réservation stricte même avec vente sans stock autorisée, isolation
organisationnelle de chaque nouvelle route, permissions (présence et absence), facture jamais
« Payée » avant remise, imputation 4191 → 411 équilibrée, cashback et commission une seule fois et
au bon moment sous les deux déclencheurs, trop-perçu bloquant la clôture, remboursement refusé si
solde insuffisant, retrait partiel libérant le reste, retour avec acompte, non-régression des ventes
normales (aucun encaissement avant chargement).

## 16. Hors périmètre V1

- Précommande depuis l'application client mobile.
- Prévente sur stock « Entrant » (production à venir).
- Avoir client (solde réutilisable) : les sommes sont remboursées.
- Expiration automatique.
- Remboursement des acomptes face à la remise à la trésorerie principale (ADR 0016) : les acomptes
  entrent dans le disponible de l'agence comme tout encaissement ; un remboursement ultérieur
  dépend du solde du support (refus sinon, financement par la trésorerie principale par le circuit
  existant). Une réserve dédiée pourra être étudiée plus tard.
