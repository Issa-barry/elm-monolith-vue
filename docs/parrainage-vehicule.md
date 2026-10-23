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
  `StoreParrainController`, effet le jour même, jamais rétroactif) : un nouveau parrain reçoit une
  ligne à 0 GNF dans chaque partage existant, et le partage Distribution est créé (copie du partage
  Logistique + parrain à 0) — aucun montant ne change, aucune opération n'est bloquée. Une part
  nulle passe au nouveau parrain ; au retrait, elle disparaît et la distribution reprend le partage
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
- Onglet Parrain (`Vehicules/Show.vue`) : part par processus et catégorie, « Modifier la
  répartition », « Changer de parrain », « Retirer le parrain » ; `ParrainDialog.vue` enchaîne
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

**Vérification (lot 4, 23/10/2026)** : parcours complet couvert par
`tests/e2e/commissions/parrain-commission.spec.ts` (de l'ajout du parrain au paiement partiel de sa
fiche, avec contrôle des comptes 622500 / 467210 / 467220 et des fiches livreurs) ; relance d'une
génération partielle, période Parrain clôturée et réaffectation d'agence couvertes par
`tests/Feature/Comptabilite/ParrainFichePaiementTest.php` (ADR 0035, lot 4).
