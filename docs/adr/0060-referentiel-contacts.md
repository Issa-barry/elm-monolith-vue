# ADR 0060 — Référentiel Contacts : deux fiches d'identité, des fonctions qui gardent leurs ids

- Statut : accepté (architecture validée le 06/11/2026) ; lot 1 réalisé le 06/11/2026 (commit
  `35abb314`) ; lot 2 réalisé le 07/11/2026 (commit `d7a1effb`) ; lot 3 réalisé le 07/11/2026
  (commit `ae3f8842`) ; lot 4 réalisé le 07/11/2026 (commit `48234f04`) ; lot 5 : décisions
  validées le 07/11/2026, plan technique validé et réalisé le 08/11/2026 (commit `d2c763e3`) ;
  lot 6a réalisé le 08/11/2026 (commit `f3e1508b`) ; lot 6b réalisé le 09/11/2026.
- Documentation : [docs/contacts.md](../contacts.md)

## Contexte

Le module Contacts présentait cinq fiches séparées (client, fournisseur, prestataire, propriétaire,
parrain). L'audit a montré que l'identité est déjà largement centralisée : `personnes` et
`entreprises_tierces` portent l'identité de la plupart des fonctions. Les fonctions sont référencées
par leur id dans plus de vingt tables (ventes, achats, commissions, dépenses, fiches de paiement,
comptes auxiliaires), parfois en polymorphe. En production (06/11/2026, audit partiel), 34 personnes
cumulent déjà plusieurs fonctions et aucun doublon certain n'a été trouvé.

## Décision

1. **Deux fiches d'identité** : Personne physique et Entreprise. Pas de table `contacts` unique qui
   remplacerait les fonctions.
2. **Les fonctions restent les tables existantes et gardent leurs ids** ; la catégorie se déduit de
   la fonction. Une fonction par catégorie et par contact ; plusieurs sous-catégories possibles dans
   cette fonction.
3. **R1 — la fiche Contact fait foi pour l'identité.** Les colonnes d'identité de `clients` sont
   conservées et alimentées uniquement depuis la fiche, dans une transaction. Branchement au lot 7,
   après le rapport des écarts Client/Personne et son arbitrage (D16) ; il inversera alors
   CLIENTPERSONNE-002.
4. **R2** — un client ou un propriétaire pourra être rattaché à une Entreprise (colonne ajoutée aux
   lots 5 et 7), sans changer `client_id` ni `proprietaire_id`.
5. **R4 — rapprochement par téléphone** : confirmation explicite avant tout rattachement au
   back-office et pour les interlocuteurs ; imports validés avant application ; inscription et ADR 0056
   inchangés.
6. **R5** — menu Contacts : Entreprises, Personnes physiques, Catégories et sous-catégories.
7. **Interlocuteur = Personne du référentiel**, téléphone obligatoire, plusieurs entreprises
   possibles, un seul principal actif, désactivation sans perte d'historique.
8. **Sous-catégories** : référentiel par organisation, codes stables. Les quatre natures client
   (Externe, Revendeur, Distributeur, Grossiste) et Consultant sont des sous-catégories système, non
   désactivables depuis les Paramètres (D18) ; leurs règles de prix, de PDV et de commission ne
   changent pas.
9. **7 bis** — qualifications égales sans principale ; l'éligibilité aux commissions reste la
   désignation existante (barème, affectation) + qualification Consultant (lot 6).
10. **D12 à D14** — la nature du client sera enregistrée sur chaque nouvelle commande ; les
    anciennes gardent le comportement actuel ; un changement de nature ne vaut que pour les nouvelles
    commandes (lot 7).
11. **D15** — retrait de Consultant refusé tant qu'une affectation ou un barème actif le désigne.
12. **D17** — une fonction qui a un historique se désactive ; sa suppression est refusée dès qu'une
    opération la référence (lot 3).
13. Pas de `contacts.delete` ; permissions `contacts.read`, `.create`, `.update`,
    `.gerer_categories`, `.gerer_interlocuteurs`.

## Lot 1 (socle, 06/11/2026)

Colonnes de coordonnées, interlocuteurs enrichis et leur service, référentiel des sous-catégories
avec ses tables de liaison (vides), permissions, service de lecture des fonctions, service de
synchronisation client construit et non branché. Aucun écran, aucune route, aucune ligne existante
modifiée.

## Lot 2 (consultation, 07/11/2026)

Listes et fiches Personnes physiques / Entreprises, groupe « Contacts » de la recherche globale,
liens de menu visibles avec `contacts.read` — lecture seule. Décisions du 07/11 : liens dès ce lot ;
personnes sans fonction exclues ; doublon temporaire « Clients » / « Contacts » dans la recherche
accepté jusqu'au lot 8. La visibilité (fonctions lisibles, périmètre d'agences des parrains,
organisation) est décidée par un seul service côté serveur et vérifiée dans les contrôleurs en plus
des policies, `Gate::before` accordant tout au super_admin.

## Lot 3 (création, 07/11/2026)

Parcours « Nouveau contact » (type, téléphone puis « Rechercher », identité, première fonction),
ajout explicite d'une fonction à une fiche existante, une fonction par catégorie sous verrou, fin
de la reprise silencieuse dans les formulaires Fournisseur et Prestataire, et D17 sur les quatre
écrans Client, Fournisseur, Prestataire, Propriétaire. Décisions du 07/11 : message neutre et
arrêt pour un numéro d'une fiche non visible ; Fournisseur et Prestataire seuls créables ; D17 dès
ce lot ; « autre personne » arrête sans retirer ni réaffecter le numéro ; reprise silencieuse
traitée seulement pour Fournisseur et Prestataire ; modification d'identité au lot 7. Aucune
migration.

## Lot 4 (interlocuteurs, 07/11/2026)

Gestion des interlocuteurs depuis l'onglet Interlocuteurs de la fiche Entreprise : ajout
« téléphone d'abord », fonction et notes, désignation du principal, désactivation, réactivation
(CONTACT-021 à CONTACT-026). Décisions du 07/11 : (1) personne connue non visible → message neutre
et arrêt, appliqué aussi à l'enregistrement (identifiant forgé compris) ; (2) modification limitée à
la fonction et aux notes, coordonnées au lot 7 ; (3) journal d'audit existant, sans donnée
personnelle ; (4) accès aux commissions hors lot 4, ni affiché ni modifié ; (5) entreprise
désactivée → ajout interdit, gestion des liens existants autorisée ; (6) case « principal » cochée
par défaut seulement s'il n'y a aucun principal actif, choix explicite. Contrôle serveur à chaque
requête : permission `contacts.gerer_interlocuteurs`, entreprise visible (404 sinon, super_admin
compris), interlocuteur lié à l'entreprise de l'URL (`scopeBindings`). Rattachement de compte
d'ADR 0056 inchangé. Aucune migration.

## Lot 5 (propriétaire entreprise, 08/11/2026)

Périmètre : les propriétaires de type entreprise seulement, reliés à une fiche Entreprise par une
colonne `proprietaires.entreprise_tierce_id` ajoutée ; aucun propriétaire entreprise en production à
l'audit du 06/11. Décisions : (D1) `personne_id` reste le contact du propriétaire (téléphone, compte,
espace client) ; (D2) téléphone de l'entreprise facultatif, jamais recopié de celui du contact ;
(D3) l'écran Propriétaire actuel crée ou relie l'entreprise en arrière-plan ; (D4) propriétaire
personne physique inchangé ; (D5) `proprietaires.raison_sociale` conservée et synchronisée, la fiche
Entreprise faisant foi dès que le lien est posé. Garde-fous : aucun rattachement automatique par NIF
ou raison sociale (l'entreprise trouvée est présentée et confirmée, l'utilisateur arbitre entre
plusieurs) ; jamais de fusion ; le contact devient interlocuteur sans doublon de lien, les autres
interlocuteurs sont conservés ; reprise idempotente (`--dry-run`, `--apply`, valeurs d'avant
journalisées, rapport d'anomalies) ; ids de propriétaire, comptes auxiliaires, commissions, fiches de
paiement et contrats d'API inchangés. Démarrage après stabilisation des travaux parallèles sur les
commissions.

Décisions complémentaires : (D6) téléphone ou NIF d'une entreprise non visible du lecteur → message
neutre et arrêt, rien n'est révélé ; une raison sociale seule ne révèle pas son existence ; (D7)
renommer depuis l'écran Propriétaire une entreprise qui porte d'autres fonctions → avertissement et
confirmation explicite, qui ne remplacent pas le droit de modifier l'identité de l'entreprise ;
(D8) passage d'entreprise à personne physique autorisé : le lien est retiré et journalisé,
l'entreprise et ses interlocuteurs sont conservés ; (D9) le contact devient principal seulement si
l'entreprise n'en a aucun, sinon il est ajouté sans rôle de principal. Les rattachements de la
reprise restent réversibles ; aucune identité n'est supprimée.

Plan technique validé le 08/11/2026, après le commit de la session commissions (`1b58e68e`, sans
incompatibilité) : (D7) `proprietaires.update` seule si l'entreprise ne porte que la fonction
Propriétaire ; `contacts.update` en plus, avec confirmation, dès qu'elle en porte une autre ; sans
le droit, l'identité de l'entreprise est en lecture seule ; (D10) la fiche Personne du contact garde
la fonction Propriétaire avec la mention « au nom de » l'entreprise et un lien vers sa fiche ;
le rattachement du contact comme interlocuteur, dans le cadre du propriétaire, passe par
`InterlocuteurService` sans exiger `contacts.gerer_interlocuteurs` ; téléphone et NIF de
l'entreprise facultatifs dans le formulaire Propriétaire ; une entreprise créée depuis le
formulaire ou par la reprise ne reprend ni le téléphone ni la localisation du contact (l'adresse
d'une personne n'est pas celle de son entreprise), sans synchronisation ultérieure. Réversibilité : chaque exécution appliquée
de la reprise a un identifiant (`contacts_reprises`) et journalise ses valeurs d'avant par ligne
(`contacts_reprise_lignes`) ; l'annulation vérifie que le rattachement n'a pas changé depuis
(sinon « conflit »), désactive sans supprimer les interlocuteurs qu'elle avait créés et conserve les
entreprises créées, même sans fonction. La raison sociale de l'entreprise est recopiée sur le
propriétaire relié quel que soit l'écran qui la modifie (Fournisseur et Prestataire compris). Règles
CONTACT-027 à CONTACT-032.

## Lot 6 (paramétrage) — découpage et lot 6a (08/11/2026)

Décisions du 08/11 : (A) découpage en 6a (paramétrage, sans effet métier) puis 6b
(qualifications Prestataire, `qualifieConsultant()`, D15) ; (B, 6b) `prestataires.type` rendu
facultatif, anciennes valeurs conservées, qualifications multiples sans priorité ; (C) sous-catégories
système en lecture seule — code, libellé et activation — tant que ventes, PDV et factures ne lisent
pas le référentiel (D18 interdit la désactivation, il n'autorise pas le renommage) ; (D) catégories
libres incluses au 6a, avec bloc sur les fiches et filtre, attribution par `contacts.update` ;
(E, 6b) reprise des qualifications par migration au déploiement, avant la bascule des lectures ;
(F) écran dans le menu Contacts, « Catégories de contacts » ; (G) « Partenaire » créée pour chaque
organisation existante et nouvelle, idempotente ; (H) au 6a, seules les sous-catégories Fournisseur
et les catégories libres s'administrent ; (I) un contact sans fonction reste hors périmètre, les
catégories libres ne s'attribuent qu'aux fiches visibles. Codes stables après renommage ; libellé
unique sans distinction de casse. Règles CONTACT-033 à CONTACT-037 ; CONTACT-009 modifiée.

Lot 6b (09/11/2026) : qualifications multiples des prestataires, table de liaison source de vérité ;
critère unique `Prestataire::qualifieConsultant()` pour les dix lectures de la qualification
Consultant (seule la qualification système ouvre l'éligibilité, aucune règle de calcul ni barème
modifié) ; (Q1) au moins une qualification active ; (Q2) repli transitoire sur l'ancien `type` pour un
prestataire sans qualification enregistrée, le temps de la reprise ; (Q3) sous-catégories Prestataire
administrables, Consultant protégé ; (Q4) D15 limité au retrait de Consultant (affectation active ou
barème actif, sous verrou, super_admin compris), désactivation du prestataire inchangée ; (Q5)
`type` facultatif, copie de compatibilité sans priorité à Consultant. Reprise par migration de
données au déploiement, rejouable, sans perte. Règles CONTACT-038 à CONTACT-041.

## Conséquences

- Aucune entrée ajoutée au morph map : les comptes auxiliaires stockent le nom de classe des
  fonctions, un alias créerait des comptes en double.
- L'unicité du principal est garantie par le service (transaction + verrou), pas par un index, faute
  d'index unique partiel portable entre SQLite, MySQL et MariaDB. Même principe pour « un seul
  propriétaire par entreprise » (lot 5) : les propriétaires supprimés logiquement empêchent un index.
- Tant que le lot 7 n'est pas livré, l'identité d'un client s'écrit comme avant (CLIENTPERSONNE-002).

## Alternatives écartées

- Table `contacts` unique remplaçant les fonctions : réécriture des clés de plus de vingt tables et
  des valeurs polymorphes, risque comptable élevé.
- Fusion de `personnes` et `entreprises_tierces` : `personnes` sert aussi aux comptes, employés et
  livreurs.
- Interlocuteur avec ses propres colonnes : recrée les doublons et casse le rattachement de compte.
- Consultant obligatoirement sous-catégorie principale : limitait artificiellement les qualifications
  (remplacé par 7 bis).
