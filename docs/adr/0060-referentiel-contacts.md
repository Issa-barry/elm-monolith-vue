# ADR 0060 — Référentiel Contacts : deux fiches d'identité, des fonctions qui gardent leurs ids

- Statut : accepté (architecture validée le 06/11/2026) ; lot 1 réalisé le 06/11/2026 (commit
  `35abb314`) ; lot 2 réalisé le 07/11/2026
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

## Conséquences

- Aucune entrée ajoutée au morph map : les comptes auxiliaires stockent le nom de classe des
  fonctions, un alias créerait des comptes en double.
- L'unicité du principal est garantie par le service (transaction + verrou), pas par un index, faute
  d'index unique partiel portable entre SQLite, MySQL et MariaDB.
- Tant que le lot 7 n'est pas livré, l'identité d'un client s'écrit comme avant (CLIENTPERSONNE-002).

## Alternatives écartées

- Table `contacts` unique remplaçant les fonctions : réécriture des clés de plus de vingt tables et
  des valeurs polymorphes, risque comptable élevé.
- Fusion de `personnes` et `entreprises_tierces` : `personnes` sert aussi aux comptes, employés et
  livreurs.
- Interlocuteur avec ses propres colonnes : recrée les doublons et casse le rattachement de compte.
- Consultant obligatoirement sous-catégorie principale : limitait artificiellement les qualifications
  (remplacé par 7 bis).
