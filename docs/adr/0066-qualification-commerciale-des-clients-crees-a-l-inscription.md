# ADR 0066 — Qualification commerciale des clients créés à l'inscription

- **Date** : 2026-11-16
- **Statut** : accepté le 2026-11-16 (décisions de l'utilisateur pendant le chantier)
- **Complète** : [ADR 0055](0055-compte-unique-back-office-espace-client.md) (front ouvert à tous) et la
  règle CLIENTPERSONNE-006 ([identite-client-personne.md](../identite-client-personne.md))
- **Détail fonctionnel** : [docs/identite-client-personne.md](../identite-client-personne.md),
  § Qualification commerciale

## Contexte

La commande VTE-151126-080 (véhicule YOUSSOUF, 100 packs de 25 sachets) a été facturée au tarif
externe (5 150 GNF) au lieu du prix de vente. Le client choisi était une fiche créée
automatiquement le 24/09/2026 à l'inscription d'un livreur.

Depuis avril 2026, quand un livreur ou un propriétaire crée son compte, `RegistrationService` et
`CreateNewUser` lui créent une fiche Client dans son organisation, avec la nature Externe « par
défaut ». Cette fiche sert à l'espace client (identité, profil, consultation des commandes). Mais
elle apparaissait aussi dans les ventes comme un vrai client Externe. Pour un produit fabricable, la
nature du client fixe le prix, avant le véhicule (`PrixVenteNatureResolver`). Le 16/11/2026, la
production comptait 27 fiches de ce type, presque toutes créées après l'ouverture du front à tous
(ADR 0055).

La refonte Contacts (ADR 0060) n'est pas en cause : elle n'a créé aucun client et n'a modifié aucun
calcul de prix.

Une inscription crée une identité et un accès à l'application, pas un client commercial qualifié.

## Décision

1. **État distinct de la nature** : deux colonnes `clients.qualifie_le` et `clients.qualifie_par`.
   Aucune nouvelle nature tarifaire, aucune nature modifiée : `clients.type` reste la seule source
   des prix, des commissions et du cashback. Un client est **qualifié** quand `qualifie_le` est
   renseigné.
2. **Création** : un client créé au back-office (ou par import, seed…) est qualifié à sa création,
   par l'utilisateur connecté. La fiche créée à l'inscription d'un livreur ou d'un propriétaire
   (`Client::creerNonQualifie()`) ne l'est pas : elle garde la nature Externe en base, sans effet
   tant qu'elle n'est pas qualifiée. Un client existant rattaché à l'inscription reste qualifié.
3. **Ventes** : un client non qualifié n'est jamais proposé (formulaire de vente, précommande, PDV)
   et le serveur le refuse (`ClientUtilisableEnVente`). Exception : le client déjà porté par une
   commande modifiée reste accepté tant qu'il n'est pas remplacé. Prix et historique des commandes
   existantes inchangés.
4. **Organisation** : la même règle refuse un client d'une autre organisation, même qualifié, à la
   création et à la modification des commandes, des précommandes et des ventes PDV, ainsi que dans
   l'aperçu de solvabilité.
5. **Qualification** : permission dédiée `clients.qualifier` (« Clients — qualifier un client créé à
   l'inscription »), indépendante de la modification d'une fiche. Action « Qualifier » de la fiche
   client : choix de la nature et du cashback. Nature, cashback, `qualifie_le` et `qualifie_par`
   sont enregistrés en une seule écriture, sous verrou ; un client déjà qualifié n'est pas
   requalifié. Règles de cashback inchangées (Revendeur obligatoirement éligible avec un montant).
   La simple modification de la fiche ne peut pas changer la nature d'un client non qualifié.
   **Changer la nature d'un client déjà qualifié exige aussi `clients.qualifier`** (en plus de la
   modification, qui reste suffisante pour les coordonnées) : la nature fixe les prix et le cashback.
   Le changement enregistre une requalification (`qualifie_le`, `qualifie_par`) dans la même
   écriture ; sans la permission, le champ Nature est verrouillé à l'écran et le serveur répond 403.
6. **Affichage** : mention « À qualifier » (point ambre) dans la liste des clients ; message
   explicatif sur la fiche, avec l'action « Qualifier » réservée à la permission dédiée.
7. **Clients existants** (migration réversible) : qualifiés seulement s'ils sont certainement saisis
   au back-office — sans compte, ou créés plus de 2 minutes avant l'ouverture du compte avec un nom
   saisi (`nom_complet`, obligatoire au back-office). Tous les autres restent non qualifiés, y compris
   ceux qui ont déjà des commandes : une ancienne vente ne vaut pas qualification. `qualifie_le` reprend
   la date de création, `qualifie_par` reste vide (auteur inconnu).
8. **Valeur de départ de la permission** : accordée une fois à `super_admin` et `admin_entreprise`
   (migration de données) ; les autres rôles se règlent dans Rôles → Clients & Contacts.

## Conséquences

- Les fiches créées à l'inscription disparaissent des listes de vente jusqu'à leur qualification.
  Les ventes en cours de saisie qui les utilisent doivent être qualifiées d'abord.
- Les commandes passées avec ces fiches gardent leur prix. Leur éventuelle régularisation relève du
  métier, au cas par cas (ADR 0043).
- L'espace client et l'application mobile ne changent pas : la fiche reste créée et rattachée.
- Le catalogue gagne une permission (256).
- La création d'un client (permission de création) choisit sa nature et le qualifie : inchangé.
- Hors périmètre, à traiter dans un lot distinct : le véhicule (`vehicule_id`) n'est pas borné à
  l'organisation par la validation des ventes (`exists:vehicules,id`). Contrôles existants partiels :
  la précommande le borne ; pour une distribution client, `ensureNatureOperationCoherente()` refuse
  un véhicule d'une autre organisation ; ailleurs (vente standard, PDV), le véhicule est seulement
  ignoré pour les contrôles (`resolveVehiculeAvecEquipe()` filtre l'organisation) mais son
  identifiant serait enregistré sur la commande, et `VehiculeCommandeContextResolver` le charge
  sans filtre d'organisation.

## Vérification

`tests/Feature/Clients/QualificationClientTest.php` (création qualifiée, qualification atomique,
cashback Revendeur, permission dédiée, autre organisation, requalification refusée, nature non
modifiable par la fiche, changement de nature d'un client qualifié avec et sans la permission, liste
et fiche, sélecteurs de vente et PDV, refus en vente, au PDV et pour
une autre organisation, commande existante modifiable, tarification après qualification, vente sans
client) ; `tests/Feature/Clients/RepriseQualificationClientsTest.php` (reprise des clients existants) ;
`tests/Feature/Ventes/PrecommandeCreationTest.php` (précommande refusée) ;
`tests/Feature/Auth/ApiRegistrationTest.php`, `tests/Feature/CreateNewUserTest.php` (inscriptions) ;
`resources/js/pages/Clients/__tests__/Show.spec.ts` (message et action).
