# Précommandes — spécification fonctionnelle et technique V1

- **Date** : 2026-10-04
- **Statut** : **validée le 2026-10-04** (D1 à D14 acceptées, § 13 ; pas d'interrupteur d'activation
  en V1). Développement par lots (§ 15) : lots 1, 2 et 3 livrés, lot 4 à faire.
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
| **C4** | **ADR 0003** : retour de livraison impossible dès qu'un encaissement existe. | Une précommande livrée avec acompte ne pourrait jamais faire l'objet d'un retour. | Pour une précommande, les **acomptes ne bloquent pas le retour** ; la valeur retournée devient un trop-perçu à rembourser (§ 8.4). Un encaissement du **solde** (après remise) ferme la fenêtre de retour comme pour toute vente : c'est lui qui confirme la livraison. Amende l'ADR 0003. **Livré au lot 3.** |
| **C5** | `validerReception()` refuse un écart si l'encaissé dépasse le nouveau total (« Régularisez l'encaissement »). | Cas normal d'une précommande avec acompte élevé et réception partielle. | Pour une précommande : écart accepté, l'excédent devient un **trop-perçu à rembourser**. Le refus est conservé pour les autres ventes. **Livré au lot 3.** |
| **C10** | Lot 2 : une livraison **soldée par ses acomptes** passait « Livrée » dès la validation du chargement. | Le chargement ne prouve pas la remise : si la livraison échoue (panne, client absent, refus), aucun retour n'était plus possible — contradiction avec D7 / D9. | **Chargement ≠ livraison** (D13) : la précommande reste « En livraison » jusqu'à **« Confirmer la livraison »** (`ventes.valider_reception`), l'encaissement du solde ou la validation de réception. **Livré au lot 3.** |
| **C11** | Cashback calculé sur `quantite_livree ?? quantite_demandee` : la quantité **chargée** (ou remise) était ignorée. | Une vente chargée ou remise en quantité inférieure à la commande donnait un cashback sur la quantité commandée. | Quantité **effective** (livrée, sinon chargée, sinon commandée) pour **toutes les ventes** ; pour une précommande en livraison, cashback seulement une fois la livraison définitive (D14). **Livré au lot 3.** |
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
       PREPAREE ──(Valider le retrait : qté remise)──► FACTURATION ──► CLOTUREE   A_CHARGER ──► chargement
          (en attente du client)             (sortie stock, facture        (existant)        (existant)
                                              activée, acomptes imputés)                         │
                                                                                                 ▼
                                    LIVRAISON_EN_COURS (sortie stock, facture activée, acomptes imputés)
                                       │  retour / écart de réception possibles malgré les acomptes
                                       ▼
            Confirmer la livraison | encaissement du solde | validation de réception ──► LIVREE ──► CLOTUREE
RESERVEE / A_PREPARER / PREPAREE / A_CHARGER ──(Annuler la précommande + remboursement)──► ANNULEE
LIVRAISON_EN_COURS ──(retour total)──► RETOURNEE (encaissé net à rembourser)
```

| Transition | Action | Permission | Effets |
|---|---|---|---|
| — → `reservee` | Enregistrer la précommande | `ventes.precommander` | Réservation (stricte), facture `CREEE`, acompte éventuel (§ 8.1) |
| `reservee` → `a_preparer` | Lancer la préparation | `ventes.preparer` | Horodatage (cf. D5) |
| `a_preparer` → `preparee` (retrait) / `a_charger` (livraison) | Valider la préparation | `ventes.preparer` | `quantite_preparee` ≤ demandée ; réservation **réduite** à la quantité préparée (nouvelle primitive `StockReservationService::reduire()`) ; total et facture recalculés |
| `preparee` → `facturation` | Valider le retrait | `ventes.valider_retrait` | `quantite_chargee` (remise) ≤ préparée ; réservation consommée + sortie de stock (`decrementerStock()` existant) ; total recalculé ; facture activée (§ 8.3) ; `remise_at` ; clôture si soldée |
| `a_charger` → … | Workflow existant | permissions existantes | Chargement : `quantite_chargee` ≤ préparée ; `remise_at` = validation du chargement ; facture activée, acomptes imputés ; la commande reste **`livraison_en_cours`**, même soldée (D13) |
| `livraison_en_cours` → `livree` | **Confirmer la livraison** (lot 3, D13) — sans réception explicite | `ventes.valider_reception` | Cashback si la facture est payée, clôture si tout est réglé. Aussi atteint par l'encaissement du solde (règle des ventes) ou, pour une distribution / Grossiste livré, par la validation de réception |
| `livraison_en_cours` → … | Retour de livraison / écart de réception (lot 3) | `ventes.enregistrer_retour` / `ventes.valider_reception` | Acomptes non bloquants ; excédent = trop-perçu (§ 8.4) ; retour total ⇒ `retournee`, encaissé net à rembourser |
| `reservee` → `annulee` | Annuler la précommande | `ventes.annuler_precommande` | Motif (≥ 10 caractères) ; remboursement de l'encaissé net dans la même opération ; réservation libérée ; facture annulée (jamais comptabilisée) |
| `a_preparer` / `preparee` / `a_charger` → `annulee` | Annuler la précommande — **procédure renforcée** (D1) | `ventes.annuler_precommande_preparee` | Idem + code e-mail si l'organisation l'exige (même réglage que l'annulation exceptionnelle). Jamais une fois le chargement démarré |

« Retirée » n'est pas un statut : la page Précommandes l'affiche pour toute précommande dont
`remise_at` est renseigné et la commande en `facturation`/`cloturee`. Le statut de paiement reste
porté par la facture (Payée / Partiel / Impayée), jamais mélangé au statut de commande.

**Livraison déjà soldée par les acomptes** (révisé au lot 3, D13) : le chargement **ne vaut pas
livraison**. La commande reste « En livraison » — un retour ou un écart reste possible si la
livraison échoue — jusqu'à l'action **« Confirmer la livraison »** (`ventes.valider_reception`,
affichée sur la fiche de toute précommande en livraison sans réception explicite). Avant le lot 3,
elle passait « Livrée » dès le chargement validé : aucun retour n'était alors possible. Une
précommande non soldée peut aussi être confirmée par l'encaissement de son solde, comme toute
vente ; une distribution / Grossiste livré l'est par la validation de réception.

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
   cashback, clôture — point unique partagé avec le contrôleur d'encaissement (C9). Pour une
   **livraison**, le cashback attend que la livraison soit **définitive** (confirmée, ou réception
   validée — D14) : `FacturePayeeCascade` ne fait rien tant que la précommande est « En livraison ».
5. Le reste dû s'encaisse ensuite par l'écran existant (`est_acompte = false`).

### 8.4 Trop-perçu
Encaissé net > montant facturé (quantité remise inférieure, retour, écart de réception) : la fiche
affiche **« Trop-perçu à rembourser »**, la précommande apparaît dans le filtre correspondant, et la
**clôture est bloquée** tant qu'il n'est pas remboursé (cf. D10).

**Retour total** (lot 3) : la commande passe « Retournée » et sa facture est annulée, mais l'argent
du client est toujours détenu (compte 411000 créditeur après la pièce `vente_retour`). Pour une
précommande, le trop-perçu d'une facture annulée vaut donc **tout l'encaissé net**
(`FactureVente::tropPercu()`), remboursable par la même action « Rembourser ».

**Retour ou écart sur une précommande** (lot 3) : après le recalcul du montant, le statut de la
facture est recalculé depuis l'encaissé net (`recalculStatut()`) — une facture « Partiel » peut
devenir « Payée » — et l'excédent apparaît comme trop-perçu.

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
| Livraison, déclencheur « chargement validé » (défaut) | À la validation du chargement (inchangé) | À la facture payée **et** livraison définitive : confirmation de livraison ou encaissement du solde (D14) |
| Livraison, déclencheur « facture encaissée » | Quand la facture devient réellement payée, **après** remise | idem |
| Distribution / Grossiste livré | À la validation de réception (inchangé) | À la facture payée **et** réception validée (D14) |
| Retour / écart / annulation | Règles existantes (réajustement, annulation des commissions non soldées) | Aucun gain à reprendre : un retour ou un écart survient pendant la livraison, **avant** le cashback (D14) |

**Quantité du cashback** (D14, toutes ventes) : quantité **effective** de chaque ligne fabricable —
livrée, sinon chargée (ou remise), sinon commandée (`CommandeVenteLigne::quantite_effective`, cf.
[cashback.md](cashback.md)).

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
| Ventes → **Précommandes** (nouveau menu, permission `ventes.read`, comme la liste Ventes) | Liste : Référence, Client, Agence, Date de précommande, Date prévue, Mode, Total, Acompte, Reste à payer, Quantité réservée, Statut (`StatusDot`), indicateurs En retard / Trop-perçu. `DataFilters` : Agence (`site_ids[]`) → Statut → filtres inline (Mode, En retard, Trop-perçu) → drawer (dates). Sur le modèle de la page Distribution. **Compteurs en tête de page (05/10/2026)** — pilotage du cycle, aucun montant (le suivi financier reste sur les factures et la liste Ventes) : **En cours** (réservée, à préparer, préparée, à charger, chargement en cours, en livraison), **À préparer** (réservée ou à préparer : préparation à lancer ou à valider), **En livraison** (`livraison_en_cours` : chargée, livraison pas encore confirmée, D13), **En retard** (`isEnRetard()`, ambre — D8). Regroupements définis côté serveur (`PrecommandeSuivi`) ; compteurs calculés avec les filtres Agence / dates / texte mais **hors** filtres Statut et En retard, car chaque carte sert de filtre rapide (`statuts[]` ou `en_retard=1`, second clic = retrait). Colonnes Date prévue et Mode placées juste après la Référence. Pour joindre chacun sans ouvrir la fiche : immatriculation sous le véhicule, téléphone sous le livreur (chauffeur de l'équipe) et sous le client ; sur mobile, la fiche de la commande donne les deux téléphones. |
| Ventes (liste) | Boutons « Nouvelle vente » / « Nouvelle précommande » ; marqueur « Précommande » sur les lignes. |
| Nouvelle précommande | § 7.1. |
| Fiche commande | Badge « Précommande », date prévue, bloc Acomptes / Encaissé net / Reste / Trop-perçu, actions selon statut et permission : Lancer la préparation, Valider la préparation, Valider le retrait, **Confirmer la livraison** (lot 3), Ajouter un acompte, Rembourser, Annuler la précommande. Le bouton **Retour** existant (ADR 0003) et l'écart de réception sont disponibles malgré les acomptes (lot 3). Étapes affichées en livraison : Réservée → À préparer → À charger → En livraison → Livrée. Mêmes onglets qu'une vente (Informations, Produits, Facturation, **Journal d'activité**, **Historique**) — aucun historique propre aux précommandes. Le Journal (`CommandeVenteActiviteService`) trace, avec auteur et date/heure : enregistrement (acompte, date prévue), acompte complémentaire, lancement et validation de la préparation (quantité préparée), retrait (quantité remise), démarrage et validation du chargement (quantité chargée), confirmation de livraison, retour (quantité, montant, motif), remboursement (montant, motif), annulation (motif) et clôture (05/10/2026 : attribuée à l'auteur de l'action qui la déclenche, « Système » hors requête — vaut pour toutes les ventes). L'Historique (`AuditLog`) garde les modifications de données : création, encaissements et acomptes, modification, annulation. |
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
| D13 | Livraison soldée par les acomptes : comment la livraison est-elle confirmée ? (C10) | **Chargement ≠ livraison** : la précommande reste « En livraison » après le chargement, même payée à 100 % ; action explicite **« Confirmer la livraison »** protégée par la permission existante `ventes.valider_reception` (aucune permission nouvelle). Révise la règle du lot 2 (passage « Livrée » au chargement). | ☑ Accepté (04/10/2026) |
| D14 | Cashback : base et moment (C11) | Quantité **effective** (livrée, sinon chargée, sinon commandée) pour **toutes les ventes**, précommandes comprises ; pour une précommande en livraison, cashback déclenché **seulement** à la livraison définitive (confirmation ou réception validée), jamais au chargement — aucun mécanisme de reprise de cashback n'est nécessaire. | ☑ Accepté (04/10/2026) |
| D15 | Date de réalisation de la vente (lot 4) | **Validée le 05/10/2026** : précommande en **livraison** ⇒ vente datée à la **confirmation de livraison** (ou à la validation de réception) ; en **retrait** ⇒ à la **remise effective** au client. Le chargement n'est que la date de sortie du stock. Tant que la vente n'est pas réalisée (créée, réservée, à préparer, préparée, à charger, chargée, en livraison), elle ne compte **jamais** dans le chiffre d'affaires — dashboard, rapports, indicateurs, agrégations et comptabilité. Les autres dates (création, chargement, remise) sont conservées telles quelles. Amende l'ADR 0007 §3 pour les précommandes. | ☑ Accepté |
| D16 | Comptabilisation de la vente d'une précommande livrée (conséquence de D15) | **À valider** — proposition : la facture est activée et la vente comptabilisée (411/701, imputation des acomptes 419100 → 411000) à la **confirmation de livraison** au lieu du chargement. Conséquences : pendant « En livraison », la facture reste « Créée » et un paiement reçu est un acompte (419100) ; le retour et l'écart de réception s'appliquent avant toute écriture de vente. La commission (déclencheur « chargement validé ») et la sortie de stock restent au chargement. | ☐ À valider |
| D17 | Ventes ordinaires (hors précommandes) | **À valider** — proposition : inchangées en V1, toujours datées à la création de leur facture (ADR 0007 §3), y compris une vente avec véhicule dont la facture naît à la confirmation, avant le chargement. Aligner aussi ces ventes sur la livraison serait un chantier distinct. | ☐ À valider |

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
| 12 | Livraison impossible | Retour total pendant la livraison (ADR 0003 amendé), même soldée par les acomptes (D13) : stock réintégré, commande « Retournée », facture annulée, **encaissé net remboursé** ; nouvelle tentative = nouvelle vente (D7) |
| 13 | Livraison partielle | Retour partiel (vente standard) ou écart de réception (distribution / Grossiste) → montant recalculé, statut de facture recalculé, trop-perçu à rembourser, clôture bloquée jusqu'au remboursement |
| 13 bis | Livraison soldée, bien remise | « Confirmer la livraison » : Livrée, cashback sur la quantité effective, clôture si les commissions sont réglées |
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
   Limites du lot 2, levées par le lot 3 : pas de retour d'une précommande livrée avec acompte, pas
   d'écart de réception inférieur aux acomptes, et livraison soldée passée « Livrée » dès le
   chargement.
3. **Lot 3 — Livraison** (**livré le 04/10/2026**) : retour malgré les acomptes (C4,
   `CommandeVente::raisonRetourImpossible()` ne compte que les encaissements hors acompte), écart de
   réception supérieur aux acomptes accepté pour une précommande (C5), statut de facture recalculé
   après retour / écart, retour total ⇒ encaissé net remboursable, **« Confirmer la livraison »**
   (D13, `PrecommandeService::confirmerLivraison()`, route `precommandes.livraison.confirmer`),
   cashback différé jusqu'à la livraison définitive et calculé sur la quantité effective pour toutes
   les ventes (D14). Déjà en place au lot 2 : passage à charger à la validation de la préparation,
   chargement ≤ préparé, activation de la facture au chargement.
4. **Lot 4 — Date de réalisation de la vente** (D15, D16 et D17 à valider avant tout code) :
   une **date de vente** portée par la facture (`factures_ventes.date_vente`), renseignée à la
   création pour une vente ordinaire (= date de création, historique repris tel quel) et seulement à
   la réalisation pour une précommande (retrait : remise ; livraison : confirmation ou réception
   validée) ; toutes les mesures de chiffre d'affaires lisent cette date et ignorent une vente non
   réalisée — tableau de bord (indicateurs, courbes, CA par site, par type de véhicule, par produit),
   API de statistiques, rapport d'activité, situation agent, situation véhicule ; comptabilisation de
   la vente au moment de la réalisation (D16).

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
