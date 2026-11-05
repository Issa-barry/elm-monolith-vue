# ADR 0055 — Compte unique et front Nuxt ouvert à tous

- **Date** : 2026-11-04
- **Statut** : accepté (décision de l'utilisateur du 04/11/2026)
- **Révise** : [ADR 0050](0050-rattachement-manuel-comptes-parrain-consultant.md), décision 7 et conséquence
  « compte du staff refusé au rattachement »
- **Périmètre** : `elm-monolithe` (API de l'espace client, `/api/auth/me`, rattachement des comptes) et
  `elm-vitrine-nuxt` (connexion, garde des pages, accueil)

## Règle

« Le front Nuxt est un espace ouvert à tous les utilisateurs. Toute personne peut le consulter. Tout
utilisateur disposant d'un compte actif peut s'y connecter avec son identité EAU LA MAMAN existante, quels
que soient ses rôles et ses activités métier. L'absence d'activité n'est jamais un motif de refus d'accès
au front. Seules les données et fonctionnalités métier restent conditionnées par les rattachements et
autorisations propres à chaque utilisateur. »

Un utilisateur EAU LA MAMAN possède une identité unique : le même compte sert au back-office et au front.

## Contexte

Des agents du back-office sont aussi propriétaires, parrains, consultants ou anciens livreurs ; d'autres
n'ont aucune activité. Tous se connectent au back-office par téléphone et mot de passe
(`user_auth_identities`, même table que l'API). Sur Nuxt, la connexion réussissait côté Laravel, puis le
compte était refusé :

1. Nuxt (`hasClientSpaceAccess`) n'acceptait que les rôles `client`, `proprietaire` ou `livreur`, se
   déconnectait aussitôt et affichait « Ce compte n'a pas accès à l'espace client » ;
2. les routes de l'espace client (`role:client|proprietaire|livreur`) répondaient 403 pour la même raison ;
3. un compte staff ne pouvait pas être rattaché à un parrain ou à un consultant (ADR 0050, décision 7).

## Décision

1. **Aucun second compte** : pas de nouvelle table de comptes, pas de compte créé à la première connexion
   sur Nuxt. L'inscription, la connexion, le code OTP et la récupération de compte ne changent pas.
2. **Accès universel** : aucune garde globale par rôle ni par activité sur les routes API de l'espace
   client (`auth:sanctum` et le filet `EnsureApiAccountIsActive` seulement). Nuxt n'exige qu'une session
   valide. Les rôles back-office n'ouvrent rien et ne ferment rien ; ils ne sont jamais modifiés.
3. **Les données restent celles des activités** : chaque endpoint lit ses données depuis les activités du
   compte (`ClientIdentityResolver`, `ActivitesCompteResolver`, `BeneficiairesCompte`). Sans activité :
   listes et totaux vides, ou `404` pour une ressource rattachée (profil à modifier, espace parrain,
   commande, frais d'un véhicule). Jamais la donnée d'un autre compte.
4. **Aucun droit back-office exposé** : `/api/auth/me` renvoie les noms de rôles du compte, jamais ses
   permissions ; aucune donnée RH, de trésorerie interne ou d'un autre utilisateur.
5. **Accueil neutre** : un compte sans activité (tous les ids de `context` à `null`, `consultant_ids` vide)
   arrive sur `/espace-client/bienvenue`, sans menu métier, avec un message qui l'explique. Dès qu'une
   activité est rattachée, il retrouve l'accueil habituel. Les menus viennent toujours de `context`.
6. **Ancien livreur** : ses commissions se lisent par bénéficiaire (`livreur_id`), sans condition
   d'équipe : il les garde. Le véhicule, l'équipe et les livraisons en cours se lisent par l'appartenance
   actuelle à une équipe : il ne les retrouve pas après son départ.
7. **Rattachement d'un compte agent** : `RattachementCompteService` ne refuse plus un compte sans rôle
   externe. Les autres refus de l'ADR 0050 restent. Le contrôle humain du responsable reste la protection
   des commissions.
8. **Compte désactivé** : refusé partout, comme avant (connexion, `EnsureApiAccountIsActive`).

## Conséquences

- Tout agent se connecte à Nuxt avec ses identifiants du back-office.
- Les applications mobiles utilisent les mêmes routes et acceptent aussi ces comptes ; le contrat OpenAPI
  ne documente plus de `403` « rôle manquant » sur ces routes.
- Un compte staff dont le numéro figure sur une fiche livreur ou propriétaire non réclamée de son
  organisation est relié à cette fiche à la connexion API (`lierCompteParTelephone`, comportement
  existant) et peut lire les données de cette fiche par le repli téléphone de `ClientIdentityResolver`.
  Ce comportement existait déjà pour les comptes porteurs d'un rôle externe ; il s'étend aux agents. Un
  compte rattaché à une organisation n'est jamais relié à la fiche d'une autre organisation (test :
  `test_login_ne_relie_jamais_une_fiche_d_une_autre_organisation`).
- L'espace client Inertia historique (`routes/web.php`, `role:client|proprietaire|livreur`) n'est pas
  concerné.

## Options écartées

- **Accès réservé aux comptes ayant une activité rattachée** (première version de cette décision, le même
  jour) : refusait encore les agents sans activité, contraire à la règle d'un front ouvert à tous.
- **Ajouter le rôle `client` aux agents** : modifie les rôles pour donner un accès.
- **Créer un compte front à la première connexion** : deux comptes, deux mots de passe pour une personne.
