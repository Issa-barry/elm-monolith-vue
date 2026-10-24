# Parrainage véhicule

Un véhicule peut avoir **un parrain** : une personne (référentiel `Personne`, le même que celui
utilisé par `Proprietaire`/`Fournisseur`/`Prestataire`/`Livreur`/`Employe`) rattachée au véhicule
pour mémoriser qui l'a fait entrer dans le parc.

**Phase 1 (chantier du 07/09/2026)** — volontairement limitée à :
- la recherche/réutilisation d'une `Personne` existante par téléphone (jamais de doublon) ;
- la création d'une nouvelle `Personne` si aucune ne correspond ;
- l'association d'un parrain à un véhicule, sa modification en place, son remplacement.

**Hors périmètre de la phase 1** : commission de parrainage, calcul, paiement, historique des
parrains successifs d'un véhicule.

**Phase 2 (chantier du 21/10/2026, [ADR 0035](adr/0035-part-du-parrain-dans-lenveloppe-livreur.md))**
— le parrain reçoit une part de l'enveloppe Livreur, sans jamais augmenter le total des
commissions : voir la section « Part du parrain » plus bas.

## Règles métier (IDs)

- **PARRAIN-001** — Un parrain est une `Personne` du référentiel existant, jamais une identité
  dupliquée. Avant toute création, le téléphone saisi est recherché parmi les `Personne` de
  l'organisation (`telephone_normalise`) ; s'il correspond à une personne existante, celle-ci est
  réutilisée telle quelle, jamais fusionnée ni recréée (cf. `Personne::resoudreOuCreer()`).
- **PARRAIN-002** — Le rôle "parrain" est porté par un modèle dédié léger, `Parrain`
  (`personne_id` → `personnes`), sur le même principe que `Proprietaire` : l'identité civile
  (nom, téléphone, ville, pays, adresse) reste entièrement sur `Personne`, `Parrain` ne stocke
  que le rattachement organisationnel et `is_active`.
- **PARRAIN-003** — Une même `Personne` peut parrainer plusieurs véhicules. Il n'existe aucune
  contrainte d'unicité en base sur `parrains.personne_id` ; la déduplication du rôle est faite
  applicativement (`Parrain::firstOrCreate(['organization_id' => ..., 'personne_id' => ...])`
  dans `StoreParrainController`) — une même Personne n'a donc jamais deux lignes `Parrain`,
  seulement plusieurs véhicules qui pointent vers la même ligne.
- **PARRAIN-004** — `vehicules.parrain_id` est un pointeur simple vers le parrain **actuel**, sans
  historique : changer de parrain remplace la valeur, sans conserver trace du précédent (même
  comportement que `vehicules.proprietaire_id`, cf. `StoreParrainController`).
- **PARRAIN-005** — Modifier l'identité d'un parrain déjà rattaché (nom, téléphone, ville,
  adresse) l'édite **en place** sur sa `Personne` — jamais de re-résolution par téléphone, pour
  ne jamais rattacher silencieusement le véhicule à une autre personne existante (même principe
  que `ResolutionIdentiteTiersTrait`/`ProprietaireController::update()`). Si le nouveau téléphone
  saisi appartient déjà à une autre `Personne` de l'organisation, la modification est refusée
  (`UpdateParrainController`, via `Personne::assertTelephoneDisponible()`) plutôt que de violer silencieusement la
  contrainte unique `personnes.(organization_id, telephone_normalise)`.
- **PARRAIN-006** — Le rattachement d'un parrain est protégé par les mêmes permissions que le
  véhicule (`vehicules.update` + isolation par organisation via `VehiculePolicy`) : pas de
  permission dédiée créée pour cette fonctionnalité. Exception depuis la phase 2 : changer ou
  retirer un parrain qui reçoit une part exige aussi `equipes-livraison.update` (PARRAIN-016).

## Modèles et migrations

| Élément | Rôle |
|---|---|
| `Parrain` (`app/Models/Parrain.php`) | Rôle léger, `personne_id` + `organization_id` + `is_active`. Accesseurs proxy vers `Personne` (nom, téléphone, ville, pays, adresse...), comme `Proprietaire`. |
| `parrains` (migration [`create_parrains_table`](../database/migrations/2026_09_07_160000_create_parrains_table.php)) | `personne_id` en FK `restrictOnDelete` vers `personnes` — une `Personne` encore parrain ne peut pas être supprimée. |
| `vehicules.parrain_id` (migration [`add_parrain_id_to_vehicules_table`](../database/migrations/2026_09_07_160001_add_parrain_id_to_vehicules_table.php)) | FK nullable `restrictOnDelete` vers `parrains`, même modèle que `proprietaire_id`. |
| `Vehicule::parrain()` | `belongsTo(Parrain::class)`. |
| `Personne::parrain()` | `hasOne(Parrain::class)` — une Personne n'a qu'un seul rôle Parrain (wrapper), réutilisé par plusieurs véhicules. |

## Backend — `app/Http/Controllers/Vehicules/Parrain/`

Trois contrôleurs mono-action, tous scopés à un véhicule (`vehicules/{vehicule}/parrain...`) et
gatés par `$this->authorize('update', $vehicule)` :

- `RechercherTelephoneParrainController` (GET, JSON) — lecture seule, ne crée jamais rien.
  Normalise le téléphone soumis (même logique que partout ailleurs : `PhoneHandlerTrait` +
  `Personne::normaliserTelephone()`) et cherche une `Personne` de l'organisation par
  `telephone_normalise`.
- `StoreParrainController` (POST) — soit `personne_id` (personne trouvée, réutilisée telle
  quelle), soit les champs d'identité complets (`nom_complet`, `telephone`, `code_pays`, `ville`,
  `adresse`) pour créer/réutiliser via `Personne::resoudreOuCreer()`. Résout ensuite le `Parrain`
  (créé ou réutilisé), assigne `vehicule.parrain_id` et répercute le changement sur le partage
  des commissions (PARRAIN-012), le tout dans une seule transaction.
- `UpdateParrainController` (PUT) — édite l'identité du parrain déjà rattaché, en place.

## Frontend

- Nouvel onglet **Parrain** dans `resources/js/pages/Vehicules/Show.vue`, aux côtés de
  Informations / Équipe / Dépenses — affiche "Aucun parrain associé" + bouton **Ajouter un
  parrain**, ou l'identité du parrain + boutons **Modifier** / **Changer de parrain**.
- `resources/js/pages/Vehicules/partials/ParrainDialog.vue` — Dialog PrimeVue avec un parcours en
  étapes : téléphone → résultat (personne trouvée / non trouvée) → création (champs pré-remplis,
  téléphone non modifiable à ce stade — il faut revenir à l'étape recherche pour le changer) ;
  ou, en mode "Modifier", directement le formulaire d'édition en place. Calqué sur
  `CreateFournisseurModal.vue` (Dialog + masque téléphone + sélecteur pays) pour la cohérence
  visuelle avec le reste de l'application.

## Part du parrain dans l'enveloppe Livreur (phase 2, 21/10/2026)

Décisions métier du 20/10/2026, cf. [ADR 0035](adr/0035-part-du-parrain-dans-lenveloppe-livreur.md)
et [commissions.md](commissions.md).

- **PARRAIN-007 — Une part de l'enveloppe Livreur, jamais une commission en plus.** Le parrain est
  une ligne du partage d'équipe (`equipe_livraison_partages_categorie.parrain_id`, exclusif de
  `livreur_id`) : montant fixe en GNF par pack, par catégorie et par processus, comme un livreur.
  La somme livreurs + parrain doit égaler exactement le barème Livreur (même juge,
  `CommissionPartageLivraisonValidator`) : l'enveloppe reste quantité × barème. Exemple
  (fictif) : barème 1 000 GNF/pack, chauffeur 500, convoyeur 300, parrain 200.
- **PARRAIN-008 — Opérations rémunérées.** Toute commission livreur du véhicule, **sauf le transfert
  logistique entre sites** : vente classique (`vente`), vente Grossiste livrée
  (`transfert_grossiste`), distribution client (`distribution_client`) —
  `CommissionProcessusDefaults::PROCESSUS_REMUNERANT_LE_PARRAIN`. Une ligne parrain sur le partage
  Logistique est refusée.
- **PARRAIN-009 — Distribution : partage propre, barème Logistique.** La distribution garde le
  barème Logistique (COMM-005), mais un véhicule parrainé à usage Logistique a son propre partage
  Distribution (avec le parrain), le partage Logistique restant sans parrain. La génération lit le
  partage Distribution de l'équipe s'il existe à la date de l'opération, sinon le partage Logistique
  (`CommissionPartageLivraisonCategorieChecker::partagesActifsPourGeneration()`). Les contrôles de
  commande l'exigent dès que le véhicule a un parrain (`processusBaremeEtPartage()`).
- **PARRAIN-010 — Ligne obligatoire, 0 GNF accepté.** Dès que le véhicule a un parrain, sa ligne est
  exigée dans chaque partage qui le rémunère (enregistrement de l'équipe, commande, chargement, liste
  des véhicules) ; une ligne désignant un autre parrain que le parrain actuel rend le partage non
  conforme (`verifierParrainAJour()`).
- **PARRAIN-011 — Garde-fou serveur.** `PartageLivraisonVersionService::versionner()` ferme la
  version active en bloc : toute nouvelle version d'un véhicule parrainé, sur un processus qui le
  rémunère, doit porter explicitement la part du parrain — jamais complétée ni recalculée par le
  serveur (refus `ValidationException`) ; une part de parrain est refusée partout ailleurs.
- **PARRAIN-012 — Ajout, remplacement, retrait** (`PartageParrainService`, dans la transaction de
  `StoreParrainController`, effet le jour même ; la part du parrain peut ensuite prendre effet à une
  date choisie, passée ou future, cf. PARRAIN-023) : un nouveau parrain reçoit une
  ligne à 0 GNF dans chaque partage existant, et le partage Distribution est créé (copie du partage
  Logistique + parrain à 0) — aucun montant ne change. Ces lignes à 0 GNF, créées par le système, ne
  valent **pas** décision : elles restent « À répartir » (PARRAIN-022, qui remplace ici l'ancienne
  règle « aucune opération n'est bloquée »). Une part nulle passe au nouveau parrain, sans sa
  confirmation (la décision portait sur l'ancien parrain) ; au retrait, elle disparaît et la distribution reprend le partage
  Logistique. **Une part positive n'est jamais transférée ni retirée automatiquement** : le
  changement (remplacement par `StoreParrainController`, retrait par `DestroyParrainController`)
  doit joindre la nouvelle répartition complète de chaque partage où il reçoit une part
  (`repartitions`, cf. `PartageParrainService::groupesARepartir()` et
  `GET vehicules/{vehicule}/parrain/repartitions`), validée puis enregistrée avec le changement
  dans une seule transaction — refusé sinon, rien n'est enregistré. Au retrait, le partage
  Distribution (barème Logistique) est clos, sans répartition à refaire.
- **PARRAIN-016 — Droits.** Ajouter un parrain, ou changer un parrain sans part : `vehicules.update`.
  Joindre une répartition (changer ou retirer un parrain qui a une part) : en plus
  `equipes-livraison.update`, vérifié par le serveur (`RepartitionsParrain`) ; les boutons
  « Changer de parrain » et « Retirer le parrain » ne s'affichent que si l'utilisateur peut aller au
  bout.
- **PARRAIN-013 — Changement de barème.** Une équipe parrainée (même part à 0) n'est jamais alignée
  automatiquement : elle passe par la grille de reconfiguration, le parrain y figurant comme membre
  requis ; un brouillon Logistique y ajoute son partage Distribution
  (`ReconfigurationPartagesService`). Équipes sans parrain : comportement inchangé (COMM-019).
- **PARRAIN-014 — Part validée d'office**, comme celles du propriétaire, du site et du consultant
  (`CommissionEnveloppeGenerator::TYPES_VALIDATION_AUTOMATIQUE`) : un parrain n'est jamais absent
  ni remplacé. Type de part `CommissionEnveloppePart::TYPE_PARRAIN`.
- **PARRAIN-015 — Cumul de rôles autorisé** : un parrain qui est aussi livreur ou propriétaire du
  même véhicule reçoit des parts distinctes.

**Historique** : `vehicules.parrain_id` reste un pointeur vers le parrain actuel ; l'historique des
parts passe par le versionnement du partage (chaque ligne désigne son parrain et ses dates), ce qui
garantit qu'une régénération à une date passée paie le parrain en place à cette date.

**Reprise** : migration `2026_10_21_100100_backfill_parrain_partages_commission` (rejouable) — chaque
véhicule déjà parrainé reçoit ses lignes à 0 GNF et, s'il a l'usage Logistique, son partage
Distribution.

**Écrans (lot 2, 21/10/2026)** :
- `CommissionMontantFixeEditor.vue` (éditeur unique, désormais utilisé aussi par la modale Équipe) :
  section « Parrain du véhicule » distincte de l'équipe, totaux mis à jour pendant la frappe, trois
  états avec libellé et écart (« Répartition équilibrée », « Reste à répartir », « Dépassement du
  barème »), bouton « Ajuster l'équipe au prorata » (barème − part du parrain, reliquat au
  chauffeur, rien n'est enregistré).
- `EquipeStepperModal.vue` : une carte par catégorie (barème, part propriétaire pour information,
  éditeur), catégories à équilibrer nommées sous le bouton « Suivant », parrain dans le
  récapitulatif ; plus d'attribution automatique du barème au livreur seul quand le véhicule a un
  parrain. Onglet Distribution pour un véhicule parrainé à usage Logistique.
- `TransfertVehiculeDialog.vue` : ligne du parrain dans les répartitions de départ et d'arrivée
  (à 0 au départ, comme les livreurs).
- `Reconfiguration.vue` : le parrain est un membre de la grille (rôle « Parrain »), champ « Parrain »
  dans « Appliquer par rôle », partages Distribution signalés dans un brouillon Logistique.
- Onglet Parrain (`Vehicules/Show.vue`) : part par processus et catégorie, « Définir / Modifier la
  part du parrain » (grille unique, PARRAIN-021 — remplace l'ancien « Modifier la répartition » qui
  ouvrait la modale Équipe d'un seul processus), « Changer de parrain », « Retirer le parrain » ;
  `ParrainDialog.vue` enchaîne
  identité puis « Nouvelle répartition » quand le parrain a une part, ou une simple confirmation de
  retrait sinon.

**Paiement (lot 3, 23/10/2026)** :
- **PARRAIN-017 — Fiche « Parrain » à la quinzaine.** Type de période `parrain` (référence
  `PAY-AAAAMM-P1-PAR`), P1 du 1er au 15, P2 du 16 à la fin du mois. Une fiche par parrain et par
  période (`PeriodeCalculatorService::calculerParrains()`), une ligne par vente, **sans déduction**
  (aucune dépense n'est imputable à un parrain). Agence de paiement = site du véhicule, qui
  suit ses réaffectations comme la fiche du livreur (ADR 0020, `FicheSiteResponsableService`).
- **PARRAIN-018 — Validation automatique.** Sa part étant validée d'office, la période « Parrain »
  se valide d'elle-même (`PeriodeValidationService::TYPES_AUTOMATIQUES`) ; paiement par le
  mécanisme commun des fiches (décaissement depuis un support, ADR 0009 ; fiche figée et fiche
  complémentaire, ADR 0010).
- **PARRAIN-019 — Comptabilité.** À la validation : débit **622500 « Commissions parrains »**,
  crédit **467210 « Parrains — commissions à payer »** (467220 pour d'éventuelles avances) ; au
  paiement : débit 467210, crédit trésorerie (`fiche_parrain_validee`, `paiement_parrain`). La
  charge du parrain sort de celle des livreurs : 622200 + 622500 = l'enveloppe, jamais plus.
  Comptes créés par la migration `2026_10_23_100000_bootstrap_comptes_commission_parrain`
  (idempotente ; un numéro déjà utilisé pour un autre usage est signalé dans le journal).
- **PARRAIN-020 — Prévision de trésorerie.** Colonnes « Parrains P1 » / « Parrains P2 » du
  financement des agences (`ParrainObligationContributor`), restant dû des fiches, comme les
  livreurs.
- Écrans : Comptabilité → Commissions → **Parrains** (`fiches/parrains`, liste des fiches et
  paiement), périodes « Parrains » dans la page Périodes.

**Part du parrain à définir et garde-fou des ventes (lots 5 et 6, 23/10/2026)** :
- **PARRAIN-022 — Une part à 0 GNF créée par le système n'est pas une décision** (remplace la règle
  « jamais bloquant » de la première version de PARRAIN-021 et « aucune opération n'est bloquée »
  de PARRAIN-012).
  - Chaque ligne du parrain porte `part_parrain_confirmee_at` : une part **positive** vaut toujours
    décision ; une part **nulle** n'est décidée que si une personne l'a confirmée explicitement
    (modale Équipe ou grille de la part, case « aucune part pour le moment »). Les lignes créées par
    le système (ajout ou remplacement de parrain, copie Distribution, reprise au déploiement) restent
    vides. La migration `2026_10_23_200000_add_part_parrain_confirmee_at_to_partages_commission`
    n'attribue aucune décision rétroactivement.
  - Une nouvelle version reprend la confirmation d'une part nulle si la version remplacée portait
    déjà une part nulle confirmée pour **le même parrain** (modifier les livreurs ne redemande pas une
    décision prise) ; un nouveau parrain repart « à répartir ».
  - État **« À répartir »** du partage (`PartageConformiteVehiculesService::A_REPARTIR`, onglet
    Équipe, colonne et filtre « à faire » de la liste) : partage complet mais part à 0 non confirmée.
  - **Refus des ventes, partage par partage** (processus × catégorie), à partir de la date
    d'activation de l'organisation (`Parametre::CLE_REPARTITION_PARRAIN_OBLIGATOIRE_DU`, Paramètres →
    Ventes ; vide = pas encore activé, mise en conformité progressive) : le juge commun
    `CommissionPartageLivraisonCategorieChecker::nonConformites()` renvoie le motif
    `parrain_a_repartir` — création (web et API), modification, précommande et changement de mode de
    remise, aperçu du formulaire, chargement (commandes créées avant l'activation comprises). Refus
    avant toute écriture : ni commande, ni ligne, ni mouvement de stock, ni écriture comptable.
  - Jamais concernés : transfert logistique entre sites (le parrain n'y est pas rémunéré), véhicules
    non parrainés, encaissements.
- **PARRAIN-023 — Date de début des droits** (décisions du 24/10/2026, remplace « jamais
  rétroactif » de PARRAIN-012). La grille de la part du parrain porte une « Date de début des
  droits » (aujourd'hui par défaut) :
  - **future** : la nouvelle répartition prend effet à cette date, rien ne change avant ;
  - **passée** : la répartition est antidatée (les versions plus récentes du partage sont bornées
    à leur début, jamais supprimées) et la commission Livreur de chaque commande du véhicule gagnée
    depuis cette date est **recalculée automatiquement** (`RecalculRetroactifParrainService`,
    `CommissionEnveloppeGenerator::regenererEnveloppeEquipe()`) : part du parrain prise sur
    l'enveloppe, parts des livreurs réduites d'autant, total inchangé ; propriétaire, site,
    consultant et transferts entre sites jamais touchés ;
  - **option B** : seules les commissions **non payées** sont recalculées — validées par une
    personne et **ajustées à la main comprises** (l'ajustement est remplacé) ; une période validée
    est rouverte (écritures contrepassées, fiches recalculées, revalidation) par le mécanisme commun
    (`PeriodeValidationService::rouvrirSiDesynchronisee()`) ;
  - exclues et listées dans le bilan : commission payée ou partiellement versée, part sur une fiche
    déjà payée (figée), date dans une période Livreurs ou Parrains clôturée ;
  - **équipe actuelle** : la répartition actuelle s'applique à toute la période rétroactive, même aux
    commandes livrées par une équipe différente (un livreur parti perd sa part non payée sur ces
    commandes, un livreur arrivé en reçoit une) ;
  - limite : toute période non clôturée — au plus tôt le lendemain de la dernière période Livreurs
    ou Parrains clôturée ; idempotent (l'enveloppe est remplacée, jamais dupliquée) ;
  - le bilan (commandes recalculées, déjà à jour, exclues et motif) s'affiche à l'enregistrement ;
    l'onglet Parrain indique « depuis le … » pour chaque part ;
  - **sécurité financière** : tout le recalcul (répartition antidatée, commissions remplacées,
    réouverture des périodes) tient dans une seule transaction ; la réouverture est en mode strict
    (`rouvrirSiDesynchronisee(strict: true)`, `traiterPeriodesPourDates(strict: true)`) — si une
    période ne peut pas être rouverte (contrepassation impossible) ou reste validée sur des fiches
    périmées, **tout est annulé** avec un message explicite : jamais une fiche validée sur d'anciens
    montants face à une commission recalculée ;
  - **idempotence** : une commande dont la commission recalculée serait identique (montants
    effectifs, ajustements compris) est restaurée telle quelle (point de sauvegarde) — ni nouvelle
    enveloppe, ni réouverture, ni écriture ;
  - **traçabilité** : chaque commande recalculée reçoit une entrée du journal d'audit
    (`auto_recalculated`) : parts avant / après et décisions humaines remplacées (montant ajusté,
    historique des ajustements avec motif, commentaire, auteur et date, validation) ;
  - **concurrence** : la commande est verrouillée pendant sa régénération, comme pour la génération
    normale ;
  - **répartition planifiée** (date future) : jamais écrasée en silence — la modale Équipe, le
    transfert d'un livreur, la reconfiguration après changement de barème et le changement de
    parrain sont refusés tant qu'elle existe (`versionner()`, message renvoyant à l'onglet Parrain) ;
    seule la grille de la part du parrain la remplace, après confirmation explicite (case
    « Remplacer la répartition planifiée du … »), sans laisser de période sans répartition.
- **PARRAIN-021 — « À définir » ou « Définie » au niveau du véhicule**, déduit des partages : à
  définir dès qu'un partage où il est rémunéré est « à répartir » (`PartParrainService::statut()` /
  `statuts()`, une requête pour la liste).
- **Parcours** :
  - après l'association d'un parrain, `ParrainDialog.vue` annonce sa part à 0 GNF et propose
    « Définir sa part maintenant » ou « Plus tard » ;
  - onglet Parrain : bandeau « Part du parrain à définir » et bouton « Définir / Modifier la part
    du parrain », qui ouvre `PartParrainDialog.vue` — une seule grille pour tous les partages où il
    est rémunéré (Vente, Transfert grossiste, Distribution × catégorie), même éditeur que la modale
    Équipe ; chaque partage laissé à 0 GNF exige la case « Le parrain ne reçoit aucune part pour
    le moment sur … » ;
  - modale Équipe : même case par catégorie quand la part du parrain est à 0 GNF ; sans elle, le
    partage reste « À répartir » ;
  - **un seul livreur et un parrain** (`CommissionMontantFixeEditor.vue`, toutes les saisies) : la
    répartition est déterminée — saisir la part du parrain calcule celle du livreur (barème − part),
    et inversement ; le champ calculé est signalé, toute part est bornée entre 0 et le barème
    (dépassement ramené au barème avec un message). Avec plusieurs livreurs, aucune part n'est
    recalculée d'office. Ce calcul ne vaut jamais confirmation d'une part nulle (PARRAIN-022) ;
  - onglet Équipe : ligne « Parrain du véhicule » (hors équipe, sans action de livreur) sous les
    livreurs, et « Total réparti » face au barème par catégorie ; ordre des processus identique
    partout (Ventes, Transferts logistiques, Transferts grossistes, Distribution client), les
    transferts entre sites signalés « jamais rémunéré » dans l'onglet Parrain ;
  - onglet Équipe : rappel « part à définir » avec lien vers l'onglet Parrain, état « À répartir »
    dans le tableau des commissions, et date à partir de laquelle les ventes sont refusées ;
  - liste des véhicules : ligne « Parrain » (À définir / Définie) dans la colonne Partages et
    filtre « Part du parrain » (`parrain_part`), pour retrouver les véhicules repris.
- **Enregistrement** (`PUT vehicules/{vehicule}/parrain/part`, `DefinirPartParrainController`) :
  `vehicules.update` + `equipes-livraison.update` (sinon actions masquées et message « un responsable
  des équipes de livraison doit la définir ») ; chaque partage du parrain doit être fourni et
  conforme (`PartageParrainService::validerGroupe()`, mêmes contrôles que le changement de parrain :
  somme exacte du barème, livreurs actifs, part du parrain présente) ; tout est enregistré dans une
  seule transaction, refusé en bloc sinon ; un partage inchangé n'est pas reversionné ; un parrain
  changé entre-temps est refusé.

**Vérification (lot 4, 23/10/2026)** : parcours complet couvert par
`tests/e2e/commissions/parrain-commission.spec.ts` (de l'ajout du parrain au paiement partiel de sa
fiche, avec contrôle des comptes 622500 / 467210 / 467220 et des fiches livreurs) ; relance d'une
génération partielle, période Parrain clôturée et réaffectation d'agence couvertes par
`tests/Feature/Comptabilite/ParrainFichePaiementTest.php` ; changement de véhicule d'un livreur
de ou vers un véhicule parrainé par `tests/Feature/EquipeLivraisonTransfertLivreurTest.php`
(ADR 0035, lot 4).
