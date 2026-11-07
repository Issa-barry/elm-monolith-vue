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
| 2 — Consultation | Listes et fiches Personnes physiques / Entreprises, recherche globale « Contacts », liens de menu — lecture seule | Fait (07/11/2026, commit `d7a1effb`) |
| 3 — Création | Parcours « téléphone d'abord », ajout explicite d'une fonction, une fonction par catégorie, fin de la reprise silencieuse (Fournisseur, Prestataire), D17 | Fait (07/11/2026) |
| 4 à 9 | Interlocuteurs à l'écran, reprises, paramétrage, client, bascule, fusion | À venir |

Le lot 1 n'ajoute ni écran ni route. Le lot 2 ajoute 4 routes GET en lecture seule. Le lot 3
ajoute la création (2 routes GET, 4 routes POST) et change deux comportements existants, par
décision : les formulaires Fournisseur et Prestataire refusent un numéro déjà connu
(CONTACT-020), et une fonction qui a un historique ne se supprime plus (CONTACT-019).

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

- **CONTACT-017** — « Nouveau contact » (`contacts.create` et au moins une fonction créable) : type
  (Personne ou Entreprise), téléphone puis « Rechercher », identité, première fonction. Le numéro est
  normalisé comme pour Nouvel employé (libphonenumber). Trois réponses : **libre** (formulaire) ;
  **connu** et visible (la fiche est présentée, rien n'est rattaché : « C'est la même personne »
  ajoute la fonction, « C'est une autre personne » arrête avec un message, le numéro n'est ni retiré
  ni réaffecté) ; **indisponible** (fiche non visible du lecteur : message neutre, aucune donnée, la
  création s'arrête). La création d'une fiche avec un numéro déjà connu est refusée côté serveur ;
  fiche et fonction sont créées dans une transaction, et deux créations simultanées du même numéro
  n'en laissent qu'une. Fonctions créables au lot 3 : Fournisseur et Prestataire (permission de
  création, de lecture et module actif) ; Client, Propriétaire, Parrain, Livreur et Employé gardent
  leurs parcours actuels. La modification de l'identité depuis la fiche Contact arrive au lot 7.
- **CONTACT-018** — Une fiche porte au plus une fonction de chaque catégorie, active ou non
  (décision 11) : l'ajout d'une fonction déjà portée est refusé, avec l'invitation à réactiver
  celle qui existe. Contrôle sous verrou de la fiche (`SELECT … FOR UPDATE`). L'ajout d'une
  fonction exige que la fiche soit visible du lecteur (404 sinon, y compris d'une autre
  organisation). Un consultant ajouté sur une Personne qui a un compte est relié par le
  rattachement automatique d'ADR 0056, inchangé.
- **CONTACT-019** — D17 : une fonction Client, Fournisseur, Prestataire ou Propriétaire qu'une
  opération référence (vente, cashback, proposition de véhicule ; produit, bon de commande,
  facture, paiement fournisseur ; emballage, affectation ou barème consultant, dépense, part de
  commission, fiche de paiement ; véhicule, équipe, part ou paiement logistique ; compte
  auxiliaire) ne se supprime plus : la suppression est refusée avec un message et le bouton
  Supprimer n'est affiché que pour une fiche supprimable. Les lignes supprimées logiquement
  comptent : ce sont encore des historiques. Une fiche sans historique se supprime comme avant.
- **CONTACT-020** — Fin de la reprise silencieuse dans les formulaires Fournisseur, Prestataire
  et la création rapide de fournisseur depuis un produit : un numéro déjà enregistré n'est plus
  repris en ignorant le nom saisi, il est refusé avec un message neutre qui renvoie vers Contacts.
  À la modification, un numéro déjà porté par une autre fiche est refusé par un message (et non
  plus par une erreur SQL), et la clé de comparaison du téléphone suit désormais le numéro
  modifié. Les autres saisies (clients, propriétaires, équipes et livreurs, utilisateurs, parrain
  depuis le véhicule) gardent leur comportement jusqu'à leurs lots.

## Écrans (lot 2)

| Route | Écran |
| --- | --- |
| `GET /backoffice/contacts/personnes` | Liste Personnes physiques : nom (repli sur la désignation du livreur, puis le téléphone), téléphone, localisation, badges de fonctions ; filtres fonction, recherche, sous-catégorie, ville ; 25 par page |
| `GET /backoffice/contacts/personnes/{personne}` | Fiche : Informations, Fonctions (lien vers l'écran existant de la fonction), Entreprises représentées |
| `GET /backoffice/contacts/entreprises` | Liste Entreprises : raison sociale, téléphone, ville, fonctions, interlocuteur principal, statut ; filtres statut, fonction, recherche, sous-catégorie, ville |
| `GET /backoffice/contacts/entreprises/{entreprise}` | Fiche : Informations, Fonctions, Interlocuteurs (lecture) |

Menu : « Entreprises » et « Personnes physiques » en tête de Contacts, visibles avec
`contacts.read` ; les cinq liens par fonction restent jusqu'au lot 8.

## Écrans et routes (lot 3)

| Route | Rôle |
| --- | --- |
| `GET /backoffice/contacts/nouveau?type=personne\|entreprise` | Parcours « Nouveau contact » |
| `GET /backoffice/contacts/recherche-telephone` | « Rechercher » : libre, connu ou indisponible (lecture seule) |
| `POST /backoffice/contacts/personnes`, `POST /backoffice/contacts/entreprises` | Création d'une fiche et de sa première fonction |
| `POST /backoffice/contacts/{personnes\|entreprises}/{id}/fonctions` | Ajout explicite d'une fonction à une fiche existante |

Boutons : « Nouveau contact » sur les deux listes, « Ajouter une fonction » sur les deux fiches,
affichés selon des indicateurs calculés par le serveur (`peut_creer`, `fonctions_ajoutables`).

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
- Création (lot 3) : services `CreationContact`, `FonctionsContactEcriture`,
  `RechercheTelephoneContact` ; requêtes `App\Http\Requests\Contacts\*` ; règle D17
  `App\Support\Contacts\SuppressionFonction` ; messages `MessagesContacts` ; reprise silencieuse
  retirée de `ResolutionIdentiteTiersTrait` ; page `Contacts/Creer.vue`.
- Écrans (lot 2) : contrôleurs `App\Http\Controllers\Contacts\{Personnes,Entreprises}\*`,
  policies `PersonnePolicy`, `EntrepriseTiercePolicy`, présentation
  `App\Support\Contacts\PresentationContacts`, recherche `ContactSearchProvider`, pages
  `resources/js/pages/Contacts/`.
- Tests : `tests/Feature/Contacts/`.
