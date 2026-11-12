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
| 3 — Création | Parcours « téléphone d'abord », ajout explicite d'une fonction, une fonction par catégorie, fin de la reprise silencieuse (Fournisseur, Prestataire), D17 | Fait (07/11/2026, commit `ae3f8842`) |
| 4 — Interlocuteurs | Gestion depuis l'onglet Interlocuteurs de la fiche Entreprise : ajout « téléphone d'abord », fonction et notes, principal, désactivation, réactivation, journal | Fait (07/11/2026, commit `48234f04`) |
| 5 — Propriétaire entreprise | Lien vers une fiche Entreprise, contact devenu interlocuteur, reprise des données réversible (ADR 0060, décisions D1 à D10) | Fait (08/11/2026, commit `d2c763e3`) |
| 6a — Catégories de contacts | Écran de paramétrage, sous-catégories Fournisseur multiples, catégories libres (Partenaire) et filtre, sous-catégories système en lecture seule | Fait (08/11/2026, commit `f3e1508b`) |
| 6b — Qualifications Prestataire | Qualifications multiples, `qualifieConsultant()`, D15, reprise des qualifications | Fait (09/11/2026, commit `059e64dd`) |
| 7c — Nature du client sur la commande | `commandes_ventes.nature_client` figée à la création, lue par les commissions, listes et exports (D12 à D14) | Fait (09/11/2026, commit `74db6f7a`) |
| 7a — Identité client | La fiche Personne fait foi (R1) pour l'identité, sans la localisation (10/11/2026) ; rapport, arbitrage et activation par organisation (D16) ; modification de l'identité depuis Contacts | Fait (09/11/2026, commit `d268ffe8`) ; localisation retirée le 10/11/2026 |
| 7b — Client professionnel | Client relié à une fiche Entreprise (règles du lot 5), raison sociale comme nom principal (L7-2) | Fait (09/11/2026, commit `ee322eb0`) |
| 8 — Bascule | Menu « Par fonction », groupe « Contacts » unique dans la recherche du back-office, création Fournisseur / Prestataire par le parcours Contacts (L8-1 à L8-3) | Fait (09/11/2026, commit `0b06d1c6`) |
| 9a — Détection des doublons | Commande `contacts:doublons` en lecture seule : paires probables d'entreprises et blocage F1 ; personnes jamais rapprochées (10/11/2026) | Fait (09/11/2026, en production le 10/11/2026) ; personnes retirées le 10/11/2026 |
| 9b — Fusion manuelle | Écran de fusion des personnes et des entreprises : simulation, arbitrage champ par champ, confirmation, archivage de la fiche absorbée, historique et annulation sûre (F1 à F4, décisions 1 à 8) | Développé le 11/11/2026, non commité |

Le lot 1 n'ajoute ni écran ni route. Le lot 2 ajoute 4 routes GET en lecture seule. Le lot 3
ajoute la création (2 routes GET, 4 routes POST) et change deux comportements existants, par
décision : les formulaires Fournisseur et Prestataire refusent un numéro déjà connu
(CONTACT-020), et une fonction qui a un historique ne se supprime plus (CONTACT-019). Le lot 4
ajoute 6 routes de gestion des interlocuteurs (1 GET en lecture seule, 4 POST, 1 PATCH) ; aucune
migration, aucun changement d'accès aux commissions. Le lot 5 ajoute une colonne
(`proprietaires.entreprise_tierce_id`), deux tables de journal de reprise, une route GET en lecture
seule et la commande `contacts:reprendre-proprietaires-entreprises` ; il ne concerne que les
propriétaires de type entreprise. Le lot 6a ajoute trois tables (catégories libres et leurs
liaisons), une migration de données (« Partenaire » par organisation), l'écran « Catégories de
contacts » (1 GET, 3 POST, 2 PATCH) et l'attribution des catégories libres (2 PUT) ; aucun effet
sur les ventes, commissions, comptabilité ou logistique.

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
  (`prestataires.type`). `clients.type` reste la seule source lue par les prix, le PDV et les
  commissions ; depuis le lot 6b, la qualification Consultant se lit sur les qualifications du
  prestataire (CONTACT-039), `prestataires.type` n'étant plus qu'une copie de compatibilité.
- **CONTACT-009** — Une sous-catégorie système (les 4 natures client, Consultant — D18) ne change ni
  de code ni de catégorie, ne se renomme pas et ne se désactive pas ; seul son ordre se modifie
  (lot 6a, décision C : le libellé reste en lecture seule tant que ventes, PDV et factures ne lisent
  pas le référentiel ; D18 interdit la désactivation, il n'autorise pas le renommage). Une
  sous-catégorie ne se supprime jamais : désactivée, elle n'est plus proposée mais reste affichée sur les
  fiches qui la portent.
- **CONTACT-010** — Chaque organisation reçoit les sous-catégories par défaut (natures client et
  Consultant en système, machiniste et mécanicien modifiables) à sa création ; l'initialisation est
  rejouable sans doublon ni écrasement d'un libellé renommé.
- **CONTACT-011** — Qualifications multiples : un prestataire peut être machiniste, mécanicien et
  consultant sur une seule fiche (`prestataire_sous_categories`), un fournisseur porter plusieurs
  sous-catégories (`fournisseur_sous_categories`). Créées au lot 1 ; les sous-catégories fournisseur
  sont saisies au lot 6a (sans effet métier), les qualifications prestataire font foi depuis le lot 6b
  (décision 7 bis).
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
  « Clients » et « Contacts » jusqu'au lot 8 (regroupement : CONTACT-051).

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

- **CONTACT-021** — Gestion des interlocuteurs (lot 4) : permission `contacts.gerer_interlocuteurs`
  et entreprise visible du lecteur, vérifiées par le serveur à chaque requête (recherche comprise).
  Une entreprise non visible ou d'une autre organisation répond 404, y compris pour un super_admin.
  Un interlocuteur est toujours désigné dans l'URL de son entreprise : un interlocuteur d'une autre
  entreprise répond 404. Les actions de l'onglet ne s'affichent qu'avec la permission
  (`peut_gerer_interlocuteurs` calculé par le serveur).
- **CONTACT-022** — Ajout « téléphone d'abord » : téléphone puis « Rechercher » (lecture seule).
  **Libre** : identité (nom obligatoire), fonction dans l'entreprise, notes. **Connu** et visible :
  la personne est présentée, rien n'est rattaché ; « C'est la même personne » l'ajoute telle quelle,
  « C'est une autre personne » arrête avec un message. **Déjà interlocuteur** de cette entreprise :
  l'ajout est refusé, avec l'invitation à réactiver un lien désactivé. **Indisponible** (personne non
  visible du lecteur) : message neutre, aucune donnée, l'ajout s'arrête. Le serveur applique la même
  règle à l'enregistrement : une personne non visible, qu'elle porte le numéro saisi ou soit désignée
  par un identifiant forgé, reçoit le message neutre et n'est jamais rattachée ni nommée.
- **CONTACT-023** — La modification d'un interlocuteur ne porte que sur sa fonction dans
  l'entreprise et ses notes. Ses coordonnées appartiennent à sa fiche Personne et ne se modifient pas
  depuis cet écran (lot 7).
- **CONTACT-024** — Principal : la case « Interlocuteur principal » est proposée cochée quand
  l'entreprise n'a aucun principal actif, et reste un choix de l'utilisateur. Désigner un principal,
  en ajoutant ou depuis la liste, le retire à l'ancien sous verrou de l'entreprise (CONTACT-006). Un
  interlocuteur désactivé ne peut pas être désigné.
- **CONTACT-025** — Entreprise désactivée : aucun interlocuteur ne peut lui être ajouté (refus serveur,
  bouton remplacé par un message) ; ses interlocuteurs existants restent modifiables, désignables,
  désactivables et réactivables.
- **CONTACT-026** — Journal : chaque ajout, modification, désignation, désactivation et réactivation
  est inscrit au journal d'audit existant (`created`, `updated`, `status_changed`) avec des
  identifiants et des indicateurs seulement (entreprise, principal, personne existante, anciens
  principaux, noms des champs modifiés) : jamais de nom, de téléphone, d'e-mail ni le texte des
  notes. L'accès aux commissions (`acces_commissions`) n'est ni affiché ni modifié par ces écrans.

- **CONTACT-027** — Propriétaire entreprise (lot 5) : un propriétaire de type entreprise est relié à
  une fiche Entreprise (`proprietaires.entreprise_tierce_id`) ; `personne_id` reste son contact
  (téléphone, compte, espace client — D1). Une entreprise porte au plus une fonction Propriétaire,
  contrôlée sous verrou de l'entreprise puis du propriétaire. Le propriétaire personne physique ne
  change pas (D4).
- **CONTACT-028** — Formulaire Propriétaire, type entreprise (D2, D3, D6) : raison sociale,
  téléphone et NIF de l'entreprise facultatifs ; le téléphone de l'entreprise n'est jamais celui du
  contact. « Rechercher une entreprise existante » (lecture seule) liste les entreprises visibles qui
  ont le même téléphone, le même NIF ou la même raison sociale normalisée (minuscules, sans accents
  ni ponctuation). Aucun rattachement d'office : dès qu'une entreprise correspond, l'enregistrement
  exige un choix explicite (l'une d'elles, ou « Créer une nouvelle entreprise ») et le serveur refuse
  sinon, sans rien enregistrer. Sans correspondance, la fiche Entreprise est créée en arrière-plan
  avec la raison sociale, le NIF et le téléphone saisis, et rien d'autre : ni le téléphone ni la
  localisation (pays, ville, adresse) du contact ne sont repris, et aucune synchronisation n'existe
  ensuite entre la fiche du contact et celle de l'entreprise (décision du 08/11/2026). Un
  téléphone déjà porté par une entreprise de la liste interdit d'en créer une seconde. Un téléphone
  ou un NIF d'une entreprise non visible du lecteur donne un message neutre et arrête ; une
  entreprise non visible trouvée par sa seule raison sociale n'est pas proposée. Un identifiant
  d'entreprise forgé, non visible ou d'une autre organisation reçoit « Entreprise introuvable » ; une
  entreprise désactivée ou déjà propriétaire n'est pas sélectionnable. Jamais de fusion.
- **CONTACT-029** — La fiche Entreprise fait foi (D5) : au lien, `proprietaires.raison_sociale`
  reprend celle de l'entreprise (la saisie ne la renomme pas) ; ensuite, tout changement de raison
  sociale de l'entreprise, quel que soit l'écran (Fournisseur, Prestataire, Propriétaire), est recopié
  sur le propriétaire dans la même transaction. `nom_affichage`, l'espace client, les exports et les
  fiches de paiement lisent toujours cette colonne : ids, comptes auxiliaires, commissions et fiches
  inchangés.
- **CONTACT-030** — Identité de l'entreprise reliée depuis l'écran Propriétaire (D7) : raison
  sociale, téléphone et NIF. Si l'entreprise ne porte que la fonction Propriétaire,
  `proprietaires.update` suffit. Si elle porte une autre fonction (Fournisseur ou Prestataire, active
  ou non), il faut aussi `contacts.update` et une confirmation explicite ; sans le droit, les champs
  sont en lecture seule et le serveur refuse, la confirmation ne remplaçant jamais le droit. Une
  saisie identique à l'existant n'est pas un changement. Le contact (`personne_id`) devient
  interlocuteur de l'entreprise, sans doublon de lien (un lien existant est réutilisé tel quel, un lien
  désactivé reste désactivé), principal seulement si l'entreprise n'en a aucun (D9), les autres
  interlocuteurs étant conservés ; ce rattachement passe par `InterlocuteurService` sans exiger
  `contacts.gerer_interlocuteurs`. Passer le propriétaire en personne physique retire le lien et le
  journalise ; l'entreprise et ses interlocuteurs sont conservés (D8). Sur la fiche Personne du
  contact, la fonction Propriétaire porte la mention « au nom de » l'entreprise, avec un lien vers sa
  fiche (D10) ; la fiche Entreprise porte la fonction Propriétaire (visible avec `proprietaires.read`
  et le module Véhicules).
- **CONTACT-031** — Reprise des propriétaires entreprise existants :
  `php artisan contacts:reprendre-proprietaires-entreprises --acteur=<id utilisateur>`. Simulation par
  défaut (`--dry-run`), rien n'est écrit ; `--apply` exige `proprietaires.update` et `contacts.update`
  pour l'acteur, s'exécute ligne par ligne dans une transaction et reste idempotent (un propriétaire
  déjà relié est ignoré). Sans correspondance par raison sociale normalisée, l'entreprise est créée
  (raison sociale seule : ni téléphone ni localisation déduits du contact) et reliée ; avec une correspondance, la ligne est « à arbitrer » et rien n'est
  relié : l'arbitrage se fait explicitement par `--relier=<propriétaire>:<entreprise>` ou
  `--creer=<propriétaire>`. Rapport d'anomalies : raison sociale vide, contact absent, contact d'une
  autre organisation, contact sans téléphone. Chaque exécution appliquée reçoit un identifiant
  (`contacts_reprises`) ; chaque ligne garde ses valeurs d'avant, l'entreprise créée ou réutilisée,
  l'état du lien interlocuteur avant l'exécution et le principal posé (`contacts_reprise_lignes`) —
  identifiants seulement, aucune donnée personnelle.
- **CONTACT-032** — Annulation d'une reprise : `--annuler=<identifiant>` (simulation sans `--apply`).
  Une ligne n'est annulée que si le propriétaire est encore relié à l'entreprise posée par
  l'exécution ; sinon elle est marquée « conflit » et rien n'est touché. Annuler retire le lien,
  restaure la raison sociale d'avant, désactive l'interlocuteur créé par l'exécution (jamais supprimé)
  et retire le rôle de principal qu'elle avait posé sur un lien existant. Les entreprises créées sont
  conservées, même sans fonction (invisibles dans Contacts) : aucune identité n'est effacée.
  L'annulation est journalisée ; la relancer ne fait rien de plus.

- **CONTACT-033** — Écran « Catégories de contacts » (lot 6a), menu Contacts : consultation et
  administration réservées à `contacts.gerer_categories`, revérifiée à chaque requête ; un élément
  d'une autre organisation répond 404. Sous-catégories par fonction : celles des fournisseurs
  (lot 6a) et des prestataires (lot 6b, décision Q3 — Consultant reste protégé) s'administrent ;
  celles des clients sont affichées en lecture seule (leurs libellés sont encore écrits en dur dans
  ventes et PDV). Rien ne se supprime.
- **CONTACT-034** — Code et libellé : le code est généré une fois depuis le libellé (minuscules,
  sans accents, `_2`, `_3`… en cas de collision) et ne change plus, même après renommage. Libellé
  unique, sans distinction de casse, par organisation et par fonction (sous-catégories) ou par
  organisation (catégories libres). Une sous-catégorie système ne se renomme pas, ne se désactive
  pas et ne change pas de code, y compris pour un super_admin (verrou du modèle).
- **CONTACT-035** — Désactivation : un élément désactivé n'est plus proposé pour une nouvelle
  saisie, reste affiché sur les fiches qui le portent, et ces fiches restent enregistrables ; il
  peut être réactivé. L'ordre d'une liste se change en une fois (la liste envoyée doit être
  complète, sinon refus).
- **CONTACT-036** — Sous-catégories d'un fournisseur (formulaire Fournisseur, `fournisseurs.create`
  ou `fournisseurs.update`) : plusieurs possibles, de l'organisation et de la fonction Fournisseur
  seulement ; une désactivée se garde mais ne s'ajoute pas ; sans le champ, les sous-catégories
  portées ne changent pas. Affichées sur la ligne de fonction des fiches Contacts et filtrables
  (filtre « Sous-catégorie »). Aucun effet sur les achats, factures ou la comptabilité.
- **CONTACT-037** — Catégories libres (ex. Partenaire, décision 5) : étiquettes sans effet sur les
  ventes, commissions, comptabilité, logistique ni permissions, distinctes des catégories de
  produits ou de véhicules et des natures client. « Partenaire » est créée dans chaque organisation,
  existante (migration rejouable) et nouvelle, une seule fois même renommée ; elle reste renommable
  et désactivable. Attribution depuis le bloc « Catégories » des fiches Personne et Entreprise avec
  `contacts.update`, sur une fiche visible seulement (404 sinon, super_admin compris) : la liste
  envoyée remplace la liste portée, dans une transaction ; une catégorie désactivée se garde mais ne
  s'ajoute pas. Filtre « Catégorie » sur les deux listes. Un contact sans fonction reste invisible
  (CONTACT-014 inchangée) : le cas « partenaire seul » est hors lot 6a. Journal d'audit : ids
  ajoutés et retirés seulement.

- **CONTACT-038** — Qualifications d'un prestataire (lot 6b) : plusieurs, égales, sans principale,
  enregistrées dans `prestataire_sous_categories`, qui fait foi. Saisie sur le formulaire Prestataire
  et à la création depuis Contacts : au moins une qualification active (Q1) ; une qualification
  désactivée se garde mais ne s'ajoute pas ; une qualification d'une autre fonction ou organisation
  est refusée. L'ancien champ `type` (un code) reste accepté par les formulaires et reçoit l'erreur
  « au moins une qualification » quand aucune n'est envoyée. Journal d'audit : ids ajoutés et retirés.
- **CONTACT-039** — Critère unique `Prestataire::qualifieConsultant()` (scope
  `qualifiesConsultant()`, condition partagée pour `Rule::exists`) : seule la qualification système
  Consultant ouvre l'éligibilité ; une qualification libre n'est jamais assimilée à Consultant ni ne
  déclenche de règle. Il remplace les dix lectures de `type = consultant` : générateur de
  commissions (consultant actif), consultants éligibles du barème, validation du barème et de
  l'affectation, concerné « Consultant » des dépenses (serveur et navigateur), activités du compte,
  rattachement de compte, fiches rattachables. Aucune règle de calcul ni aucun barème ne change.
  Repli transitoire (Q2) : un prestataire sans aucune qualification enregistrée est lu sur l'ancien
  `type`, le temps de la reprise au déploiement ; dès qu'une qualification existe, elle fait foi.
- **CONTACT-040** — D15 : retirer la qualification Consultant d'un prestataire est refusé tant qu'une
  affectation consultant active ou un barème actif (en cours ou à venir) le désigne, contrôlé côté
  serveur dans une transaction, sous verrou du prestataire, super_admin compris ; message vers
  Paramètres → Commissions. Désactiver un prestataire garde son comportement actuel (Q4).
- **CONTACT-041** — `prestataires.type` devient facultatif et reste une copie de compatibilité (Q5) :
  l'ancienne valeur est gardée si elle figure encore parmi les qualifications, sinon la première des
  trois anciennes valeurs portée (ordre du référentiel), sinon vide ; Consultant n'a aucune priorité.
  Reprise au déploiement par migration de données : une qualification par prestataire d'après son
  `type` (supprimés logiquement compris), rejouable sans doublon, sans retirer ni modifier aucune
  valeur ; les prestataires restés sans qualification sont signalés dans le journal applicatif.

- **CONTACT-042** — Nature du client sur la commande (lot 7c, D12 à D14) : chaque nouvelle commande
  enregistre la nature de son client à sa création (`commandes_ventes.nature_client`, quel que soit
  le point d'entrée : back-office, PDV, précommande) ; si le client de la commande est remplacé, la
  nature suit le nouveau client. Un changement de nature d'un client ne vaut que pour ses nouvelles
  commandes (D14) : la fiche Client l'indique sous le champ et cite les commandes non terminées.
  Commandes antérieures : colonne vide, repli sur la nature actuelle du client (D13), comportement
  inchangé. Lue par le générateur de commissions (processus Vente ou Transfert grossiste), le suivi
  des commissions non générées, la liste et l'export des ventes, la modification d'une vente, les
  précommandes et le contrôle de confirmation. Les prix et les communications lisent toujours la
  nature actuelle du client (aucun calcul de prix modifié). Règle de commission mise à jour dans
  [commissions.md](commissions.md) (COMM-011).

- **CONTACT-043** — R1 (lot 7a) : la fiche Personne fait foi pour l'identité d'un client
  (`nom`, `prenom`, `nom_complet`, `telephone`, `code_phone_pays`, `email`) ; ces colonnes de
  `clients` en sont une copie. **La localisation (`pays`, `code_pays`, `ville`, `adresse`) n'en fait
  pas partie** (décision du 10/11/2026) : chaque fonction garde la sienne — l'adresse d'un client
  peut être celle de sa boutique, distincte du domicile porté par la Personne. Elle n'est jamais
  recopiée, ni de la Personne vers le client, ni du client vers la Personne (reprise, synchronisation
  et création au back-office comprises). **Active par organisation
  seulement** (`organizations.synchronisation_identite_client_le`), après le rapport des écarts et
  leur arbitrage (D16) ; avant, comportement inchangé (CLIENTPERSONNE-002). Une fois active : toute
  modification de la Personne, quel que soit l'écran (Client, Contacts, Propriétaire, espace client,
  Employé…), est recopiée sur ses clients avant l'enregistrement, et un conflit d'unicité client
  (téléphone, e-mail) refuse aussi la modification de la Personne ; un client créé (back-office,
  inscription) reçoit l'identité de sa Personne ; au back-office, une Personne existante garde son
  identité, seuls ses champs vides sont complétés depuis la saisie. Nature, cashback, dérogation et
  tarifs ne sont jamais touchés.
- **CONTACT-044** — Modification de l'identité d'une fiche depuis Contacts : bouton « Modifier » de
  l'onglet Informations des fiches Personne et Entreprise, avec `contacts.update` et une fiche
  visible (404 sinon, super_admin compris). La fiche vaut pour toutes ses fonctions. Téléphone
  unique par organisation : un numéro d'une fiche non visible reçoit le message neutre (D6).
  Entreprise : téléphone et ville facultatifs ; un renommage est recopié sur son propriétaire
  (CONTACT-029). Journal : noms des champs seulement.
- **CONTACT-045** — Rapport des écarts : `contacts:ecarts-clients --acteur=<id>` (simulation par
  défaut) compare chaque client à sa Personne, sur les seules colonnes d'identité (CONTACT-043 : la
  localisation n'est jamais comparée ni écrite). Un champ vide d'un côté est complété depuis l'autre ;
  deux clients d'une même Personne qui proposent deux valeurs pour un champ vide de la Personne, ou
  deux valeurs différentes, sont des **conflits** jamais tranchés automatiquement (L7-1). Arbitrage
  explicite client par client : `--personne-fait-foi=<client>` ou `--client-fait-foi=<client>` (sur
  un champ vide de la Personne, seul « le client fait foi » tranche). Une différence de casse ou
  d'espaces n'est pas un conflit. `--apply` (droits `clients.update` et `contacts.update`) écrit les
  complétions et arbitrages, refuse un téléphone déjà porté par une autre fiche, et journalise chaque
  champ (valeurs d'avant et d'après, `contacts_reprise_valeurs`) sous un identifiant d'exécution.
  Anomalie signalée : client sans Personne.
- **CONTACT-046** — `--annuler=<exécution>` (simulation sans `--apply`) restaure chaque champ qui vaut
  encore la valeur écrite par l'exécution ; un champ modifié depuis est signalé « conflit » et laissé
  tel quel. `--activer` démarre la synchronisation de l'organisation, refusée tant qu'un conflit ou
  une complétion reste, puis aligne l'écriture de chaque client sur sa Personne ; journalisée, une
  seule fois.
- **CONTACT-047** — Client professionnel (lot 7b, L7-3) : case « Client professionnel (entreprise) »
  du formulaire Client. Cochée, le client est relié à une fiche Entreprise
  (`clients.entreprise_tierce_id`) avec les règles du propriétaire entreprise : raison sociale
  obligatoire, téléphone et NIF de l'entreprise facultatifs et jamais ceux du contact (D2), recherche
  des entreprises qui correspondent et choix explicite ou « nouvelle » (D3), entreprise non visible
  jamais révélée (D6), contact (`personne_id`) devenu interlocuteur, principal seulement si
  l'entreprise n'en a aucun (D9). Une entreprise porte au plus un client ; elle peut porter en même
  temps les fonctions Fournisseur, Prestataire et Propriétaire. Aucun client existant n'est relié
  automatiquement : il l'est à sa prochaine modification, case cochée.
- **CONTACT-048** — Nom d'un client professionnel (L7-2) : `clients.nom_complet` porte la raison
  sociale de l'entreprise, recopiée au lien puis à chaque renommage de l'entreprise quel que soit
  l'écran (`EntrepriseTierce::booted()`, comme CONTACT-029), et conservée par la synchronisation
  d'identité (CONTACT-043). Ventes, PDV, factures et espace client, qui lisent ce nom, affichent donc
  la raison sociale. Le champ « Nom du contact » du formulaire modifie la Personne. La fiche Client
  affiche le contact en mention secondaire ; les autres écrans ne l'affichent pas encore.
- **CONTACT-049** — Modification et retrait (lot 7b) : modifier raison sociale, téléphone ou NIF de
  l'entreprise depuis le formulaire Client suit D7 (`clients.update` ; entreprise qui porte une autre
  fonction : `contacts.update` et confirmation explicite). Décocher la case retire le lien (D8,
  journalisé, origine `client_particulier`) sans supprimer l'entreprise ni ses interlocuteurs ; le
  nom du client redevient le nom du contact. Fiche Entreprise : fonction « Client » (avec
  `clients.read`) ; fiche Personne : mention « au nom de » (D10).
- **CONTACT-050** — Menu Contacts (lot 8, L8-1) : « Entreprises » et « Personnes physiques » en tête,
  puis l'intertitre « Par fonction » avec les écrans Clients, Fournisseurs, Prestataires,
  Propriétaires et Parrains — permissions et modules inchangés, aucun écran ni URL retiré (cashback,
  tarifs, dérogations, commissions, véhicules et comptes restent sur ces écrans) — puis
  « Paramétrage » (Catégories de contacts).
- **CONTACT-051** — Recherche globale du back-office (lot 8, L8-2) : avec `contacts.read`, un seul
  groupe « Contacts » en tête remplace les groupes Clients, Propriétaires et Livreurs. Chaque
  résultat porte un badge par fonction visible, avec le lien vers l'écran métier quand il existe
  (fournisseur, prestataire : pas d'écran de consultation, badge sans lien). Rien n'est perdu : la
  recherche porte aussi sur le nom et le téléphone écrits sur la fiche Client et sur la désignation
  du livreur ; un client encore sans Personne reste retrouvable (lien vers sa fiche Client) ; le
  groupe affiche autant de résultats que les groupes qu'il remplace (20 au plus). Un groupe dont la
  fonction n'est pas lisible dans Contacts (module inactif) reste séparé ; sans `contacts.read`, les
  groupes historiques restent. L'API `v1/search/global` (mobile) garde ses groupes : contrat
  inchangé. Les listes Contacts retrouvent aussi une personne par le nom de sa fiche Client.
- **CONTACT-052** — Création (lot 8, L8-3) : avec `contacts.create`, le bouton « Nouveau » des écrans
  Fournisseurs et Prestataires ouvre le parcours « téléphone d'abord » de Contacts, fonction
  présélectionnée (`?fonction=`, ignorée si l'utilisateur ne peut pas la créer) ; sinon le
  formulaire de l'écran. Le parcours propose les sous-catégories Fournisseur, comme l'écran
  Fournisseur. Client, Propriétaire et Parrain gardent leur formulaire (champs métier : nature,
  cashback, dérogation, type de propriétaire…), le parcours Contacts ne créant que les fonctions
  Fournisseur et Prestataire (CONTACT-017).
- **CONTACT-053** — Doublons probables d'entreprises (lot 9a) : `contacts:doublons --acteur=<id>`
  (droit `contacts.read`, organisation de l'acteur) liste les paires d'entreprises qui se
  ressemblent, **en lecture seule** : aucune fusion, aucun rattachement, aucune écriture en base. Ce
  sont des rapprochements, jamais des certitudes : même NIF, même raison sociale normalisée, même
  e-mail. Fiches supprimées et autres organisations ignorées. `--export=<fichier.csv>` écrit une
  ligne par paire (séparateur « ; »). **Les personnes ne sont jamais rapprochées** (règle du
  10/11/2026) : deux numéros de téléphone différents désignent deux personnes différentes, même nom,
  même ville ou même e-mail compris. Ni le nom ni l'e-mail ne déclenchent un rattachement ou une
  fusion ; deux fiches d'une même personne ne se fusionnent que sur décision de l'administration
  (lot 9b).
- **CONTACT-054** — Pour chaque paire, le rapport donne les fonctions des deux entreprises et ce qui
  bloquerait la fusion (décision F1 du lot 9) : **même fonction** des deux côtés (les fonctions ne
  sont jamais fusionnées, aucun historique métier n'est touché).
- **CONTACT-055** — Fusion manuelle (lot 9b) : jamais automatique. Depuis une fiche Personne ou
  Entreprise (fiche A, conservée), l'administrateur cherche la fiche B (même type, même
  organisation, visible), compare les deux fiches et arbitre chaque champ différent. Chaque choix
  relance une **simulation sans écriture** ; rien n'est fusionné sans confirmation explicite. Par
  défaut, les noms (nom, prénom, nom complet, surnom, raison sociale, nom commercial) restent ceux de
  A, même vides — le nom affiché peut venir d'une fonction ; les autres champs vides de A sont
  complétés par B. « Conserver plutôt B » inverse les rôles. Entreprises : contact principal
  arbitré comme un champ, interlocuteur principal choisi si A et B en ont chacune un (décision 8).
- **CONTACT-056** — Blocages : même fonction des deux côtés, **fonction archivée comprise** (F1) ;
  A et B interlocuteurs de la même entreprise (décision 4) ; un compte utilisateur de chaque côté
  (F2) ; une fonction ou un lien que l'utilisateur ne peut pas consulter (décision 6, message
  neutre) ; une donnée liée d'une autre organisation ou un compte sans organisation ; identité
  retenue en conflit d'unicité avec un autre client quand la synchronisation R1 est active.
- **CONTACT-057** — Historique métier intact : les fonctions changent de fiche **avec leur id**
  (ventes, factures, paiements, commissions, écritures et comptes auxiliaires pointent vers elles) ;
  rien n'est supprimé, recréé ni réaffecté à une autre fonction. Sont transférés vers A : les
  fonctions, le compte, les liens d'interlocuteur, le rôle de contact d'une entreprise, les
  catégories libres, les pièces d'identité. Une catégorie ou un interlocuteur déjà présent sur A
  reste sur la fiche archivée. La fiche B est **archivée** (suppression logique, jamais physique)
  avec un lien vers A ; son adresse redirige vers A. Le journal de reprise du lot 6 n'est jamais
  réécrit.
- **CONTACT-058** — Téléphones (F3, décision 2) : un principal et un secondaire au plus, choisis
  parmi les numéros des deux fiches (par défaut : principal de A ; secondaire de A, sinon numéro de
  B). Les autres quittent la fiche avec confirmation et restent au journal. Le numéro principal de
  B est libéré à l'archivage, son numéro secondaire aussi (il peut devenir un numéro de A).
  Décision 3 : un numéro secondaire est reconnu par la recherche
  « téléphone d'abord » (statut `secondaire`, lien vers la fiche) mais n'ouvre ni création ni
  rattachement automatique ; la création Contacts avec ce numéro est refusée.
- **CONTACT-059** — Compte utilisateur (F2, décision 5, précision 1) : un seul compte — la
  simulation montre ses rôles, ses identifiants de connexion et les fonctions qui pourront lui être
  rattachées (ADR 0056, visibles dans l'espace client) ; confirmation explicite et permission
  `users.update` exigées. Compte sur B : il passe sur A, confirmation renforcée (saisir
  `TRANSFERER`). Les identifiants de connexion du compte ne sont **jamais** modifiés par la fusion :
  garder le numéro de B comme principal aligne la fiche sur le numéro de connexion.
- **CONTACT-060** — Sécurité d'exécution : transaction, verrou sur A et B (ordre fixe), simulation
  refaite sous verrou, **empreinte** des deux fiches et de leurs liens comparée à celle de la
  simulation (toute modification concurrente refuse la fusion), jeton de formulaire (un double envoi
  ne fusionne qu'une fois), vérification qu'aucune table ne pointe encore vers B avant de valider.
- **CONTACT-061** — Journal : chaque valeur écrite, avant et après — champs arbitrés, liens
  transférés, numéro libéré, archivage, effets induits (identité recopiée sur le client si R1 est
  active, raison sociale recopiée sur le propriétaire et le client professionnel, rattachement au
  compte, rôle ajouté au compte) — et éléments laissés tels quels (numéros retirés, doublons de
  catégorie ou d'interlocuteur). Écran « Historique des fusions ».
- **CONTACT-062** — Annulation (décision 7, précision 3) : sans délai, depuis le détail d'une
  fusion. Le journal est relu à rebours : une valeur n'est remise que si elle est encore celle que
  la fusion a écrite ; sinon elle n'est **jamais écrasée** et apparaît « à traiter ». Un rôle ajouté
  au compte n'est jamais retiré automatiquement. Un numéro repris depuis par une autre fiche n'est
  pas restauré. Si la fiche absorbée n'est plus archivée par cette fusion, rien n'est restauré.
  Statuts : exécutée, annulée, annulée partiellement (éléments à traiter).
- **CONTACT-063** — Numéro secondaire dans toute l'application (11/11/2026, demandé à la revue du
  9b) : le numéro secondaire d'une fiche active ne peut devenir le numéro principal d'**aucune autre
  fiche du même type** de l'organisation. Règle portée par les modèles `Personne` et
  `EntrepriseTierce` (création et tout changement de numéro principal) : aucun formulaire ni
  parcours ne peut créer une nouvelle identité avec ce numéro — Client, Fournisseur, Prestataire,
  Propriétaire, Parrain, Livreur, Employé, membres d'une équipe de livraison, interlocuteur,
  utilisateur, invitation, import de flotte, conversion d'une proposition. **Jamais de
  rattachement automatique** : le numéro principal d'une fiche reste la seule clé de
  rapprochement (règle inchangée). Le message désigne la fiche (nom et fonctions) si l'utilisateur
  peut la voir, sinon il reste neutre (décision 1 du lot 3). Détection **avant** la saisie
  complète : vérification en direct du formulaire Client (bloquante), recherche « téléphone
  d'abord » de Nouvel employé (statut `secondaire`, lien vers la fiche), recherche du parrain
  (création impossible), contrôle en direct des membres d'équipe, recherche d'interlocuteur,
  analyse de l'import de flotte (erreur dès la prévisualisation). Une fiche peut reprendre son
  propre secondaire comme principal ; une fiche archivée ne bloque plus ; un numéro d'une autre
  organisation ne compte pas. **Exception métier** (numéro réattribué à quelqu'un d'autre) : aucune
  case « forcer » ; on retire d'abord ce numéro secondaire de la fiche existante (Contacts →
  Modifier, geste explicite et tracé), puis on crée la nouvelle identité. Fusion : un numéro
  retenu comme principal qui est le secondaire d'une troisième fiche bloque la simulation ;
  l'annulation ne remet pas un numéro devenu depuis le principal ou le secondaire d'une autre fiche.
  Non couvert (choix volontaire, non demandé) : enregistrer comme **secondaire** un numéro déjà
  porté par une autre fiche reste possible (numéro partagé d'une famille, par exemple).

## Écrans (lot 2)

| Route | Écran |
| --- | --- |
| `GET /backoffice/contacts/personnes` | Liste Personnes physiques : nom (repli sur la désignation du livreur, puis le téléphone), téléphone, localisation, badges de fonctions ; filtres fonction, recherche, sous-catégorie, ville ; 25 par page |
| `GET /backoffice/contacts/personnes/{personne}` | Fiche : Informations, Fonctions (lien vers l'écran existant de la fonction), Entreprises représentées |
| `GET /backoffice/contacts/entreprises` | Liste Entreprises : raison sociale, téléphone, ville, fonctions, interlocuteur principal, statut ; filtres statut, fonction, recherche, sous-catégorie, ville |
| `GET /backoffice/contacts/entreprises/{entreprise}` | Fiche : Informations, Fonctions, Interlocuteurs (lecture) |

Menu : « Entreprises » et « Personnes physiques » en tête de Contacts, visibles avec
`contacts.read` ; les cinq liens par fonction restent, sous l'intertitre « Par fonction » depuis le
lot 8 (CONTACT-050).

## Écrans et routes (lot 3)

| Route | Rôle |
| --- | --- |
| `GET /backoffice/contacts/nouveau?type=personne\|entreprise` | Parcours « Nouveau contact » |
| `GET /backoffice/contacts/recherche-telephone` | « Rechercher » : libre, connu ou indisponible (lecture seule) |
| `POST /backoffice/contacts/personnes`, `POST /backoffice/contacts/entreprises` | Création d'une fiche et de sa première fonction |
| `POST /backoffice/contacts/{personnes\|entreprises}/{id}/fonctions` | Ajout explicite d'une fonction à une fiche existante |

Boutons : « Nouveau contact » sur les deux listes, « Ajouter une fonction » sur les deux fiches,
affichés selon des indicateurs calculés par le serveur (`peut_creer`, `fonctions_ajoutables`).

## Routes (lot 4)

Préfixe `/backoffice/contacts/entreprises/{entreprise}/interlocuteurs`, liaison de route limitée à
l'entreprise (`scopeBindings`).

| Route | Rôle |
| --- | --- |
| `GET …/recherche-telephone` | « Rechercher » : libre, connu, déjà interlocuteur, indisponible ou invalide (lecture seule) |
| `POST …` | Ajout d'un interlocuteur (nouvelle personne, ou personne connue confirmée) |
| `PATCH …/{interlocuteur}` | Fonction dans l'entreprise et notes |
| `POST …/{interlocuteur}/principal` | Désigner comme principal |
| `POST …/{interlocuteur}/desactiver` | Désactiver (confirmation, lien conservé) |
| `POST …/{interlocuteur}/reactiver` | Réactiver (sans redevenir principal) |

Onglet Interlocuteurs de la fiche Entreprise : bouton « Ajouter un interlocuteur » et menu ⋮ par
ligne (Modifier la fonction et les notes, Définir comme principal, Désactiver / Réactiver).

## Routes et commande (lot 5)

| Route / commande | Rôle |
| --- | --- |
| `GET /backoffice/proprietaires/entreprises-correspondantes` | « Rechercher une entreprise existante » du formulaire Propriétaire : aucune, correspondances ou indisponible (lecture seule ; `proprietaires.create`, ou `proprietaires.update` sur le propriétaire désigné) |
| `POST /backoffice/proprietaires`, `PUT /backoffice/proprietaires/{proprietaire}` | Existantes : champs `telephone_entreprise`, `identifiant_fiscal`, `entreprise_choix` (id ou `nouvelle`), `confirmer_modification_entreprise` |
| `contacts:reprendre-proprietaires-entreprises` | Reprise et annulation (CONTACT-031, CONTACT-032) |

Formulaire Propriétaire : téléphone et NIF de l'entreprise, recherche et choix dans la carte
« Entreprise » existante ; aucune carte ajoutée.

## Routes (lot 7)

| Route / commande | Rôle |
| --- | --- |
| `PATCH /backoffice/contacts/personnes/{personne}`, `PATCH /backoffice/contacts/entreprises/{entreprise}` | Modification de l'identité d'une fiche (lot 7a, CONTACT-044) |
| `contacts:ecarts-clients` | Rapport, arbitrage, annulation et activation de la synchronisation (lot 7a, CONTACT-045, CONTACT-046) |
| `GET /backoffice/clients/entreprises-correspondantes` | Recherche des entreprises du formulaire Client (lot 7b ; `clients.create`, ou `clients.update` sur le client désigné par `client`) |
| `POST /backoffice/clients`, `PUT /backoffice/clients/{client}` | Existantes : champs `client_professionnel`, `raison_sociale`, `telephone_entreprise`, `identifiant_fiscal`, `entreprise_choix`, `confirmer_modification_entreprise` |

Formulaire Client : case et champs de l'entreprise dans la carte « Identité » existante (composant
partagé `components/contacts/RattachementEntreprise.vue`, aussi utilisé par le formulaire
Propriétaire) ; aucune carte ajoutée.

## Routes (lot 8)

Aucune route ajoutée, aucune migration.

| Route | Changement |
| --- | --- |
| `GET /backoffice/search/global` | Groupe « Contacts » regroupé (CONTACT-051) ; résultats avec `href` et `badges` |
| `GET /api/v1/search/global` | Inchangée (groupes historiques) ; le groupe « Contacts » y porte aussi `href` et `badges` |
| `GET /backoffice/contacts/nouveau` | Paramètre `fonction` (présélection) ; props `fonction`, `sous_categories_fournisseur` |
| `POST /backoffice/contacts/{personnes\|entreprises}`, `POST …/{id}/fonctions` | Champ `sous_categorie_ids[]` pour un fournisseur |

## Commande (lot 9a)

| Commande | Rôle |
| --- | --- |
| `contacts:doublons --acteur=<id> [--export=<fichier.csv>]` | Doublons probables d'entreprises et blocage F1, en lecture seule (CONTACT-053, CONTACT-054) |

## Écrans et routes (lot 9b)

| Route | Rôle |
| --- | --- |
| `GET /backoffice/contacts/fusion/{personne\|entreprise}/{fiche}` | Écran de fusion depuis la fiche A : `recherche`, `b`, `choix[...]` ; simulation sans écriture |
| `POST /backoffice/contacts/fusions` | Confirmation : `type`, `fiche_a`, `fiche_b`, `choix`, `confirmations`, `empreinte`, `jeton` |
| `GET /backoffice/contacts/fusions` | Historique des fusions de l'organisation |
| `GET /backoffice/contacts/fusions/{fusion}` | Détail, journal, ce que l'annulation restaurerait et ce qui demande une intervention |
| `POST /backoffice/contacts/fusions/{fusion}/annulation` | Annulation sûre |
| `GET /backoffice/contacts/{personnes\|entreprises}/{id}` (existante) | Une fiche absorbée redirige vers la fiche conservée |

Fiches Personne et Entreprise : bouton « Fusionner ». Listes : bouton « Historique des fusions ».
Les deux avec `contacts.fusionner` seulement.

## Routes (lot 6a)

| Route | Rôle |
| --- | --- |
| `GET /backoffice/contacts/categories` | Écran « Catégories de contacts » |
| `POST …/categories/sous-categories`, `PATCH …/categories/sous-categories/{sousCategorie}` | Créer ; renommer, désactiver, réactiver (fournisseur, non système) |
| `POST …/categories/libres`, `PATCH …/categories/libres/{categorieLibre}` | Créer ; renommer, désactiver, réactiver une catégorie libre |
| `POST …/categories/ordre` | Nouvel ordre d'une liste (`libres` ou `fournisseur`) |
| `PUT /backoffice/contacts/{personnes\|entreprises}/{id}/categories` | Catégories libres d'une fiche (`contacts.update`, fiche visible) |
| `POST`/`PUT /backoffice/fournisseurs` (existantes) | Champ `sous_categorie_ids[]` |

Lot 6b : formulaire Prestataire et création depuis Contacts — champ « Qualifications » (choix
multiple, `qualification_ids[]`) ; liste des prestataires — un badge par qualification.

Menu Contacts : lien « Catégories de contacts » (avec `contacts.gerer_categories`). Fiches
Personne et Entreprise : bloc « Catégories » dans l'onglet Informations. Listes : filtre
« Catégorie ».

## Permissions

| Permission | Effet |
| --- | --- |
| `contacts.read` | Consulter les fiches Personne et Entreprise |
| `contacts.create` | Créer une fiche |
| `contacts.update` | Modifier l'identité (s'applique à toutes les fonctions de la fiche) ; attribuer les catégories libres d'une fiche (lot 6a) |
| `contacts.gerer_categories` | Consulter et paramétrer les sous-catégories et les catégories libres (écran « Catégories de contacts », lot 6a) |
| `contacts.gerer_interlocuteurs` | Ajouter, modifier, désigner, désactiver, réactiver un interlocuteur (lot 4, avec l'entreprise visible) |
| `contacts.fusionner` | Fusionner deux fiches et annuler une fusion (lot 9b, avec `contacts.read` ; `users.update` en plus quand une fiche a un compte). Attribuée à **aucun rôle** par défaut (F4) : seul `super_admin` l'a, comme toute permission |

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
  `ClientIdentiteSynchronisation` (branché au lot 7a), `VisibiliteContacts` (source unique de la
  visibilité des listes, fiches et recherche — lot 2).
- Création (lot 3) : services `CreationContact`, `FonctionsContactEcriture`,
  `RechercheTelephoneContact` ; requêtes `App\Http\Requests\Contacts\*` ; règle D17
  `App\Support\Contacts\SuppressionFonction` ; messages `MessagesContacts` ; reprise silencieuse
  retirée de `ResolutionIdentiteTiersTrait` ; page `Contacts/Creer.vue`.
- Écrans (lot 2) : contrôleurs `App\Http\Controllers\Contacts\{Personnes,Entreprises}\*`,
  policies `PersonnePolicy`, `EntrepriseTiercePolicy`, présentation
  `App\Support\Contacts\PresentationContacts`, recherche `ContactSearchProvider`, pages
  `resources/js/pages/Contacts/`.
- Interlocuteurs (lot 4) : contrôleurs `App\Http\Controllers\Contacts\Interlocuteurs\*` (contrôle
  commun `ControleAccesInterlocuteurs`), requêtes `StoreInterlocuteurRequest`,
  `UpdateInterlocuteurRequest`, policy `EntrepriseTiercePolicy::gererInterlocuteurs`, recherche
  `RechercheTelephoneContact::rechercherInterlocuteur`, journal dans `InterlocuteurService`,
  dialogues `AjouterInterlocuteurDialog.vue`, `ModifierInterlocuteurDialog.vue`.
- Propriétaire entreprise (lot 5) : service `App\Services\Contacts\ProprietaireEntreprise`
  (correspondances, rattachement, D7, lien), `InterlocuteurService::rattacherContact()` et
  `retirerPrincipal()`, synchronisation de la raison sociale dans `EntrepriseTierce::booted()`,
  contrôleur `App\Http\Controllers\Proprietaires\EntreprisesCorrespondantesController`, reprise
  `RepriseProprietairesEntreprises` et commande `ContactsReprendreProprietairesEntreprisesCommand`,
  modèles `ContactsReprise`, `ContactsRepriseLigne`, formulaire
  `Proprietaires/partials/ProprietaireForm.vue`.
- Catégories (lot 6a) : service `App\Services\Contacts\ReferentielCategories` (référentiel,
  attribution, sous-catégories fournisseur), modèles `CategorieLibre` (table `contact_categories`),
  `CategorieLibreAttribution` (liaisons), relations `Personne::categoriesLibres()`,
  `EntrepriseTierce::categoriesLibres()`, initialisation `CategoriesLibresParDefaut`, policy
  `CategorieLibrePolicy`, contrôleurs `App\Http\Controllers\Contacts\Categories\*`, page
  `Contacts/Categories/Index.vue`, bloc `partials/BlocCategories.vue`.
- Qualifications (lot 6b) : service `App\Services\Contacts\QualificationsPrestataire` (saisie, Q1,
  D15, synchronisation de `type`), critère `Prestataire::qualifieConsultant()` /
  `scopeQualifiesConsultant()` / `conditionQualifieConsultant()`, migrations `2026_11_09_100000`
  (`type` facultatif) et `2026_11_09_100100` (reprise).
- Identité client (lot 7a) : `ClientIdentiteSynchronisation` (activation, `apresCreation()`),
  recopie dans `Personne::booted()`, `EcartsIdentiteClients` et commande
  `ContactsEcartsClientsCommand`, `ModificationIdentiteContact`, requêtes
  `UpdatePersonneContactRequest` / `UpdateEntrepriseContactRequest`, contrôleurs
  `UpdatePersonneController` / `UpdateEntrepriseController`, dialogue `ModifierIdentiteDialog.vue`,
  modèle `ContactsRepriseValeur`, migration `2026_11_09_220000`.
- Doublons (lot 9a) : service `App\Services\Contacts\DetectionDoublons`, commande
  `ContactsDoublonsCommand`.
- Fusion (lot 9b) : services `App\Services\Contacts\Fusion\FusionContacts` (simulation,
  blocages, exécution), `AnnulationFusionContacts`, `ReferencesFusion` (registre des liens, comparé
  au schéma par `ReferencesFusionTest`), `JournalFusion` ; modèles `ContactsFusion`,
  `ContactsFusionOperation` ; contrôleurs `App\Http\Controllers\Contacts\Fusions\*` ;
  redirection `App\Support\Contacts\RedirectionFusion` ; pages `Contacts/Fusion.vue`,
  `Contacts/Fusions/Index.vue`, `Contacts/Fusions/Show.vue` ; migration `2026_11_11_120000`
  (`fusionnee_dans_id`, `fusionnee_le`, `telephone_secondaire_normalise`, tables de journal).
- Numéro secondaire (CONTACT-063) : `App\Support\Contacts\NumeroSecondaire` (garde des modèles
  `Personne` et `EntrepriseTierce`, présentation selon la visibilité), branchée sur
  `TelephoneOwnerLookupService`, `RechercheEmployeParTelephone`, `RechercherTelephoneParrainController`,
  `EquipeLivraisonController::detecterConflitTelephone`, `RechercheTelephoneContact`,
  `ResolutionIdentiteTiersTrait` (champ `phone`) et `ImportFlotteParser` ; tests
  `NumeroSecondaireApplicationTest`, `ImportFlotteTest`.
- Défaut connu des tests (constaté le 11/11/2026, non corrigé, sans effet en production) :
  `LivreurFactory::create(['personne_id' => …])` ignore la Personne fournie et en crée une
  nouvelle. Cause : avec des attributs, `parent::create()` rappelle la méthode avec un tableau vide,
  et le verrou de réentrance n'est posé que lorsque `personne_id` est absent. Impact : un test qui
  croit relier un livreur à une Personne existante teste en réalité une autre fiche (faux négatif
  possible sur les blocages par fonction, la visibilité ou la fusion). Contournement utilisé par
  les tests du lot 9b : créer le livreur, puis poser `personne_id` par une mise à jour directe.
- Client professionnel (lot 7b) : `ProprietaireEntreprise` étendu au client (`Proprietaire|Client`),
  règles de saisie `App\Http\Controllers\Concerns\SaisieEntrepriseLiee`, relations
  `Client::entrepriseTierce()` / `EntrepriseTierce::clients()`, migration `2026_11_09_230000`,
  composant `components/contacts/RattachementEntreprise.vue`.
- Tests : `tests/Feature/Contacts/`.
