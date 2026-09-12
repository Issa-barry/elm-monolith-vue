# Parrainage véhicule

Un véhicule peut avoir **un parrain** : une personne (référentiel `Personne`, le même que celui
utilisé par `Proprietaire`/`Fournisseur`/`Prestataire`/`Livreur`/`Employe`) rattachée au véhicule
pour mémoriser qui l'a fait entrer dans le parc.

**Phase 1 (chantier du 07/09/2026)** — volontairement limitée à :
- la recherche/réutilisation d'une `Personne` existante par téléphone (jamais de doublon) ;
- la création d'une nouvelle `Personne` si aucune ne correspond ;
- l'association d'un parrain à un véhicule, sa modification en place, son remplacement.

**Explicitement hors périmètre de la phase 1** : commission de parrainage, calcul, paiement,
historique des parrains successifs d'un véhicule. L'architecture (voir ci-dessous) est conçue
pour ne pas devoir être repensée quand ces besoins arriveront, mais rien n'est implémenté
maintenant.

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
  dans `ParrainController::store()`) — une même Personne n'a donc jamais deux lignes `Parrain`,
  seulement plusieurs véhicules qui pointent vers la même ligne.
- **PARRAIN-004** — `vehicules.parrain_id` est un pointeur simple vers le parrain **actuel**, sans
  historique : changer de parrain remplace la valeur, sans conserver trace du précédent (même
  comportement que `vehicules.proprietaire_id`, cf. `ParrainController::store()`).
- **PARRAIN-005** — Modifier l'identité d'un parrain déjà rattaché (nom, téléphone, ville,
  adresse) l'édite **en place** sur sa `Personne` — jamais de re-résolution par téléphone, pour
  ne jamais rattacher silencieusement le véhicule à une autre personne existante (même principe
  que `ResolutionIdentiteTiersTrait`/`ProprietaireController::update()`). Si le nouveau téléphone
  saisi appartient déjà à une autre `Personne` de l'organisation, la modification est refusée
  (`ParrainController::assertPhoneUniqueInOrg()`) plutôt que de violer silencieusement la
  contrainte unique `personnes.(organization_id, telephone_normalise)`.
- **PARRAIN-006** — Le rattachement d'un parrain est protégé par les mêmes permissions que le
  véhicule (`vehicules.update` + isolation par organisation via `VehiculePolicy`) : pas de
  permission dédiée créée pour cette fonctionnalité.

## Modèles et migrations

| Élément | Rôle |
|---|---|
| `Parrain` (`app/Models/Parrain.php`) | Rôle léger, `personne_id` + `organization_id` + `is_active`. Accesseurs proxy vers `Personne` (nom, téléphone, ville, pays, adresse...), comme `Proprietaire`. |
| `parrains` (migration [`create_parrains_table`](../database/migrations/2026_09_07_160000_create_parrains_table.php)) | `personne_id` en FK `restrictOnDelete` vers `personnes` — une `Personne` encore parrain ne peut pas être supprimée. |
| `vehicules.parrain_id` (migration [`add_parrain_id_to_vehicules_table`](../database/migrations/2026_09_07_160001_add_parrain_id_to_vehicules_table.php)) | FK nullable `restrictOnDelete` vers `parrains`, même modèle que `proprietaire_id`. |
| `Vehicule::parrain()` | `belongsTo(Parrain::class)`. |
| `Personne::parrain()` | `hasOne(Parrain::class)` — une Personne n'a qu'un seul rôle Parrain (wrapper), réutilisé par plusieurs véhicules. |

## Backend — `ParrainController`

Trois actions, toutes scopées à un véhicule (`vehicules/{vehicule}/parrain...`) et gatées par
`$this->authorize('update', $vehicule)` :

- `rechercherTelephone` (GET, JSON) — lecture seule, ne crée jamais rien. Normalise le téléphone
  soumis (même logique que partout ailleurs : `PhoneHandlerTrait` + `Personne::normaliserTelephone()`)
  et cherche une `Personne` de l'organisation par `telephone_normalise`.
- `store` (POST) — soit `personne_id` (personne trouvée, réutilisée telle quelle), soit les
  champs d'identité complets (`nom_complet`, `telephone`, `code_pays`, `ville`, `adresse`) pour
  créer/réutiliser via `Personne::resoudreOuCreer()`. Résout ensuite le `Parrain` (créé ou
  réutilisé) et assigne `vehicule.parrain_id`.
- `update` (PUT) — édite l'identité du parrain déjà rattaché, en place.

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

## Hors périmètre — pistes pour une phase 2

Si une commission de parrainage est demandée plus tard, l'architecture actuelle n'a pas besoin
d'être repensée :
- une commission se rattacherait au modèle `Parrain` (comme les commissions propriétaire se
  rattachent à `Proprietaire`), pas à `Personne` ;
- un historique des parrains successifs d'un véhicule (si demandé) s'ajouterait comme une table
  d'événements séparée (ex. `vehicule_parrainages` avec `date_debut`/`date_fin`), en gardant
  `vehicules.parrain_id` comme pointeur dénormalisé vers le parrain **actuel** — aucun précédent
  de ce type n'existe dans le code (le changement de propriétaire n'est pas non plus historisé),
  ce serait une nouveauté à concevoir le moment venu, pas une extension mécanique de l'existant.
