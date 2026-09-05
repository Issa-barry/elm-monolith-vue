# Commissions — nature de l'opération et moteur générique (vente / logistique)

Chantier du 30/08/2026 : distinguer les ventes standard des distributions clients dans les
tableaux de bord et leur appliquer des barèmes de commission indépendants, sans dupliquer la
mécanique commerciale (`CommandeVente`/`FactureVente`/encaissement/solvabilité). Étend ensuite le
même principe de barème dynamique au transfert logistique interne.

## Règles métier (IDs)

- **COMM-001** — Une `CommandeVente` porte une `nature_operation` (`vente_standard` ou
  `distribution_client`), figée à la création, jamais recalculée si le client change de type
  ensuite.
- **COMM-002** (révisée le 31/08/2026 — la version précédente ne conditionnait le défaut qu'à « un
  véhicule de flotte rattaché », sans distinguer son usage) — Défaut : `distribution_client`
  uniquement si `client.type = distributeur` **ET** le véhicule rattaché est autorisé pour la
  logistique (`vehicule.livraison_logistique = true`). Un distributeur sans véhicule ELM (retrait
  sur site) ou avec un véhicule vente-seulement reste `vente_standard`, au tarif distributeur, sans
  commission de distribution (`App\Enums\NatureOperation::deriverParDefaut()`, seule source de
  vérité). L'utilisateur peut modifier ce choix par défaut avant l'enregistrement, sous réserve de
  COMM-003.
- **COMM-003** (révisée le 31/08/2026 — verrouillage métier/backend d'une distribution client,
  cf. `tests/Feature/DistributionClientVehiculeLogistiqueTest.php`) — `nature_operation =
  distribution_client` exige, contrôlé côté backend (jamais uniquement par le formulaire, requête
  forgée incluse) :
  - un véhicule (`vehicule_id` non vide) ;
  - appartenant à la même organisation, actif (`is_active`) ;
  - autorisé pour la logistique (`livraison_logistique = true`) — un véhicule vente-seulement est
    refusé même si sa capacité suffirait ;
  - livreur obligatoire : le véhicule doit avoir une `EquipeLivraison` active portant au moins un
    chauffeur actif (`role = chauffeur` dans le pivot `equipe_livreurs`) — il n'existe pas de champ
    `livreur_id` sur `CommandeVente`, le livreur est entièrement dérivé de l'équipe du véhicule.

  Toute violation renvoie une `ValidationException` (422) sur `nature_operation` ou `vehicule_id`
  selon le cas (`CommandeVenteController::ensureNatureOperationCoherente()`), appelée à la fois
  depuis `store()` et `update()` — deux points d'entrée indépendants, aucun ne suppose que l'autre a
  déjà protégé la donnée. `vente_standard` n'est soumise à aucune de ces exigences.

  Côté UI (`Ventes/Create.vue`), la liste de véhicules proposée à la saisie dépend du **type de
  client** (jamais de `nature_operation`, pour éviter une dépendance circulaire tant qu'aucun
  véhicule n'est choisi) : un client `distributeur` ne voit que les véhicules logistiques
  (prop `vehicules_distribution`, résolue par `CommandeVenteController::vehiculesLogistiques()`) ;
  les autres types voient la liste vente-only historique (`vehicules`, inchangée). Un véhicule déjà
  sélectionné qui quitte le pool courant (changement de type de client, ou retour arrière) est
  désélectionné automatiquement (`useDistributionVehiculePool.ts`) plutôt que laissé affiché mais
  invalide. `Ventes/Edit.vue` n'est pas concerné par ce chantier (source de véhicules inchangée).
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
- **COMM-005** (révisée le 02/09/2026 — la version précédente affirmait l'inverse : un processus
  `distribution_client` totalement fusionné avec `logistique_transfert`, plus jamais résolu) —
  Décision produit du 02/09/2026 : le processus MÉTIER d'une commande (identité, reporting,
  historique) et le BARÈME (le montant réellement appliqué) sont deux notions distinctes. Une
  distribution génère **toujours** une `CommissionEnveloppe` rattachée à
  `CommissionProcessus::CODE_DISTRIBUTION_CLIENT` — jamais silencieusement reclassée en
  `logistique_transfert` — pour rester identifiable et reportée séparément, dès aujourd'hui, même
  si son barème est actuellement hérité de la logistique.
  - Tant qu'aucune `CommissionRegle` active n'existe pour `distribution_client`, le calcul du
    montant retombe automatiquement sur celui de `logistique_transfert`
    (`CommissionProcessusDefaults::processusResolutionBareme()`) — un distributeur ELM est livré
    par la même flotte/équipe qu'un transfert interne, décision confirmée par le métier. Bascule
    "tout ou rien" par ORGANISATION, jamais par cible : dès qu'UNE seule `CommissionRegle` active
    existe pour `distribution_client`, il cesse immédiatement d'hériter de `logistique_transfert`,
    même pour les cibles où lui-même n'aurait rien configuré.
  - Ce repli ne rend jamais `distribution_client` et `logistique_transfert` interchangeables pour
    autant : le métier pourrait demain configurer un barème distribution différent du barème
    logistique sans aucun changement de code — il suffit d'ajouter une `CommissionRegle` pour
    `distribution_client`. Fusionner les deux processus aurait rendu cette divergence future
    impossible sans dette technique (migration de données a posteriori) ; le repli l'évite en
    gardant l'identité et le barème séparés dès le départ.
  - `CommissionProcessus::CODE_DISTRIBUTION_CLIENT` reste absent de
    `Settings\CommissionRegleController::processusCodesDisponibles()` (pas d'onglet de
    configuration dédié dans Paramètres > Commissions, ni de tab dédié sur la fiche véhicule) tant
    que le métier n'a pas besoin d'un barème distinct — mais N'EST PAS pour autant "legacy" :
    chaque NOUVELLE distribution continue de générer une `CommissionEnveloppe` sous ce code, au
    même titre qu'avant cette révision.
  - Le garde-fou préventif à la création
    (`CommandeVenteController::ensurePartageLivraisonCategorieConfigure()`) applique exactement la
    même résolution identité → barème que le générateur réel
    (`CommissionEnveloppeGenerator::genererPourCommandeVente()`), jamais une résolution divergente
    qui validerait ou exigerait un partage différent de celui réellement consommé.
  - `App\Support\Commission\CommissionProcessusFilter` (reporting Comptabilité) continue de
    distinguer les 3 codes — plus que jamais justifié : `distribution_client` reste un processus
    vivant et croissant, pas seulement un solde historique à réconcilier. Ne jamais aligner ce
    filtre sur `processusCodesDisponibles()`.
- **COMM-006** — Le transfert logistique interne (usine → dépôt ELM) ne porte jamais de client ni
  de facture, quelle que soit sa commission — `TransfertLogistique` reste inchangé dans son
  fonctionnement de stock.
- **COMM-007** (révisée le 03/09/2026 — la version précédente décrivait une bascule PAR
  ORGANISATION, retirée depuis) — Le moteur générique (`CommissionEnveloppeGenerator`) est
  désormais le SEUL moteur de commission logistique, pour toute organisation, sans exception.
  `CommissionTriggerService::estMigreVersMoteurGenerique()` et les méthodes de génération de
  `CommissionLogistiqueService` (`genererPourTransfert`/`genererAutomatique`/`genererDepuisChargement`)
  ont été retirés après vérification directe en production (`commission_logistique_parts` :
  0 ligne, aucun solde restant) qu'aucune commission legacy n'était en attente de paiement. Les
  tables `commissions_logistiques`/`commission_logistique_parts`/`versements_commission_logistique`
  ne reçoivent donc plus jamais de nouvelle ligne — conservées uniquement pour un éventuel
  historique antérieur (cf. section Historique/héritage).

## Modèle

```
CommandeVente.nature_operation (vente_standard | distribution_client)
        │
        ▼
CommissionProcessus D'IDENTITÉ (vente | distribution_client | logistique_transfert)
        │   — tague la CommissionEnveloppe générée (reporting/historique), jamais fusionné
        ▼
CommissionProcessusDefaults::processusResolutionBareme()
        │   — distribution_client SANS CommissionRegle propre retombe sur logistique_transfert ;
        │     vente et logistique_transfert sont toujours leur propre source de barème
        ▼
CommissionProcessus DE BARÈME (peut différer de l'identité, uniquement pour distribution_client)
        │
        ▼
CommissionRegle — COMBIEN (par catégorie/produit/variante, exceptions par type de véhicule)
        │
        ▼
CommissionEnveloppeGenerator — génération (CommissionEnveloppe.processus_id = IDENTITÉ,
                                            règles/partages lus sur le processus de BARÈME)
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
| `App\Services\Commission\CommissionPartageLivraisonCategorieChecker` | Source unique de la résolution "enveloppe équipe_livraison > 0 + partage actif ?" — partagée par le générateur, la validation de saisie de l'équipe et les garde-fous préventifs à la création (voir ci-dessous). |
| Paramètres > Commissions (`Settings\CommissionRegleController`) | Un seul écran, 2 onglets (`?processus=vente\|logistique_transfert`) — `distribution_client` reste un processus réel côté génération/reporting mais sans onglet dédié, cf. COMM-005 (repli automatique du barème sur `logistique_transfert` tant qu'il n'a pas sa propre configuration). |
| `Commercial > Ventes` / `Commercial > Distribution` | Même liste (`CommandeVenteController::index()`), filtrée par nom de route (`ventes.index` / `distributions.index`), jamais un paramètre modifiable côté client. |

## Historique / héritage

- Depuis le 03/09/2026 (cf. COMM-007), le moteur logistique legacy (`CommissionLogistique`/
  `CommissionLogistiquePart`, `CommissionLogistiqueService`) ne génère plus AUCUNE nouvelle
  commission — vérifié sans solde restant en production avant ce retrait. `CommissionLogistiqueService`
  ne survit que pour son unique méthode `verser()` (paiement d'un éventuel solde déjà existant) ;
  ses méthodes de génération ont été supprimées avec le switch.
- `Comptabilite\CommissionLogistiqueController` (écran Comptabilité > Commissions > Logistique)
  reste en place mais n'interroge que `CommissionLogistique`/`CommissionLogistiquePart`, jamais
  `CommissionEnveloppePart` — il ne recevra donc plus jamais de nouvelle ligne pour aucune
  organisation. Conservé volontairement (pas de suppression de table dans cette PR, cf. plan de
  retrait) : à retirer dans une PR séparée une fois confirmé qu'aucune organisation n'a de solde
  historique à régler via cet écran. Toutes les commissions de transfert logistique, désormais et
  pour toujours, apparaissent dans les écrans Commission vente/sites/propriétaires/consultants
  (cible Livreur/Site/Propriétaire/Consultant), via le sélecteur Processus décrit ci-dessous.
- Deux contrôleurs orphelins (aucune route ou aucun appelant frontend restant) ont été supprimés
  le 03/09/2026 dans le cadre de ce retrait : `App\Http\Controllers\CommissionLogistiqueController`
  (génération manuelle d'une commission par transfert, route `logistique/{transfert}/commission` —
  la dernière brèche capable de créer une ligne legacy après le retrait du switch) et
  `App\Http\Controllers\CommissionLogistiqueValidationController` (stub sans route enregistrée).
  Le formulaire frontend correspondant (`Logistique/Show.vue`, dialog "Générer la commission")
  était déjà mort (aucun bouton ne l'ouvrait) et a été retiré avec elles. Le champ `montant_par_pack`
  (saisie manuelle admin à la validation de réception) a également été retiré de
  `ReceptionValidationAdminController`/`Api\Backoffice\Logistique\ValidationAdminController` : le
  montant est désormais TOUJOURS résolu par `CommissionRegle`, plus aucune saisie manuelle par
  transfert n'est possible.
- Le paramètre `montant_defaut_commission_logistique_par_pack` (Paramètres > Ventes,
  `Parametre::getMontantDefautCommissionLogistiquePack()`) reste exposé dans les Paramètres mais
  n'est plus lu par aucun code de génération — laissé en l'état (hors périmètre de ce retrait),
  puisqu'il s'agit d'un champ de configuration UI distinct, pas d'un mécanisme de génération.
- **04/09/2026** — `App\Http\Controllers\CommissionVehiculeController` (écran
  `/backoffice/logistique/commissions`, paiement DIRECT par livreur/véhicule) et
  `App\Http\Controllers\CommissionPaymentController` (ses deux routes POST) ont été **retirés**
  (routes, contrôleurs, pages `resources/js/pages/Logistique/Commissions/*.vue` et tests dédiés),
  contrairement à `Comptabilite\CommissionLogistiqueController` ci-dessus qui reste en place le
  temps d'une PR de retrait séparée. Différence assumée : cet écran n'avait plus aucun point
  d'entrée dans la navigation (aucun lien de menu, aucun lien restant depuis
  `Logistique/Show.vue`) — un simple `git grep` sur `logistique.commissions.` le confirme — alors
  que l'écran Comptabilité reste, lui, atteignable depuis son propre menu. `LivreurController::show()`
  (`commissions_url` de la fiche livreur, ex-`route('logistique.commissions.livreur', ...)`)
  pointe désormais vers `route('commissions.vente.livreur', ...)` sans filtre processus (« Tous
  les processus » — Vente/Distribution client/Transfert logistique confondus).
- **04/09/2026** — corrigé dans la foulée (même cause racine, détecté par les tests E2E
  `logistique-flow.spec.ts`) : le badge "Commission" de `Logistique/Index.vue` et l'étape
  "Commission" du stepper de `Logistique/Show.vue` (libellé Impayée/Partiellement payée/Payée
  **et** progression done/current du marqueur) lisaient encore `$t->commission` — la relation
  legacy ci-dessus, jamais peuplée pour une commission générée après le 03/09/2026. Recalculé à
  la volée dans `TransfertLogistiqueController::commissionStatutGenerique()` depuis
  `CommissionEnveloppePart` (même règle d'agrégation que l'ancien
  `CommissionLogistique::recalculStatutGlobal()`) et exposé sous les mêmes clés
  `commission_statut`/`commission_statut_label` qu'avant — aucun changement frontend nécessaire
  pour la liste, seule `Logistique/Show.vue` (stepper) a été mise à jour pour les consommer à la
  place de `commission?.statut_label`/`commission?.is_versee`.
- **04/09/2026 — fermé** (fait suite au point ouvert ci-dessus) : l'onglet "Commission
  logistique" de `Logistique/Show.vue` expose désormais un détail par livreur raccordé au moteur
  générique — `TransfertLogistiqueController::mapCommissionLivreursGeneriques()` filtre les
  `CommissionEnveloppePart` de toutes les enveloppes du transfert sur
  `beneficiaire_type = CommissionEnveloppePart::TYPE_LIVREUR` (jamais propriétaire/site/consultant)
  et expose `commission_generique_livreurs` (nom résolu via `Livreur::libelleAffichage()`,
  `montant_unitaire` = `montant_unitaire_snapshot`, `montant` = `montant_a_payer`). Le tableau
  affiché (Livreur / Part unitaire / Montant total gagné) est volontairement distinct du total
  global `commission_generique_montant_total` (qui agrège TOUTES les cibles — propriétaire, site,
  consultant, équipe de livraison) : les deux totaux sont étiquetés séparément dans la vue pour
  qu'un écart entre eux (dû aux autres cibles) ne soit jamais interprété comme une incohérence de
  calcul (cf. `mapCommissionLivreursGeneriques()`, docblock). Détail complet des autres
  bénéficiaires toujours via Commissions des livreurs (filtre Processus = Transfert logistique)
  pour la part livreur, ou en base pour les autres cibles — aucun écran dédié propriétaire/
  site/consultant à ce jour. `livreurParts`/`aggregateParts` (legacy, `transfert.commission?.parts`)
  restent en place uniquement pour l'historique pré-03/09/2026, inchangés.
  - Limite assumée et documentée dans le code (pas corrigée, pas un bug) :
    `montant_unitaire_snapshot` est un instantané de la DERNIÈRE catégorie de produit traitée par
    `CommissionEnveloppeGenerator` pour ce livreur — si un transfert mélange plusieurs catégories
    à part unitaire différente pour le même livreur, la « part unitaire » affichée ne représente
    qu'une des catégories, jamais recalculée côté frontend (montant / quantité ne serait pas
    fiable dans ce cas) ; le montant total, lui, reste exact car il vient de `montant_a_payer`.
  - Testé par `CommissionTriggerLogistiqueTest::test_reception_effectuee_expose_le_detail_par_livreur_sur_la_page_transfert()`
    (répartition 60/40 correcte, total livreurs = total enveloppe quand seule la cible équipe de
    livraison est configurée) et
    `::test_transfert_sans_bareme_livreur_expose_une_liste_livreurs_vide()` (commission générée
    pour une autre cible, sans barème livreur → liste vide, jamais un bénéficiaire inventé).
- **04/09/2026** — Le sous-menu `Comptabilité > Commissions > Logistique` a été retiré de la
  navigation (`AppSidebar.vue`) : l'écran `Comptabilite\CommissionVenteController` (route
  `commissions/vente`, inchangée) est renommé `Livreurs` dans le menu et devient le point d'entrée
  unique pour consulter les commissions d'un livreur, tous processus confondus (Vente/Distribution
  client/Transfert logistique, cf. section Reporting ci-dessous) — libellés alignés en conséquence
  (`CommissionVente/Index.vue` : "Commissions des livreurs" ; `CommissionVente/Livreur/Show.vue` :
  breadcrumb "Commissions des livreurs"). `Comptabilite\CommissionLogistiqueController` (écran
  historique décrit ci-dessus) n'est pas modifié — routes, contrôleur et pages Vue inchangés,
  tables toujours vérifiées à 0 ligne (`commission_logistique_parts`/`commissions_logistiques`/
  `versements_commission_logistique`) — mais n'a plus aucun point d'entrée dans l'UI, exactement la
  situation qui a justifié le retrait complet de `CommissionVehiculeController` le même jour
  ci-dessus. Il reste donc, comme documenté plus haut, candidat à la PR de retrait séparée
  (suppression contrôleur/routes/pages/tests) une fois cette absence de solde reconfirmée avant
  suppression effective.

## Partage Livreur par processus (équipe véhicule)

- Clé métier : `organisation + equipe + categorie + processus + livreur` — un même équipage peut
  avoir des montants fixes différents en Vente et en Transfert logistique sur la même catégorie
  (2 processus configurables, cf. COMM-005). `distribution_client` n'a pas de partage propre tant
  qu'il n'a pas sa propre configuration : le partage effectivement consulté pour une distribution
  est alors celui de `logistique_transfert`, par le même mécanisme de repli que le barème
  (`CommissionProcessusDefaults::processusResolutionBareme()`).
- `EquipeLivraisonController::store()`/`update()` exigent `processus_code` (whitelist des 2 codes
  configurables, `Settings\CommissionRegleController::processusCodesDisponibles()`) — aucun repli
  implicite à la saisie. `CommissionProcessus::CODE_DISTRIBUTION_CLIENT` est explicitement refusé
  comme valeur de `processus_code` ici (pas d'onglet de configuration dédié), quel que soit l'usage
  du véhicule (cf. tests dédiés dans `VehiculeProcessusApplicablesParUsageTest`) — cela n'empêche
  pas `distribution_client` de rester la valeur réellement écrite sur chaque `CommissionEnveloppe`
  de distribution générée (identité, cf. COMM-005).
- **« Processus disponible » ≠ « processus obligatoire »** (révisé le 31/08/2026, incident : la
  fiche d'un Tricycle Vente-only affichait Distribution client comme « à faire », alors qu'aucune
  donnée métier ne l'autorise à exercer ce processus). Les processus pertinents pour un véhicule
  dépendent de ses usages : `vente` ↔ `livraison_vente = true` ; `logistique_transfert` ↔
  `livraison_logistique = true` (source unique : `CommissionProcessusDefaults::codesApplicablesPourVehicule()`)
  — un véhicule expose donc au maximum 2 onglets, jamais 3.
  - `EquipeLivraisonController::rules()` restreint le whitelist `processus_code` à ce sous-ensemble
    — une requête forgée avec un `processus_code` non applicable à l'usage du véhicule est rejetée
    (422), jamais uniquement filtrée côté UI.
  - `VehiculeController::show()` filtre `processus_options` (tabs) et `statuts_partage_commission`
    au même sous-ensemble : un processus non applicable n'apparaît ni comme onglet, ni comme
    colonne « à faire »/« non requis » dans le tableau des commissions par catégorie — il
    disparaît simplement de l'écran, plutôt qu'un badge trompeur.
  - Un changement d'usage (ex: un véhicule Vente-only devient mixte) ne supprime ni ne clôture
    jamais un partage déjà enregistré pour un processus redevenu/devenu non applicable — la ligne
    reste en base, simplement non affichée/non exigée tant que l'usage correspondant est désactivé.
- `Settings\CommissionRegleController` (Paramètres > Commissions, écran de configuration globale
  de l'organisation) n'est PAS concerné par ce filtrage par USAGE véhicule : ses 2 onglets restent
  toujours tous visibles, un barème pouvant légitimement être préparé pour un processus avant même
  qu'un véhicule compatible existe.
- `Vehicules/Show.vue` / `EquipeStepperModal.vue` (configuration) portent un sélecteur
  `SelectButton` Processus (`?processus=...`), alimenté par `processus_options` — chaque onglet
  affiche/édite exclusivement le partage de ce processus, les autres restant inchangés.
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
  - ne s'applique jamais à une vente sans véhicule, ni à un véhicule non éligible aux commissions
    (`livraison_vente = false`) — s'applique en revanche à toute organisation côté transfert
    depuis le 03/09/2026 (retrait de la bascule par organisation, cf. COMM-007) ;
  - reste un contrôle **préventif**, pas une garantie : le filet de sécurité de la génération
    (ci-dessus) reste seul responsable au moment réel de la génération, la configuration pouvant
    encore changer entre la création et le déclencheur (chargement/encaissement/réception).

## Membres d'équipe — téléphone chauffeur/convoyeur (révisé le 01/09/2026)

- **Chauffeur** : téléphone obligatoire et unique dans l'organisation (identifie le responsable
  légal du trajet). **Convoyeur** : téléphone facultatif ; s'il est renseigné, il reste unique dans
  l'organisation au même titre que celui d'un chauffeur — `personnes.telephone`/`telephone_normalise`
  sont nullable en base précisément pour ce cas (cf. migration
  `0001_01_01_000003_z_create_personnes_table.php`), et l'index unique
  `personnes_organization_id_telephone_normalise_unique` autorise nativement plusieurs `NULL` pour
  la même organisation (jamais considérés comme des doublons entre eux).
  - Contrôlé par `EquipeLivraisonController::rules()` (règle `ImplicitRule` sur
    `membres.*.telephone` — obligatoire uniquement si `role === 'chauffeur'`, jamais un
    `required` inconditionnel) et répercuté côté formulaire (`EquipeStepperModal.vue`,
    `validateStep1()`).
  - Avant cette révision, le téléphone était obligatoire pour tous les rôles : en pratique, les
    convoyeurs sans téléphone personnel se voyaient attribuer des numéros fictifs ou réutilisés
    pour satisfaire la validation, ce qui provoquait des collisions avec de vrais numéros déjà
    enregistrés (cf. incident ci-dessous).
- **Incident Sentry PHP-LARAVEL-66** (production, 01/09/2026, réapparu le 02/09/2026) :
  `UniqueConstraintViolationException` sur `personnes_organization_id_telephone_normalise_unique`
  lors de la mise à jour d'une équipe. Cause racine, dans
  `EquipeLivraisonController::detecterConflitTelephone()` (logique historiquement inline dans
  `validateMembresExclusivite()`) — le garde-fou n'excluait du contrôle « déjà affecté à une autre
  équipe » que l'ÉQUIPE en cours d'édition (`equipe_id <> $equipeIdCourant`), jamais le LIVREUR édité
  lui-même. Un membre identifié par `livreur_id` pouvait donc se voir attribuer, dans la même
  soumission, le numéro d'un AUTRE membre de la MÊME équipe (ou de tout autre livreur non affecté à
  une équipe active) sans être bloqué en amont — la requête `UPDATE personnes SET
  telephone_normalise = ...` finissait alors en violation de contrainte SQL brute (500), au lieu
  d'un 422 propre.
  - Premier correctif (01/09/2026) limité à la seule table `livreurs` (`Livreur::where(...)`),
    laissant passer un conflit avec un numéro déjà détenu par une Personne d'un AUTRE rôle
    (Propriétaire, Employé, User, Client...) — le téléphone est unique par organisation sur TOUTE la
    table `personnes`, jamais seulement entre livreurs. Réapparu en production le 02/09/2026 pour
    cette raison précise (numéro déjà détenu par un Propriétaire).
  - Correctif définitif : la recherche du conflit porte sur `Personne` (pas `Livreur`), puis
    distingue deux cas — (1) `livreur_id` explicite (membre déjà identifié) dont le téléphone soumis
    appartient à une AUTRE Personne déjà en base → conflit d'identité direct, **peu importe l'équipe
    ET peu importe le rôle de cette autre Personne** (message « Ce numéro appartient à {nom du
    livreur trouvé}. » si cette Personne est elle-même un livreur, sinon « Ce numéro de téléphone
    est déjà utilisé par un autre contact de l'organisation. ») ; (2) `livreur_id` absent (nouveau
    membre, ou membre ré-identifié uniquement par téléphone — `resolveOrCreateLivreur()` réutilise
    alors la Personne existante via `Personne::resoudreOuCreer()`, aucun risque de collision SQL) →
    seule règle applicable : le Livreur trouvé ne doit pas être déjà affecté à une équipe active
    **différente** de celle en cours d'édition (message « Ce numéro appartient à {nom du livreur}
    (déjà affecté au véhicule "{nom du véhicule} ({immatriculation})"). », double affectation
    interdite — l'immatriculation lève l'ambiguïté quand plusieurs véhicules portent un nom proche).
  - **Contrôle live côté formulaire** (révisé le 02/09/2026) : ce conflit n'est plus découvert
    seulement à la soumission finale du stepper (`EquipeStepperModal.vue`), après que l'utilisateur
    a déjà rempli les étapes 2/3 — `GET equipes-livraison/verifier-telephone`
    (`EquipeLivraisonController::verifierTelephone()`, réservée aux permissions
    `equipes-livraison.create`/`.update`) rejoue exactement `detecterConflitTelephone()` (source
    unique, jamais de logique dupliquée entre validation finale et contrôle live) et est appelée au
    blur de chaque champ téléphone (avec un filet de sécurité si le debounce n'a pas eu le temps de
    se déclencher). Le bouton « Suivant » de l'étape 1 reste désactivé tant qu'une vérification est
    en cours ou qu'un conflit est signalé — le problème est donc réglé **en amont**, avant même
    d'atteindre l'étape de répartition.
  - Tests de non-régression : `EquipeLivraisonTest::
    test_update_echoue_proprement_si_telephone_deja_detenu_par_autre_membre_de_la_meme_equipe`,
    `test_update_echoue_proprement_si_telephone_deja_detenu_par_un_proprietaire` (réapparition du
    02/09/2026), `test_verifier_telephone_signale_conflit_avec_livreur_autre_equipe` /
    `test_verifier_telephone_sans_conflit` / `test_verifier_telephone_ignore_le_conflit_avec_soi_meme`
    / `test_verifier_telephone_refuse_sans_permission` (contrôle live), et
    `test_store_echoue_si_chauffeur_sans_telephone` / `test_store_autorise_convoyeur_sans_telephone`
    / `test_store_autorise_plusieurs_convoyeurs_sans_telephone` (règle chauffeur/convoyeur).

## Transfert de véhicule d'un livreur (changement d'équipe) — décision AMOA du 02/09/2026

Un livreur n'appartient qu'à une seule équipe active à la fois (contrainte DB
`equipe_livreurs.livreur_id` unique), elle-même rattachée à un seul véhicule
(`equipes_livraison.vehicule_id` unique). « Changer de véhicule » déplace donc le livreur
d'une équipe à une autre — jusqu'ici, cela obligeait à rouvrir le stepper de l'équipe de
départ (retirer le membre, tout re-soumettre) puis celui de l'équipe d'arrivée (l'ajouter,
tout re-soumettre), sans lien entre les deux opérations.

`EquipeLivraisonController::transferer()` (routes `equipes-livraison/transfert-livreur/{livreur}`)
exécute ce déplacement comme une opération unique :

- **Répartition obligatoirement refaite des deux côtés, jamais reprise automatiquement.**
  Le partage étant par catégorie ET par processus (Vente / Transfert logistique, cf. section
  ci-dessus), le transfert boucle sur **chaque processus ayant un partage actif** pour l'équipe
  concernée — un véhicule avec `livraison_vente` et `livraison_logistique` actifs simultanément
  exige donc de refaire les deux, pas un seul écran générique. Un processus jamais configuré pour
  l'équipe reste "non configuré" (même règle que partout ailleurs) : le transfert ne force jamais
  à configurer ce qui ne l'était pas avant.
- **Équipe de départ vidée par le transfert** (le livreur en était le dernier membre) : dissoute
  automatiquement (soft-delete + désactivation du véhicule, même code que `destroy()`), aucune
  répartition n'est demandée dans ce cas.
- **Équipe d'arrivée inexistante** (véhicule cible sans équipe) : créée à la volée avec le seul
  livreur transféré, sans forcer de partage (même logique que ci-dessus).
- **Une seule transaction DB, commit unique** : rien n'est écrit en base tant que les
  répartitions départ ET arrivée n'ont pas été validées (`CommissionPartageLivraisonValidator`,
  même contrôle strict que `store()`/`update()`). Abandonner le wizard en cours de route ne laisse
  donc aucune trace — le véhicule cible n'est activé qu'au moment où le transfert est
  effectivement validé. Un modèle en deux temps (déplacement immédiat + véhicule désactivé en
  attendant la configuration du partage) a été envisagé puis écarté : il aurait réintroduit
  exactement l'état intermédiaire incohérent que le commit unique évite.
- Réutilise directement `validatePartagesCategorie()`/`syncPartagesCategorie()` (aucune logique
  de partage dupliquée) — les parts du payload de transfert sont identifiées par `livreur_id`
  (tous des livreurs déjà résolus, jamais de nouveau membre créé par ce flux) au lieu de
  `membre_ordre`, converties en interne avant d'appeler ces deux méthodes partagées avec
  `store()`/`update()`.
- Tests : `EquipeLivraisonTransfertLivreurTest` (transfert simple, dissolution de l'équipe de
  départ, création d'équipe à l'arrivée, double processus Vente + Logistique, rollback total si
  une répartition est invalide, permission).

## Reporting Comptabilité — cloisonnement par processus

Les 4 écrans bénéficiaires (`Comptabilite\CommissionVenteController` + Site/Proprietaire/Consultant)
partagent le même risque : leur source `CommissionEnveloppePart` est interrogée par
`beneficiaire_type` seul, qui ne distingue pas Vente/Distribution client/Transfert logistique (les
trois processus peuvent produire des parts pour le même bénéficiaire).

Le sélecteur Processus garde ses 3 options (Vente/Distribution client/Transfert logistique) même
après COMM-005 : `distribution_client` y désigne un processus VIVANT (nouvelles commissions
générées en continu, cf. COMM-005), pas seulement un solde historique — retirer une option
masquerait une activité réelle et courante, restée consolidable dans `commission_summary` et
tracée par bénéficiaire via la colonne « Origine » de `commission_details`.

### Listes (Index) — revu le 02/09/2026 (incident : sélection multiple silencieusement réduite au premier processus)

- **Défaut : "Tous les processus"**, jamais un repli implicite sur `vente` — aucune sélection
  explicite consolide les 3 processus, sur les 4 écrans
  (`CommissionProcessusFilter::normaliserCodes()` renvoie un tableau vide, `appliquer()` n'ajoute
  alors aucune clause `WHERE`).
- **Case à cocher multiple avec union réelle**, jamais un simple choix unique : le champ
  `processus` de `DataFilters` est de type `multi-select` (jamais `select`, qui ne retient que la
  première valeur cochée — régression identifiée le 02/09/2026 : cocher Vente + Transfert
  logistique renvoyait silencieusement `?processus=vente` seul, masquant sans indication le reste
  de la sélection). Plusieurs codes cochés s'unissent côté backend (`whereIn`, jamais une
  intersection) ; cocher les 3 revient exactement au même qu'aucune sélection (les deux envoient
  un tableau vide/omis, cf. `DataFilters.vue::buildParams()`).
- **Colonne "Processus" toujours visible** dans le détail par bénéficiaire (Livreur/Site/
  Propriétaire/Consultant), quel que soit le nombre de processus actuellement filtrés — liste les
  libellés des processus ayant réellement contribué au montant de cette ligne
  (`CommissionProcessusFilter::labelsPresents()`), pour que deux montants identiques restent
  distinguables par leur origine sans devoir rouvrir le filtre (ex : 5 000 GNF de Transfert
  logistique et 5 000 GNF de Distribution client pour le même livreur restent deux informations
  visibles, jamais fondues silencieusement en une seule ligne ambiguë).
- **Exports Excel/PDF** — reprennent le même filtre (tableau de codes) que l'écran Index dont ils
  sont issus.
- **Jamais filtrées par processus** — `PeriodeCalculatorService`,
  `CommissionEnveloppePartAllocationService` et tout le pipeline de validation/paiement de période :
  une période comptable doit couvrir la totalité des commissions d'un bénéficiaire, quel que soit
  leur processus d'origine. Les paiements réels (`PaiementFichePaiement`, historique affiché sur la
  fiche détail) ne sont eux non plus jamais ventilés par processus — l'argent versé à un bénéficiaire
  ne « sait » pas de quel processus il provenait.

### Détail (Show) — indépendance vis-à-vis du filtre Index généralisée le 02/09/2026

**Historique** : jusqu'au 31/08/2026, la fiche détail héritait du même filtre `processus` que
l'Index sur les 4 écrans, propagé via le lien « Voir détail » (`?processus=...`) et préservé à
travers tout changement de période/véhicule/agence sur la page. Défaut observé en usage réel : un
bénéficiaire dont la commission Distribution/Transfert n'apparaissait jamais nulle part sur sa
fiche personnelle, sans sélecteur visible pour la faire apparaître — la fiche semblait alors
« oublier » une partie réelle de sa situation financière.

Le 31/08/2026, `Comptabilite\CommissionVenteController::showLivreur()` a été le premier écran à
casser ce lien : sa fiche affiche par défaut **Tous les processus** confondus
(`filtre_processus === ''`), indépendamment du filtre actif sur l'Index. Le 02/09/2026, en
généralisant l'Index lui-même à une case à cocher multiple (cf. section ci-dessus), les 3 autres
écrans (Site/Proprietaire/Consultant) ont cessé à leur tour de transmettre `?processus=...` dans
leur lien vers la fiche — une sélection MULTIPLE sur l'Index n'a de toute façon pas de sens à
propager vers un sélecteur à choix unique sur la fiche. Les 4 écrans partagent donc désormais la
même garantie structurelle : **la fiche détail garde toujours son propre état de filtre, jamais
hérité ni partagé avec l'Index d'où l'on vient.**

Seule différence restante entre écrans (signalée, non corrigée le 02/09/2026) : le DÉFAUT propre à
chaque fiche diffère toujours —

- `CommissionVenteController::showLivreur()` : défaut **Tous les processus**, sélecteur dédié sur
  la fiche elle-même (`CommissionGlobalFilters.vue`), colonne « Origine » sur `commission_details`
  (détail ci-dessous, inchangé depuis le 31/08/2026).
- `CommissionSiteController::show()` / `CommissionProprietaireController::show()` /
  `CommissionConsultantController::show()` : défaut **Vente** (`CODE_VENTE`), sans sélecteur dédié
  pour changer cette vue depuis la fiche elle-même — un utilisateur souhaitant y voir une
  commission Distribution/Transfert doit encore construire l'URL manuellement
  (`?processus=distribution_client` ou `?processus=logistique_transfert`). Candidat naturel à la
  même généralisation que `showLivreur()` si demandée.

Détail du comportement `showLivreur()` (inchangé depuis le 31/08/2026) :

- Un sélecteur Processus est visible sur la fiche elle-même (`CommissionGlobalFilters.vue`, prop
  optionnelle `processusOptions` — n'affecte aucun autre écran qui ne la fournit pas), avec un
  choix « Tous les processus » en tête (`CommissionProcessusFilter::optionsAvecTous()`).
- Chaque ligne du détail par commande (`commission_details`) porte son origine
  (`processus`/`processus_label`) — affichée par `CommissionDetailTable.vue` (colonne « Origine »,
  visible uniquement si au moins une ligne la fournit) : jamais de montants de processus différents
  mélangés sans indication, y compris en vue consolidée.
- Les montants eux-mêmes (net à payer/reste à payer/`en_attente_periode`/`payable`) restent calculés
  exactement comme avant sur l'ensemble des parts effectivement affichées (toutes si « tous », un
  seul processus si filtré) — aucun changement de la logique financière, seulement de ce qui est
  montré par défaut et de la transparence sur l'origine.

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

## Grossiste — commission consultant indépendante du mode de remise (05/09/2026)

Cf. `docs/grossiste.md` pour la règle métier complète (nature client, tarification catégorie ×
mode). Cette section couvre uniquement l'impact sur le moteur de commission.

- **COMM-008** — Pour un client `ClientType::GROSSISTE`, deux logiques de commission sont
  indépendantes l'une de l'autre :
  - **Commission de transfert logistique** (cibles `CODE_PROPRIETAIRE`/`CODE_EQUIPE_LIVRAISON`) —
    dépend du mode de remise : **Livraison** (véhicule de flotte) → générée selon les règles
    actuelles, comme n'importe quelle vente standard avec véhicule. **Enlèvement** (aucun véhicule,
    le client retire lui-même) → jamais générée, il n'y a ni propriétaire ni équipe à commissionner.
  - **Commission consultant** (cible `CODE_CONSULTANT`, indépendante de toute donnée de l'opération
    par conception, cf. section « Consultant » plus haut) — générée si une règle active existe,
    **que le Grossiste soit en Enlèvement ou en Livraison**. Une commande Grossiste + Enlèvement ne
    doit jamais priver le consultant d'une commission à laquelle il a droit par ailleurs.
- Avant ce correctif, `CommissionEnveloppeGenerator::genererPourCommandeVente()` retournait
  immédiatement dès que `commission_eligible_snapshot` était faux (dérivé de l'absence de véhicule,
  cf. `VehiculeCommandeContextResolver`) — un verrou global qui aurait aussi supprimé la commission
  consultant. Correctif strictement scopé à Grossiste (`$estGrossisteSansVehicule` dans
  `genererPourCommandeVente()`) : pour tout autre type de client (Externe/Revendeur/Distributeur),
  l'absence de véhicule continue de bloquer **toutes** les cibles, comportement historique
  inchangé — voir `tests/Feature/CommandeVenteGrossisteCommissionTest.php`, notamment le test de
  non-régression sur un Externe en vente directe.
- `genererDepuisContexte()` n'exige plus systématiquement un véhicule : les cibles
  `CODE_PROPRIETAIRE`/`CODE_EQUIPE_LIVRAISON` ne sont ajoutées à la liste des cibles que si un
  véhicule est présent ; `CODE_SITE` (si site) et `CODE_CONSULTANT` (toujours) restent inconditionnelles,
  cohérent avec leur conception déjà indépendante du véhicule.
- **Déclenchement** — un Enlèvement passe par `CommandeVenteService::creerFactureDirecte()` (pas
  d'étape de chargement). `CommissionTriggerService::onVenteDirecteFacturee()` (nouveau, appelé
  depuis `creerFactureDirecte()`) déclenche la génération de commission pour ce chemin,
  inconditionnellement (comme `onReceptionDistributionValidee()` pour la distribution client) —
  sans effet pour tout client non-Grossiste (le garde-fou scopé ci-dessus s'applique toujours).
- `CommandeVente::commissionsPretesPourCloture()` ne conclut plus "rien n'est dû" sur la seule
  valeur de `commission_eligible_snapshot` : si des enveloppes existent réellement (cas Grossiste +
  Enlèvement avec consultant généré), la clôture automatique attend leur paiement comme pour toute
  commande éligible.
- **Chantier 2 (non fait, décision explicite du 05/09/2026)** — généraliser l'éligibilité par
  bénéficiaire (Propriétaire/Livreur/Consultant/Site indépendants) à tous les types de client serait
  une refonte architecturale plus large, volontairement hors périmètre ici pour ne pas mélanger deux
  changements dans une même livraison. Le correctif ci-dessus reste une exception ciblée, pas une
  généralisation.
