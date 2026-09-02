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
- **COMM-005** (révisée le 01/09/2026 — la version précédente affirmait l'inverse : un barème
  `distribution_client` totalement indépendant de la logistique) — La commission d'une commande
  est routée vers le processus `vente` ou `logistique_transfert` selon `nature_operation` — une
  distribution client utilise **le même barème que le transfert logistique interne**, jamais un
  processus dédié. Décision produit explicite : le métier confirme que les commissions
  logistiques s'appliquent uniformément aux opérations de distribution et de transfert, il n'y a
  plus de configuration séparée à maintenir en parallèle.
  - `CommissionProcessus::CODE_DISTRIBUTION_CLIENT` reste défini dans le code (legacy) et en base
    pour toute organisation l'ayant déjà utilisé, mais n'est plus jamais résolu par aucun
    appelant — ni pour la génération (`CommissionEnveloppeGenerator::genererPourCommandeVente()`),
    ni pour la configuration (`Settings\CommissionRegleController::processusCodesDisponibles()`
    ne le liste plus), ni pour la fiche véhicule (`VehiculeController::show()`). Les
    `CommissionEnveloppe`/`CommissionRegle`/`EquipeLivraisonPartageCategorie` déjà générées sous
    ce code avant le 01/09/2026 restent en base, inchangées et lisibles — **aucune migration**,
    seul le routage des NOUVELLES opérations a changé.
  - Seul le reporting historique (`App\Support\Commission\CommissionProcessusFilter`, utilisé par
    les écrans Comptabilité) continue de distinguer `distribution_client` des deux autres codes,
    pour que le detail par processus d'une période antérieure au 01/09/2026 reste réconciliable
    avec son total déjà généré — ne jamais aligner ce filtre sur `processusCodesDisponibles()`.
  - Conséquence opérationnelle à valider par le métier pour toute organisation ayant déjà
    configuré des montants **différents** entre "Distribution client" et "Transferts
    logistiques" avant le 01/09/2026 : le montant réellement appliqué à une NOUVELLE distribution
    change (il devient celui du barème logistique) — ce n'est pas un bug, c'est l'objet même de
    cette révision, mais l'organisation concernée doit vérifier que son barème logistique reflète
    bien le montant qu'elle souhaite désormais pour ses distributions.
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
CommissionProcessus (vente | logistique_transfert)  ← distribution_client route ici depuis le
        │                                              01/09/2026 (legacy, plus jamais résolu)
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
| Paramètres > Commissions (`Settings\CommissionRegleController`) | Un seul écran, 2 onglets depuis le 01/09/2026 (`?processus=vente\|logistique_transfert` — `distribution_client` retiré, cf. COMM-005), même grille catégorie × cible × type de véhicule pour les deux. |
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
  avoir des montants fixes différents en Vente et en Transfert logistique sur la même catégorie
  (2 processus configurables depuis le 01/09/2026, cf. COMM-005 — plus de « Distribution client »
  séparée : une distribution utilise le partage Transfert logistique).
- `EquipeLivraisonController::store()`/`update()` exigent `processus_code` (whitelist des 2 codes,
  `Settings\CommissionRegleController::processusCodesDisponibles()`) — aucun repli implicite.
  `CommissionProcessus::CODE_DISTRIBUTION_CLIENT` est explicitement refusé, quel que soit l'usage
  du véhicule (cf. tests dédiés dans `VehiculeProcessusApplicablesParUsageTest`).
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
  - ne s'applique jamais à une vente sans véhicule, à un véhicule non éligible aux commissions
    (`livraison_vente = false`), ni — côté transfert — à une organisation non migrée vers le
    moteur générique (`CommissionTriggerService::estMigreVersMoteurGenerique()`) ;
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
- **Incident Sentry PHP-LARAVEL-66** (production, 01/09/2026) : `UniqueConstraintViolationException`
  sur `personnes_organization_id_telephone_normalise_unique` lors de la mise à jour d'une équipe.
  Cause racine, dans `EquipeLivraisonController::validateMembresExclusivite()` — le garde-fou
  n'excluait du contrôle « déjà affecté à une autre équipe » que l'ÉQUIPE en cours d'édition
  (`equipe_id <> $equipeIdCourant`), jamais le LIVREUR édité lui-même. Un membre identifié par
  `livreur_id` pouvait donc se voir attribuer, dans la même soumission, le numéro d'un AUTRE membre
  de la MÊME équipe (ou de tout autre livreur non affecté à une équipe active) sans être bloqué en
  amont — la requête `UPDATE personnes SET telephone_normalise = ...` finissait alors en violation
  de contrainte SQL brute (500), au lieu d'un 422 propre.
  - Correctif : le contrôle distingue désormais deux cas — (1) `livreur_id` explicite dont le
    téléphone soumis appartient à un AUTRE livreur trouvé en base → conflit d'identité direct,
    **peu importe l'équipe** (message « Ce numéro de téléphone est déjà utilisé par un autre
    livreur. ») ; (2) `livreur_id` absent (nouveau membre, ou membre ré-identifié uniquement par
    téléphone — `resolveOrCreateLivreur()` réutilise alors la Personne/le Livreur existant, aucun
    risque de collision) → seule règle applicable : le livreur trouvé ne doit pas être déjà affecté
    à une équipe active **différente** de celle en cours d'édition (message « Ce livreur est déjà
    affecté à une autre équipe. », double affectation interdite).
  - Tests de non-régression : `EquipeLivraisonTest::
    test_update_echoue_proprement_si_telephone_deja_detenu_par_autre_membre_de_la_meme_equipe`
    (reproduit l'incident) et `test_store_echoue_si_chauffeur_sans_telephone` /
    `test_store_autorise_convoyeur_sans_telephone` / `test_store_autorise_plusieurs_convoyeurs_sans_telephone`
    (règle chauffeur/convoyeur).

## Reporting Comptabilité — cloisonnement par processus

Les 4 écrans bénéficiaires (`Comptabilite\CommissionVenteController` + Site/Proprietaire/Consultant)
partagent le même risque : leur source `CommissionEnveloppePart` est interrogée par
`beneficiaire_type` seul, qui ne distingue pas Vente/Distribution client/Transfert logistique (les
trois processus peuvent produire des parts pour le même bénéficiaire).

Le sélecteur Processus décrit ci-dessous garde volontairement ses 3 options (Vente/Distribution
client/Transfert logistique) même après le 01/09/2026 (cf. COMM-005) : "Distribution client" y
désigne exclusivement des `CommissionEnveloppe` déjà générées avant cette date — aucune nouvelle
n'y sera jamais ajoutée, mais les retirer du sélecteur casserait la réconciliation du total déjà
généré (`Comptabilite\CommissionVenteController::breakdownParProcessus()`).

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
