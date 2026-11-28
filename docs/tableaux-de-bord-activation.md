# Activation des tableaux de bord

> **Statut** : en place (27/11/2026) · Menu **Paramètres → Tableaux de bord**
> (`/settings/tableaux-de-bord`) · Décision : [ADR 0071](adr/0071-activation-des-tableaux-de-bord.md)

Chaque organisation choisit les tableaux de bord « Stat- » proposés à ses utilisateurs. Un tableau
désactivé disparaît du menu **Tableau de bord** et sa page est refusée par le serveur, même par un
lien direct. Ce réglage ne change aucun calcul, aucun indicateur et aucune permission.

| Élément | Fichier |
|---|---|
| Liste, valeurs par défaut, libellés | `app/Features/TableauDeBordFeature.php` |
| Lecture et modification | `app/Services/TableauDeBordService.php` |
| Refus des routes | `app/Http/Middleware/RequireTableauDeBordActif.php` (alias `tableau-de-bord:`) |
| Paramètres | `app/Http/Controllers/Settings/TableauxDeBord/`, `resources/js/pages/settings/TableauxDeBord.vue` |
| Menu | `resources/js/components/AppSidebar.vue` (prop partagée `tableaux_de_bord`, `HandleInertiaRequests`) |
| Tests | `tests/Feature/TableauxDeBordActivationTest.php`, `resources/js/components/__tests__/AppSidebarTableauDeBord.spec.ts`, `resources/js/pages/settings/__tests__/TableauxDeBord.spec.ts`, `resources/js/pages/__tests__/Dashboard.spec.ts` |

## Valeurs par défaut

| Tableau de bord | Page | État par défaut |
|---|---|---|
| Stat-Ventes | `/backoffice/dashboard` | Activé |
| Stat-Commissions | `/backoffice/tableau-de-bord/commissions` | Désactivé |
| Stat-Dépenses | `/backoffice/tableau-de-bord/depenses` | Désactivé |
| Stat-Stock | `/backoffice/tableau-de-bord/stock` | Activé |
| Stat-RH | `/backoffice/tableau-de-bord/rh` | Désactivé |
| Stat-Véhicules | `/backoffice/tableau-de-bord/vehicules` | Désactivé |

Ces valeurs valent pour une nouvelle installation, une nouvelle organisation (SaaS) et les
organisations existantes au moment du déploiement du 27/11/2026 (décision de l'utilisateur) :
Stat-Commissions, Stat-Dépenses, Stat-RH et Stat-Véhicules disparaissent alors du menu jusqu'à ce
qu'un administrateur les active.

## Persistance et déploiements

- Le réglage est enregistré par organisation dans la table `features` (Laravel Pennant, portée
  `Organization`), comme les modules métier (Paramètres → Applications).
- La valeur par défaut est calculée à la **première lecture** pour une organisation (premier
  affichage d'une page du back-office) puis enregistrée. Une valeur enregistrée n'est jamais
  recalculée.
- **Aucune migration, aucun seeder.** Le déploiement (`migrate --force`, puis
  `db:seed --class=RolesAndPermissionsSeeder`) ne touche pas à la table `features` : les choix des
  administrateurs survivent aux redéploiements et au vidage des caches.
- Changer plus tard une valeur par défaut dans `TableauDeBordFeature::ACTIFS_PAR_DEFAUT` ne
  concernerait que les organisations jamais lues ; les autres gardent leur valeur enregistrée.
- Ne jamais lancer `pennant:purge` en production : la commande effacerait ces choix, comme ceux des
  modules.

## Contrôles

Pour ouvrir un tableau de bord, il faut que **les trois** contrôles passent :

1. le tableau est activé pour l'organisation (middleware `tableau-de-bord:`, refus 403) ;
2. le module dont il lit les données est actif (middleware `module:`, inchangé) ;
3. l'utilisateur a la permission de lecture vérifiée par le contrôleur (inchangée, voir le
   document de chaque tableau).

Le refus du tableau a lieu **avant** le contrôleur : aucun calcul n'est lancé. Les périmètres
d'agence restent ceux de chaque tableau.

Le menu applique les mêmes conditions : une entrée « Stat- » n'apparaît que si le tableau est activé
**et** que l'utilisateur y a accès. Le titre **Tableau de bord** disparaît quand aucune entrée ne
reste. Le menu se met à jour dès la page suivante après un changement.

## Stat-Ventes, page d'accueil

`/backoffice/dashboard` est aussi la page d'accueil du back-office : destination après connexion
(`fortify.home`, `AuthRedirects`), après la création du premier site et après un scan de livraison,
et cible du fil d'Ariane « Tableau de bord ». Elle n'est donc **jamais refusée**. Quand Stat-Ventes
est désactivé :

- l'entrée Stat-Ventes disparaît du menu ;
- la page garde l'en-tête (identité, QR code, bouton Scanner) et le menu rapide mobile ;
- aucun indicateur ni graphique de ventes n'est affiché, et le serveur n'en calcule aucun : ni
  requête sur les factures, ni lecture de la période (un paramètre `periode` invalide est ignoré).

## Paramètres → Tableaux de bord

- Réservé à la permission `parametres.update` (lien du menu Paramètres et serveur), comme
  Paramètres → Applications.
- Un interrupteur par tableau, avec son nom, sa description et son état. L'interrupteur est
  verrouillé pendant l'enregistrement ; un message confirme le changement.
- Le changement ne vaut que pour l'organisation de l'administrateur.
