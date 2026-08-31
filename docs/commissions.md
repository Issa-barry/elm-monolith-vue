# Commissions — nature de l'opération et moteur générique à 3 processus

Chantier du 30/08/2026 : distinguer les ventes standard des distributions clients dans les
tableaux de bord et leur appliquer des barèmes de commission indépendants, sans dupliquer la
mécanique commerciale (`CommandeVente`/`FactureVente`/encaissement/solvabilité). Étend ensuite le
même principe de barème dynamique au transfert logistique interne.

## Règles métier (IDs)

- **COMM-001** — Une `CommandeVente` porte une `nature_operation` (`vente_standard` ou
  `distribution_client`), figée à la création, jamais recalculée si le client change de type
  ensuite.
- **COMM-002** — Défaut : `distribution_client` uniquement si `client.type = distributeur` **ET**
  un véhicule de flotte est rattaché. Un distributeur sans véhicule ELM (retrait sur site) reste
  `vente_standard`, au tarif distributeur, sans commission de distribution. L'utilisateur peut
  modifier ce choix par défaut avant l'enregistrement.
- **COMM-003** — `nature_operation = distribution_client` exige un véhicule — contrôlé côté
  backend, jamais uniquement par le formulaire (`CommandeVenteController::ensureNatureOperationCoherente()`).
- **COMM-004** (révisée le 30/08/2026 — la version précédente de cette règle affirmait l'inverse :
  workflow strictement identique entre les deux natures) — `distribution_client` est un hybride
  vente/logistique : commercialement une vente à part entière (même `CommandeVente`/`FactureVente`,
  même créance/paiement, indépendants de la logistique), mais logistiquement soumise à une
  validation de réception explicite avant de passer LIVREE — mission de distribution non
  considérée réalisée avant que le distributeur ait effectivement accepté la marchandise.
  `vente_standard` reste inchangée : LIVREE toujours déclenché par le premier encaissement (cf.
  `CommandeVenteService::passerEnLivree()`), jamais de validation de réception.
  - Workflow distribution_client : … → LIVRAISON_EN_COURS → **validation de réception**
    (`CommandeVenteService::validerReceptionDistribution()`, quantités par ligne dans
    `quantite_livree` — colonne préexistante mais jamais écrite avant cette révision, déjà lue par
    `CashbackService::quantiteEligible()`) → LIVREE. Un encaissement reçu avant la réception ne
    déclenche jamais LIVREE pour cette nature (`EncaissementVenteController` le vérifie
    explicitement) — statut et paiement sont deux axes indépendants pour la distribution,
    contrairement à la vente standard où le premier encaissement fait les deux à la fois.
  - Écart de réception vs chargement : décision produit du 30/08/2026, la facture est
    **recalculée sur le réceptionné**, jamais figée au chargement — le client n'est jamais facturé
    au-delà de ce qu'il a accepté (garde-fou : refusé si le nouveau total tomberait sous ce qui est
    déjà encaissé). Contrairement à l'écart de chargement, un écart de réception ne réajuste
    **jamais** le stock physique — les unités refusées restent sorties du stock, leur sort
    physique est traité hors de ce système.
  - Commission distribution_client : la validation de réception est son **unique** déclencheur,
    jamais conditionné au paramètre organisation `Parametre::getDeclencheurCommissionVente()` (qui
    ne régit plus que `vente_standard`) — calculée sur `quantite_livree`, jamais `quantite_chargee`
    (cf. `CommissionEnveloppeGenerator::contexteDepuisCommandeVente()`,
    `CommissionTriggerService::onReceptionDistributionValidee()`).
- **COMM-005** — La commission d'une commande est routée vers le processus `vente` ou
  `distribution_client` selon `nature_operation`, avec des barèmes (`CommissionRegle`)
  totalement indépendants — un même produit/catégorie peut avoir un montant différent en vente et
  en distribution.
- **COMM-006** — Le transfert logistique interne (usine → dépôt ELM) ne porte jamais de client ni
  de facture, quelle que soit sa commission — `TransfertLogistique` reste inchangé dans son
  fonctionnement de stock.
- **COMM-007** — La bascule du transfert logistique vers le moteur générique de commission est
  **par organisation**, jamais globale : une organisation reste sur l'ancien moteur
  (`CommissionLogistiqueService`) tant qu'elle n'a configuré aucune règle dans Paramètres >
  Commissions > Transferts logistiques. Aucune migration ni recalcul de l'historique déjà généré,
  qui reste consultable et payable indéfiniment sous son ancien schéma.

## Modèle

```
CommandeVente.nature_operation (vente_standard | distribution_client)
        │
        ▼
CommissionProcessus (vente | distribution_client | logistique_transfert)
        │
        ▼
CommissionRegle — COMBIEN (par catégorie/produit/variante, exceptions par type de véhicule)
        │
        ▼
CommissionEnveloppeGenerator — génération (CommissionEnveloppe / CommissionEnveloppePart)
```

`CommissionEnveloppeGenerator` est généralisé via `CommissionOperationContext` (contexte
générique : organisation, source, véhicule, site, lignes, quantité) — la résolution de règles et
la répartition d'équipe restent une seule implémentation, partagée par `CommandeVente` et
`TransfertLogistique`.

## Champs / fichiers clés

| Élément | Rôle |
|---|---|
| `commandes_ventes.nature_operation` | Colonne ajoutée par [`add_nature_operation_to_commandes_ventes_table`](../database/migrations/2026_08_30_090000_add_nature_operation_to_commandes_ventes_table.php). |
| `App\Enums\NatureOperation` | `deriverParDefaut()` = seule source de vérité de la dérivation par défaut. |
| `equipe_livraison_partages_categorie.processus_id` | Ajouté par [`add_processus_id_to_equipe_livraison_partages_categorie_table`](../database/migrations/2026_08_30_090100_add_processus_id_to_equipe_livraison_partages_categorie_table.php) — le partage GNF fixe entre livreurs d'une équipe peut désormais différer par processus sur la même catégorie. |
| `App\Services\Commission\CommissionOperationContext` | Contexte générique consommé par le générateur. |
| `App\Services\Commission\CommissionProcessusDefaults` | Valeurs par défaut (libellé/déclencheur/ancrage) par code processus — évite la duplication entre le générateur, le contrôleur de paramétrage et les services équipe. |
| `App\Services\CommissionTriggerService::estMigreVersMoteurGenerique()` | Bascule par organisation pour le transfert logistique. |
| `App\Services\Commission\CommissionPartageLivraisonCategorieChecker` | Source unique de la résolution "enveloppe équipe_livraison > 0 + partage actif ?" — partagée par le générateur, la validation de saisie de l'équipe et les garde-fous préventifs à la création (voir ci-dessous). |
| Paramètres > Commissions (`Settings\CommissionRegleController`) | Un seul écran, 3 onglets (`?processus=vente\|distribution_client\|logistique_transfert`), même grille catégorie × cible × type de véhicule pour les trois. |
| `Commercial > Ventes` / `Commercial > Distribution` | Même liste (`CommandeVenteController::index()`), filtrée par nom de route (`ventes.index` / `distributions.index`), jamais un paramètre modifiable côté client. |

## Historique / héritage

- Le moteur logistique legacy (`CommissionLogistique`/`CommissionLogistiquePart`,
  `CommissionLogistiqueService`) reste pleinement fonctionnel (génération, ajustement, paiement)
  pour toute organisation non migrée, et pour l'historique des organisations migrées.
- Le montant par défaut du moteur legacy (`200 GNF/pack`) est configurable par organisation
  (`Parametre::getMontantDefautCommissionLogistiquePack()`, Paramètres > Ventes) — remplace la
  valeur auparavant codée en dur, sans changer le comportement par défaut.
- `Comptabilite\CommissionLogistiqueController` (écran historique dédié aux transferts) reste
  structurellement legacy-only : il n'interroge que `CommissionLogistique`/`CommissionLogistiquePart`,
  jamais `CommissionEnveloppePart`. Pour une organisation migrée, cet écran cesse simplement de
  recevoir de nouvelles lignes (comportement voulu, cf. COMM-007) — les commissions de transfert
  générées après bascule apparaissent désormais dans les écrans Commission vente/sites/
  propriétaires/consultants (cible Livreur/Site/Propriétaire/Consultant), via le sélecteur
  Processus décrit ci-dessous.

## Partage Livreur par processus (équipe véhicule)

- Clé métier : `organisation + equipe + categorie + processus + livreur` — un même équipage peut
  avoir des montants fixes différents en Vente, Distribution client et Transfert logistique sur la
  même catégorie.
- `EquipeLivraisonController::store()`/`update()` exigent `processus_code` (whitelist des 3 codes,
  `Settings\CommissionRegleController::processusCodesDisponibles()`) — aucun repli implicite.
- `VehiculeController::show()` (barèmes + partages affichés) et `Vehicules/Show.vue` /
  `EquipeStepperModal.vue` (configuration) portent un sélecteur `SelectButton` Processus
  (`?processus=...`) — chaque onglet affiche/édite exclusivement le partage de ce processus, les
  autres restant inchangés.
- Si aucun partage n'existe pour le processus demandé au moment de la génération, la commission
  Livreur correspondante est marquée « À régulariser » (jamais un montant à 0 silencieux, jamais un
  repli sur le partage d'un autre processus) — cf. `CommissionMoteurGeneriqueMultiProcessusTest::
  transfert_migre_avec_bareme_mais_sans_partage_equipe_est_marque_a_regulariser_jamais_zero_silencieux`.
- **Garde-fou préventif à la création** (30/08/2026, cf. incident CMD-300826-007 : commande
  facturée et payée le jour même de l'ajout de `processus_id` ci-dessus, bloquée « à régulariser »
  faute de partage migré pour `distribution_client`) — `CommandeVenteController::store()` et
  `TransfertLogistiqueController::store()` refusent désormais la création (`ValidationException`
  sur `vehicule_id`, jamais un simple avertissement) si l'équipe du véhicule sélectionné n'a aucun
  partage actif pour une catégorie vendue/transférée dont l'enveloppe équipe_livraison est positive
  sur le processus résolu, via `CommissionPartageLivraisonCategorieChecker`. Ce contrôle :
  - rejoue exactement la même résolution que la génération (même enveloppe, même notion de
    partage actif) — jamais une règle divergente ;
  - ne s'applique jamais à une vente sans véhicule, à un véhicule non éligible aux commissions
    (`livraison_vente = false`), ni — côté transfert — à une organisation non migrée vers le
    moteur générique (`CommissionTriggerService::estMigreVersMoteurGenerique()`) ;
  - reste un contrôle **préventif**, pas une garantie : le filet de sécurité de la génération
    (ci-dessus) reste seul responsable au moment réel de la génération, la configuration pouvant
    encore changer entre la création et le déclencheur (chargement/encaissement/réception).

## Reporting Comptabilité — cloisonnement par processus

Les 4 écrans bénéficiaires (`Comptabilite\CommissionVenteController` + Site/Proprietaire/Consultant)
partagent le même risque : leur source `CommissionEnveloppePart` est interrogée par
`beneficiaire_type` seul, qui ne distingue pas Vente/Distribution client/Transfert logistique (les
trois processus peuvent produire des parts pour le même bénéficiaire).

- **Listes (Index)** — filtrées par défaut sur `vente` sur les 4 écrans (préserve le comportement
  historique de ces écrans, tous nommés/brandés « … sur les ventes » avant le chantier du
  30/08/2026), avec un sélecteur Processus explicite (`App\Support\Commission\CommissionProcessusFilter`,
  champ `DataFilters` inline en première position) pour basculer sur Distribution client ou
  Transfert logistique — jamais de mélange silencieux, jamais de filtre non désactivable.
- **Exports Excel/PDF** — reprennent le même filtre que l'écran Index dont ils sont issus.
- **Jamais filtrées par processus** — `PeriodeCalculatorService`,
  `CommissionEnveloppePartAllocationService` et tout le pipeline de validation/paiement de période :
  une période comptable doit couvrir la totalité des commissions d'un bénéficiaire, quel que soit
  leur processus d'origine. Les paiements réels (`PaiementFichePaiement`, historique affiché sur la
  fiche détail) ne sont eux non plus jamais ventilés par processus — l'argent versé à un bénéficiaire
  ne « sait » pas de quel processus il provenait.

### Détail (Show) — révisé le 31/08/2026 pour Commission vente / Livreur uniquement

**Ancien comportement (toujours en vigueur sur Site/Proprietaire/Consultant, cf. point ci-dessous)** :
la fiche détail héritait du même filtre `processus` que l'Index, propagé via le lien « Voir détail »
(`?processus=...`) et préservé à travers tout changement de période/véhicule/agence sur la page.
Défaut observé en usage réel : un bénéficiaire dont la commission Distribution/Transfert n'apparaissait
jamais nulle part sur sa fiche personnelle, sans sélecteur visible pour la faire apparaître — la fiche
semblait alors « oublier » une partie réelle de sa situation financière.

**Nouveau comportement (`Comptabilite\CommissionVenteController::showLivreur()` uniquement)** :

- La fiche personnelle d'un bénéficiaire affiche par défaut **Tous les processus** confondus
  (`filtre_processus === ''`), indépendamment du filtre actif sur l'Index d'où l'on vient — on
  regarde la situation financière globale de la personne, pas seulement son activité de vente.
  `Comptabilite/CommissionVente/Index.vue` ne transmet donc plus `?processus=...` dans son lien vers
  la fiche ; l'Index lui-même garde son propre défaut `vente` (identité de cet écran, inchangée).
- Un sélecteur Processus est désormais visible sur la fiche elle-même
  (`CommissionGlobalFilters.vue`, prop optionnelle `processusOptions` — n'affecte aucun autre écran
  qui ne la fournit pas), avec un choix « Tous les processus » en tête
  (`CommissionProcessusFilter::optionsAvecTous()`).
- En vue « Tous les processus », un bloc `CommissionProcessusBreakdown` ventile le total généré par
  processus (Vente / Distribution client / Transfert logistique), dont la somme reconstitue
  exactement `commission_summary.total_genere` — jamais un quatrième nombre indépendant. Ce bloc
  disparaît dès qu'un processus précis est sélectionné (redondant, tout y appartient déjà à ce seul
  processus).
- Chaque ligne du détail par commande (`commission_details`) porte son origine
  (`processus`/`processus_label`) — affichée par `CommissionDetailTable.vue` (colonne « Origine »,
  visible uniquement si au moins une ligne la fournit) : jamais de montants de processus différents
  mélangés sans indication, y compris en vue consolidée.
- Les montants eux-mêmes (net à payer/reste à payer/`en_attente_periode`/`payable`) restent calculés
  exactement comme avant sur l'ensemble des parts effectivement affichées (toutes si « tous », un
  seul processus si filtré) — aucun changement de la logique financière, seulement de ce qui est
  montré par défaut et de la transparence sur l'origine.

**Hors périmètre de ce chantier** : `CommissionSiteController`, `CommissionProprietaireController` et
`CommissionConsultantController` gardent l'ancien comportement (fiche = même filtre que l'Index,
propagé et préservé) — signalé, non corrigé, candidat naturel à la même révision si demandée.

## Vente directe sans véhicule — mouvement de stock (correctif du 30/08/2026)

`CommandeVenteService::creerFactureDirecte()` (vente directe, sans véhicule ni étape de chargement)
émettait jusqu'ici une facture sans jamais toucher au stock — ni réservation, ni sortie physique.
Corrigé par `decrementerStockDirect()` (sortie physique immédiate via
`MouvementStockService::sortirStock()`, même garde-fou anti-survente et même politique
`Parametre::isVentesAutoriseesSansStock()` que le chemin véhicule) et
`MouvementStockService::annulerSortieStock()` (réintègre le stock si la vente directe est annulée
avant tout encaissement). Non lié à la distinction Vente/Distribution — un bug préexistant, corrigé
dans ce même chantier à la demande explicite de l'utilisateur (voir tests
`tests/Feature/CommandeVenteDirecteStockTest.php`).
