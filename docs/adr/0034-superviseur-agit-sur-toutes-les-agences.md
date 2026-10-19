# ADR 0034 — Le superviseur agit sur toutes les agences, comme un administrateur

- **Date** : 2026-10-19
- **Statut** : accepté le 2026-10-19 (décision de l'utilisateur pendant le chantier)
- **Amende** : [ADR 0025](0025-vision-360-consultation-de-toutes-les-agences.md), point 5
  (« l'écriture ne change pas »), pour le seul rôle superviseur
- **Détail fonctionnel** : [docs/sites.md](../sites.md) (périmètre de consultation et d'écriture)

## Contexte

En production, un superviseur (rôle personnalisé « SUPERVISEUR », rattaché à Matoto) voyait le
filtre Agence de l'écran Ventes verrouillé sur son agence. L'ADR 0025 permettait déjà d'ouvrir la
**consultation** de toutes les agences par la case `sites.lecture_toutes_agences`, sans toucher à
l'écriture. L'utilisateur a demandé plus : « peu importe la permission attribuée au superviseur,
il agit dans toutes les agences comme les admins ».

## Décision

1. **Codé sur le rôle** (choix explicite de l'utilisateur, contre la recommandation d'une case de
   rôle) : le rôle de nom technique `superviseur` (`User::ROLE_SUPERVISEUR`) a le même périmètre
   d'agences qu'un administrateur. Le nom technique vient du libellé à la création
   (`RoleNamingService::technicalName()`) et ne change pas quand le libellé ou le trinôme sont
   modifiés ; un autre rôle (ex. « Superviseur adjoint » → `superviseur_adjoint`) n'en profite pas.
2. **Une seule règle** : `User::agitSurToutesLesAgences()` = administrateur **ou** superviseur. Elle
   remplace `isAdmin()` partout où ce test décidait des **agences** sur lesquelles on agit :
   `SiteScopeService::assignedSiteIds()`, policies des supports et caisses de trésorerie, des
   mouvements de fonds (envoyer, recevoir, annuler, contester, retour, régler), du paiement d'une
   fiche, des transferts logistiques (réception, annulation, retour) ; choix du site d'un véhicule,
   du site source d'un transfert, du site d'une dépense ; règlement inter-agences ; relance du
   monitoring des commissions ; compteur « mouvements à confirmer ». `voitToutesLesAgences()`
   l'inclut : la consultation suit, sans case à cocher.
3. **Aucune permission accordée** : chaque action exige toujours sa permission (Lire, Créer,
   Modifier, Valider, Encaisser…), configurée dans Rôles & Permissions. Sans « Lire » sur un
   module, le superviseur n'y accède pas.
4. **« Comme les admins », pas « admin »** (décision de l'utilisateur) :
   - les fonctions réservées aux administrateurs restent réservées (`isAdmin()` inchangé) : périodes
     de paie (créer, calculer, valider, ajuster, clôturer, supprimer), annulation d'une vente,
     validation d'un cashback, validation administrative d'une réception, ajustements de
     commissions, rôles, organisation, fonctions RH, attribution du rôle admin_entreprise ;
   - les modules dotés de leur propre réglage par rôle le suivent, comme pour admin_entreprise :
     « Peut acheter pour » (achats), droits de création/validation des dépenses, droit
     d'ajustement de stock, plafonds de validation. Pour qu'un superviseur y agisse partout, on
     règle son périmètre sur « toutes les agences » dans ces écrans.

## Conséquences

- Contraire à la règle du projet « jamais de passe-droit dédié à un rôle » : assumé, sur décision
  de l'utilisateur. Le comportement ne se règle pas dans Paramètres ; le retirer demande un
  déploiement (ou de renommer techniquement le rôle).
- Supprimer puis recréer le rôle avec un libellé différent (« Superviseurs », « Superviseur
  général ») produit un autre nom technique : le périmètre ne s'appliquerait plus.
- Les écrans qui reçoivent `is_admin` / `can_change_site` pour proposer le choix d'une agence
  (véhicule, transfert, dépense) le proposent désormais au superviseur.

## Vérification

`tests/Feature/Authorization/SuperviseurToutesAgencesTest.php` : ventes et totaux de toutes les
agences sans case 360°, filtre Agence, module refusé sans « Lire », autre organisation invisible,
aucune écriture sans permission, écritures dans une autre agence avec permission (trésorerie,
fiche, transfert, véhicule), témoin limité, fonctions admin refusées, réglage des dépenses
appliqué, libellé renommé conservé, autre nom sans effet.
