# Identité Client ↔ Personne

**Décision produit du 08/09/2026** — `Client` devient un rôle porté par le référentiel
d'identité `Personne`, comme le sont déjà `Proprietaire`, `Fournisseur`, `Prestataire`,
`Livreur`, `Employe` et `User`. Déclenché par un bug réel constaté sur le chantier de
parrainage véhicule : le client **Guirrasy** (`+224 666 17 70 01`) n'était pas retrouvé par la
recherche de parrain car `Client` n'avait alors aucun lien vers `Personne` (îlot de données
indépendant, cf. `TelephoneOwnerLookupService`, désormais obsolète sur ce point précis).

## Règles métier (IDs)

- **CLIENTPERSONNE-001** — Une identité correspond à une seule `Personne` par organisation.
  Une même `Personne` peut porter plusieurs rôles (`Client`, `Parrain`, `Proprietaire`,
  `Fournisseur`...) sans jamais être dupliquée.
- **CLIENTPERSONNE-002** — `Client` **garde ses propres colonnes d'identité** (`nom_complet`,
  `telephone`, `email`, `pays`, `ville`, `adresse`...) : `personne_id` sert à la
  résolution/dédoublonnage d'identité entre rôles, jamais à leur remplacement. Tous les
  affichages/lectures existants (`CommandeVenteController`, `CashbackController`,
  `ClientSearchProvider`, l'espace client mobile...) continuent de lire directement les
  colonnes de `Client`, inchangées.
- **CLIENTPERSONNE-003** — À la création d'un `Client` (`ClientController::store()`,
  auto-inscription web/mobile), le téléphone est résolu via `Personne::resoudreOuCreer()` — la
  même méthode utilisée par tous les autres rôles. Si une `Personne` existe déjà dans
  l'organisation avec ce téléphone (un autre rôle, ou un autre client), elle est réutilisée
  **telle quelle** (jamais de fusion/écrasement de ses champs). `ClientController` garantit déjà
  qu'aucun autre client actif ne porte ce téléphone (`assertPhoneUniqueInOrg`), donc la Personne
  trouvée ne peut jamais appartenir à un client concurrent.
- **CLIENTPERSONNE-004** — À la modification d'un client déjà rattaché
  (`ClientController::update()`), l'identité est éditée **en place** sur sa `Personne`
  existante — jamais de re-résolution par téléphone (même principe que
  `ProprietaireController::update()`). Si le nouveau téléphone appartient déjà à une **autre**
  `Personne`, la modification est refusée (`Personne::assertTelephoneDisponible()`) plutôt que
  de heurter silencieusement la contrainte unique `personnes.(organization_id,
  telephone_normalise)`.
- **CLIENTPERSONNE-005** — Deux organisations différentes avec le même numéro donnent toujours
  deux `Personne` distinctes (l'unicité de `personnes` est scopée par organisation).
- **CLIENTPERSONNE-006** — Auto-inscription (`RegistrationService`, `CreateNewUser`) : si le
  téléphone saisi correspond à un `Client` déjà créé par le staff et que ce client a déjà sa
  propre `Personne`, le nouveau compte est rattaché à **cette** `Personne` (jamais à la
  `Personne` "à la volée" créée en tête du flow d'inscription, qui est alors supprimée) — même
  logique que pour un `Livreur`/`Proprietaire` déjà existant.
- **CLIENTPERSONNE-007** — `UpdateProfileController` (espace client, self-service) : les champs
  modifiables (`pays`, `code_pays`, `ville`, `adresse`) sont écrits à la fois sur `Client` (pour
  les lectures existantes) et sur `Client->personne` si elle est renseignée, pour que la
  localisation reste cohérente entre tous les rôles d'une même personne.

## Backfill

Migration [`2026_09_08_090001_backfill_personne_id_on_clients_table`](../database/migrations/2026_09_08_090001_backfill_personne_id_on_clients_table.php) —
pour chaque client existant sans `personne_id`, résout/crée sa `Personne` via
`Personne::resoudreOuCreer()`. Deux clients de la même organisation partageant déjà le même
téléphone (donnée historique antérieure à toute contrainte applicative) se retrouvent unifiés
sur la **même** `Personne`, jamais deux `Personne` distinctes pour un seul numéro dans une
organisation.

## Champs

| Champ | Table | Rôle |
|---|---|---|
| `personne_id` | `clients` | FK nullable vers `personnes`, `restrictOnDelete` — ajoutée par [`add_personne_id_to_clients_table`](../database/migrations/2026_09_08_090000_add_personne_id_to_clients_table.php). |
| `Personne::assertTelephoneDisponible()` | `app/Models/Personne.php` | Garde centralisée (Client, Parrain) empêchant qu'une édition en place ne collisionne silencieusement avec une autre `Personne` de l'organisation. |

## Hors périmètre de ce chantier

- `ClientIdentityResolver` (résolution du profil d'un `User` connecté) : vérifié, ne nécessite
  aucun changement — il résout des **rôles** par `user_id`/téléphone, indépendamment de la
  colonne qui porte l'identité.
- La comparaison par téléphone **brut** (non normalisé) dans `RegistrationService`/
  `CreateNewUser`/les contrôleurs `RegisterLookupController` (recherche d'un `Client` existant
  par `telephone` exact) est un comportement préexistant, non lié à ce chantier — signalé mais
  non modifié.
- `ProprietaireController::update()`/`FournisseurController::update()` n'utilisent pas encore
  `Personne::assertTelephoneDisponible()` : éditer leur téléphone vers un numéro déjà porté par
  une `Personne` d'un **autre** rôle lèverait aujourd'hui une exception SQL brute plutôt qu'un
  message de validation. Bug préexistant, adjacent mais non corrigé ici (hors périmètre de ce
  chantier).
- Faire de `Client` un pur rôle sans colonnes propres (comme `Proprietaire`) — non demandé,
  impliquerait de réécrire tous les points de lecture directe de `clients.telephone`/`nom_complet`
  (une soixantaine d'occurrences).
