# Référentiel Contacts

Décision : [ADR 0060](adr/0060-referentiel-contacts.md). Étude complète et décisions du 06/11/2026 :
document « Étude — Refonte du module Contacts (référentiel unique) ».

Un contact est une **fiche d'identité** : une Personne physique (`personnes`) ou une Entreprise
(`entreprises_tierces`). Ses **fonctions** (client, fournisseur, prestataire, propriétaire, parrain)
restent les tables existantes et gardent leurs ids : ventes, achats, commissions, dépenses, fiches de
paiement et comptes auxiliaires continuent de les référencer sans changement.

## État d'avancement

| Lot | Contenu | État |
| --- | --- | --- |
| 1 — Socle | Coordonnées, interlocuteurs (service), référentiel des sous-catégories, permissions, lecture des fonctions, synchronisation client non branchée | Fait (06/11/2026, commit `35abb314`) |
| 2 — Consultation | Listes et fiches Personnes physiques / Entreprises, recherche globale « Contacts », liens de menu — lecture seule | Fait (07/11/2026) |
| 3 à 9 | Création téléphone d'abord, interlocuteurs à l'écran, reprises, paramétrage, client, bascule, fusion | À venir |

Le lot 1 n'ajoute ni écran ni route. Le lot 2 ajoute 4 routes GET en lecture seule et ne change
aucun écran existant (Clients, Fournisseurs, Prestataires, Propriétaires, Parrains).

## Règles métier (IDs)

- **CONTACT-001** — Une identité = une fiche Personne ou Entreprise par organisation ; le téléphone
  normalisé reste unique par organisation (inchangé). Le téléphone secondaire n'est jamais unique.
- **CONTACT-002** — Une fonction garde son id et ses données propres (nature client, cashback,
  factures...). La catégorie d'un contact se déduit de ses fonctions : aucune colonne « catégorie »
  recopiée.
- **CONTACT-003** — Coordonnées d'une fiche : téléphone, téléphone secondaire, e-mail, pays, ville,
  quartier, adresse, latitude, longitude ; pour une entreprise aussi nom commercial, RCCM, NIF
  (`identifiant_fiscal`). Toutes facultatives sauf ce qu'impose déjà chaque écran.
- **CONTACT-004** — Un interlocuteur est une Personne du référentiel liée à une entreprise
  (`entreprise_interlocuteurs`) : téléphone obligatoire, nom obligatoire pour une nouvelle personne.
  Une même personne peut représenter plusieurs entreprises sans nouvelle fiche ; elle n'est jamais deux
  fois interlocuteur de la même entreprise. La fonction dans l'entreprise et les notes appartiennent au
  lien ; les coordonnées appartiennent à la personne (communes à toutes ses fonctions).
- **CONTACT-005** — Une personne déjà connue sous ce numéro n'est **jamais** rattachée à une entreprise
  sans confirmation explicite de l'utilisateur ; sans confirmation, l'ajout est refusé et la personne
  trouvée est proposée. Reprise telle quelle : ses coordonnées ne sont pas écrasées. Cette
  confirmation porte sur le lien entreprise ↔ personne ; le rattachement d'un **compte** à
  l'interlocuteur reste celui d'ADR 0056, inchangé.
- **CONTACT-006** — Au plus un interlocuteur principal actif par entreprise. Désigner un principal
  verrouille l'entreprise (`SELECT … FOR UPDATE`) le temps de la transaction et retire le rôle à tous
  les autres. Un interlocuteur n'est jamais supprimé : désactivé (date, auteur), il perd le rôle de
  principal et garde son historique ; réactivé, il ne redevient pas principal de lui-même.
- **CONTACT-007** — La lecture des fonctions d'une fiche refuse une fiche d'une autre organisation et
  ne retourne un parrain que s'il est dans le périmètre d'agences du lecteur (`Parrain::visiblesPar`).
- **CONTACT-008** — Sous-catégories (`contact_sous_categories`) : code stable, unique par
  organisation et catégorie. Les codes système reprennent les valeurs déjà stockées : natures client
  `externe`, `revendeur`, `distributeur`, `grossiste` (`clients.type`) et `consultant`
  (`prestataires.type`). Ces colonnes restent la seule source lue par les prix, le PDV et les
  commissions.
- **CONTACT-009** — Une sous-catégorie système (les 4 natures client, Consultant — D18) ne change ni
  de code ni de catégorie et ne se désactive pas ; seuls son libellé et son ordre se modifient. Une
  sous-catégorie ne se supprime jamais : désactivée, elle n'est plus proposée mais reste affichée sur les
  fiches qui la portent.
- **CONTACT-010** — Chaque organisation reçoit les sous-catégories par défaut (natures client et
  Consultant en système, machiniste et mécanicien modifiables) à sa création ; l'initialisation est
  rejouable sans doublon ni écrasement d'un libellé renommé.
- **CONTACT-011** — Qualifications multiples : un prestataire peut être machiniste, mécanicien et
  consultant sur une seule fiche (`prestataire_sous_categories`), un fournisseur porter plusieurs
  sous-catégories (`fournisseur_sous_categories`). Ces tables sont créées au lot 1 et ne sont lues par
  aucune règle avant le lot 6 (décision 7 bis).
- **CONTACT-012** — Règle R1 : la fiche Personne fera foi pour l'identité d'un client, les colonnes
  d'identité de `clients` en devenant une copie (service `ClientIdentiteSynchronisation`, une
  transaction, contrôle d'unicité du téléphone et de l'e-mail). **Non branchée** : jusqu'au lot 7 et
  l'arbitrage des écarts (D16), CLIENTPERSONNE-002 reste la règle en vigueur
  ([identite-client-personne.md](identite-client-personne.md)).

- **CONTACT-013** — Une fonction est lisible si l'utilisateur a sa permission de lecture et, quand
  elle en dépend, si le module est actif : `clients.read` ; `fournisseurs.read` + module Achats ;
  `prestataires.read` + module Prestataires ; `proprietaires.read` et `parrains.read` + module
  Véhicules ; `livreurs.read` ; `rh-employes.read` + module RH. Un lien d'interlocuteur n'est lisible
  que si son entreprise est visible. Une fonction non lisible n'apparaît ni dans les badges, ni dans
  les filtres, ni dans les données transmises au navigateur ; un filtre sur une fonction non lisible
  est refusé.
- **CONTACT-014** — Une personne ou une entreprise n'est visible que si au moins une de ses fonctions
  l'est (parrain : dans le périmètre d'agences du lecteur). Sinon elle n'apparaît nulle part dans le
  module : listes, fiches (404), recherche, compteurs. Une personne sans aucune fonction (compte seul)
  n'y apparaît jamais : elle se gère dans Utilisateurs. Une fiche d'une autre organisation répond 404,
  y compris pour un super_admin (la visibilité est vérifiée dans le contrôleur, pas seulement par la
  policy, car `Gate::before` accorde tout au super_admin).
- **CONTACT-015** — Les écrans Contacts n'exposent que les coordonnées (nom, téléphones, e-mail,
  pays, ville, quartier, adresse, position) : jamais la naissance, les pièces d'identité ni les
  données RH d'une personne.
- **CONTACT-016** — Le module est en lecture seule au lot 2 : aucune création, modification,
  suppression ni association. La recherche globale ajoute un groupe « Contacts » (fiches Personne et
  Entreprise) ; les groupes existants ne changent pas, une même personne peut figurer sous
  « Clients » et « Contacts » jusqu'au lot 8.

## Écrans (lot 2)

| Route | Écran |
| --- | --- |
| `GET /backoffice/contacts/personnes` | Liste Personnes physiques : nom (repli sur la désignation du livreur, puis le téléphone), téléphone, localisation, badges de fonctions ; filtres fonction, recherche, sous-catégorie, ville ; 25 par page |
| `GET /backoffice/contacts/personnes/{personne}` | Fiche : Informations, Fonctions (lien vers l'écran existant de la fonction), Entreprises représentées |
| `GET /backoffice/contacts/entreprises` | Liste Entreprises : raison sociale, téléphone, ville, fonctions, interlocuteur principal, statut ; filtres statut, fonction, recherche, sous-catégorie, ville |
| `GET /backoffice/contacts/entreprises/{entreprise}` | Fiche : Informations, Fonctions, Interlocuteurs (lecture) |

Menu : « Entreprises » et « Personnes physiques » en tête de Contacts, visibles avec
`contacts.read` ; les cinq liens par fonction restent jusqu'au lot 8.

## Permissions

| Permission | Effet |
| --- | --- |
| `contacts.read` | Consulter les fiches Personne et Entreprise |
| `contacts.create` | Créer une fiche |
| `contacts.update` | Modifier l'identité (s'applique à toutes les fonctions de la fiche) |
| `contacts.gerer_categories` | Paramétrer les sous-catégories |
| `contacts.gerer_interlocuteurs` | Ajouter, modifier, désactiver un interlocuteur |

Pas de `contacts.delete` : une fiche se désactive. Ajoutées par migration à `super_admin` et
`admin_entreprise` ; les autres rôles les reçoivent dans « Rôles ». Les blocs de chaque fonction
restent soumis à leurs permissions existantes (`clients.read`, `fournisseurs.read`...).

## Contraintes techniques

- Aucune entrée ajoutée au morph map : `compta_tiers.tiersable_type` stocke le nom de classe des
  fonctions client, fournisseur, prestataire et parrain ; un alias empêcherait
  `TiersComptable::resoudrePour()` de retrouver leur compte et en créerait un second. Les tables de
  liaison utilisent de vraies clés étrangères par fonction.
- Pas d'index unique partiel ni de colonne générée : les tests tournent sous SQLite, la production
  n'est pas confirmée MySQL ou MariaDB.

## Code

- Modèles : `ContactSousCategorie`, `FonctionSousCategorie` (pivot), relations
  `Prestataire::sousCategories()`, `Fournisseur::sousCategories()`,
  `EntrepriseTierce::interlocuteurPrincipal()`, `Personne::interlocutions()`.
- Catalogue : `App\Support\Contacts\SousCategoriesSysteme`, enum `ContactCategorie`.
- Services : `App\Services\Contacts\InterlocuteurService`, `FonctionsContact`,
  `ClientIdentiteSynchronisation` (non branché), `VisibiliteContacts` (source unique de la
  visibilité des listes, fiches et recherche — lot 2).
- Écrans (lot 2) : contrôleurs `App\Http\Controllers\Contacts\{Personnes,Entreprises}\*`,
  policies `PersonnePolicy`, `EntrepriseTiercePolicy`, présentation
  `App\Support\Contacts\PresentationContacts`, recherche `ContactSearchProvider`, pages
  `resources/js/pages/Contacts/`.
- Tests : `tests/Feature/Contacts/`.
