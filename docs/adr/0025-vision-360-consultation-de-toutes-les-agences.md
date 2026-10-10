# ADR 0025 — Vision 360° : consulter les données de toutes les agences, sans élargir l'écriture

- **Date** : 2026-10-10
- **Statut** : accepté le 2026-10-10 (décisions de l'utilisateur pendant le chantier)
- **Amende** : [ADR 0021](0021-achats-bon-de-commande-valide-par-plafond-de-role.md), point 2 « voir »
  et point 8 (liste des réceptions), en consultation seulement
- **Détail fonctionnel** : [docs/sites.md](../sites.md) (périmètre de consultation),
  [docs/filters.md](../filters.md) (filtre Agence), [docs/achats.md](../achats.md) (ACH-000)

## Contexte

La DSI veut que les assistants de direction (rôle personnalisé « ASSISTANTE DE DIRECTION », trinôme
`ADD`, créé sur l'instance de formation) consultent les données de **toutes** les agences, pas
seulement celles de leur agence de rattachement — sans rien changer à ce qu'ils peuvent créer,
modifier ou supprimer.

Constat de l'audit : il n'existait aucune notion de « périmètre de consultation ». Une trentaine de
contrôleurs, deux services et une policy testaient directement `User::isAdmin()` (super_admin ou
admin_entreprise) pour décider si les listes, détails, exports et statistiques étaient limités aux
agences `user_sites` de l'utilisateur. Le même test servait aussi aux écritures. Le filtre Agence de
l'interface (`DataFilters.vue`) déduisait de son côté le verrouillage du **nom** des rôles.

## Décision

1. **Une permission portée par le rôle** : `sites.lecture_toutes_agences` — « Agences — consulter les
   données de toutes les agences », cochable dans Rôles & Permissions → Sites → « Périmètre de
   consultation ». **Ni le nom ni le trinôme du rôle ne sont testés dans le code.** Raisons : le
   trinôme est modifiable à l'écran et généré automatiquement à partir des initiales (un futur rôle
   « Agent de dépôt » recevrait lui aussi `ADD`) ; une permission survit à un changement de libellé
   **et** de trinôme, se retire ou s'accorde à un autre rôle sans déploiement, et respecte la règle du
   projet : jamais de passe-droit dédié à un rôle dans le code.
2. **Le trinôme ne sert qu'une fois**, dans la migration
   `2026_10_10_300000_backfill_sites_lecture_toutes_agences_permission` : elle accorde la permission
   aux rôles dont le trinôme est `ADD`. Valeur de départ, pas une règle : ensuite seule la case compte.
3. **Une seule définition du périmètre de consultation** : `User::voitToutesLesAgences()` —
   administrateur (comportement existant) **ou** permission. `SiteScopeService` (`accessibleSiteIds()`,
   `applyToQuery()`) devient explicitement le service du périmètre de **consultation** ; tous les
   points de lecture qui testaient `isAdmin()` passent par cette règle : ventes, factures, exports,
   recherche globale, tableau de bord, rapport d'activité, produits et stock, transferts et
   réceptions, commissions (livreurs, propriétaires, sites, logistique, monitoring), fiches de
   paiement, salaires, journal financier, situation de trésorerie, supports, mouvements de fonds,
   inter-agences, financement et remises des agences, vues enregistrées, fiche livreur.
4. **La permission n'ouvre aucun écran.** La permission « Lire » de chaque ressource reste requise :
   elle élargit seulement les données des écrans déjà accessibles au rôle.
5. **L'écriture ne change pas.** Créer, modifier, supprimer, valider, payer, envoyer, recevoir,
   réceptionner, régler, relancer restent gouvernés par `isAdmin()` et le rattachement `user_sites`
   (policies, contrôleurs d'écriture), désormais via `SiteScopeService::assignedSiteIds()` là où le
   même calcul servait aux deux usages : formulaire de création d'un mouvement de fonds, règlement
   inter-agences, données de création d'une caisse d'agent, relance du monitoring des commissions.
6. **Achats — amendement de l'ADR 0021** (décision de l'utilisateur) : la permission ouvre la
   **consultation** des bons de commande, des factures d'achat (liste, fiche, PDF) et de la liste
   des réceptions fournisseurs de toutes les agences. « Peut acheter pour » continue de gouverner
   seul la création, la modification, la validation, l'annulation, la facturation, le paiement ; la
   réception reste réservée au rattachement. Dans ce module la règle teste **la permission seule**,
   sans `isAdmin()` : admin_entreprise n'y gagne rien d'automatique. Le super administrateur, qui
   détient toutes les permissions, consulte donc tous les bons même sans règle d'achat — sans aucune
   action proposée (les fiches recalculent chaque action sur le périmètre d'achat).
7. **Interface** : `auth.voit_toutes_agences` (props partagées) ouvre le filtre Agence de
   `DataFilters.vue`, qui ne lit plus le nom des rôles. Ce n'est jamais un indicateur d'écriture.

## Conséquences

- Un rôle doté de la permission voit les mêmes listes, totaux, exports et statistiques qu'un
  administrateur, sur les seuls écrans où il a « Lire ». Les badges « à réceptionner » /
  « à confirmer » restent calculés sur ses agences : ils signalent des actions qui lui reviennent.
- Les écrans déjà sans filtrage d'agence côté serveur (dépenses, véhicules, clients, fiche d'une
  commande de vente) ne changent pas : ils étaient déjà consultables par toute l'organisation.
- L'API mobile `GET /api/v1/backoffice/stats` reste calculée sur l'agence par défaut de l'utilisateur,
  pour tous les rôles, administrateurs compris : aucune distinction de rôle n'y existait à étendre.
- Le catalogue passe à 221 permissions.

## Vérification

`tests/Feature/Authorization/LectureToutesAgencesTest.php` (lecture de son agence et des autres,
témoin limité, pas d'écran sans « Lire », export, recherche, tableau de bord, trésorerie, produits,
achats, écritures refusées, libellé et trinôme modifiés, permission retirée, migration, props
partagées, isolation entre organisations) ; `CommandeAchatTest` (le super administrateur sans règle
consulte, sans action) ; `resources/js/components/filters/__tests__/DataFilters.spec.ts`.
