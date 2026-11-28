# ADR 0071 — Activation des tableaux de bord par organisation

- **Date** : 2026-11-27
- **Statut** : accepté le 2026-11-27 (demande de l'utilisateur)
- **Détail fonctionnel** : [docs/tableaux-de-bord-activation.md](../tableaux-de-bord-activation.md)

## Contexte

Six tableaux de bord « Stat- » s'affichaient à tout utilisateur qui avait la permission et le module
correspondants. Les administrateurs veulent choisir, sans intervention technique, ceux que leur
organisation propose. Seuls Stat-Ventes et Stat-Stock doivent être activés par défaut, y compris
après le prochain déploiement, et un redéploiement ne doit jamais écraser un choix.

`/backoffice/dashboard` (Stat-Ventes) est par ailleurs la page d'accueil du back-office : destination
après connexion et après l'onboarding, cible du fil d'Ariane de toutes les pages.

## Décision

1. **Laravel Pennant, portée `Organization`**, comme les modules métier, mais dans une classe
   distincte (`TableauDeBordFeature`) : un tableau de bord n'est pas un module, et le désactiver ne
   désactive pas le module dont il lit les données.
2. **Valeur par défaut dans le code, enregistrée à la première lecture**, jamais recalculée ensuite.
   Pas de migration ni de seeder : le déploiement ne touche pas à la table `features`, les choix des
   administrateurs sont donc conservés.
3. **Défauts identiques pour toutes les organisations, existantes comprises** : Stat-Ventes et
   Stat-Stock activés, les quatre autres désactivés. Décision de l'utilisateur du 27/11/2026, en
   connaissance de cause : Stat-Commissions, Stat-Dépenses et Stat-RH, visibles en production,
   disparaissent au déploiement jusqu'à leur activation.
4. **Refus côté serveur avant le contrôleur** (middleware `tableau-de-bord:`), en plus du module et
   de la permission, jamais à leur place. Le menu applique les mêmes conditions.
5. **Stat-Ventes désactivé ne refuse pas `/backoffice/dashboard`** : la page reste l'accueil, avec
   l'en-tête, le QR code et le menu rapide mobile, sans aucun indicateur de ventes ni calcul.
6. **Modification réservée à `parametres.update`**, pour l'organisation de l'administrateur
   seulement.

## Conséquences

- Les tests d'un tableau désactivé par défaut l'activent explicitement dans leur organisation de test.
- Ajouter un tableau de bord impose de l'ajouter à `TableauDeBordFeature` (clé, défaut, libellé,
  description), à sa route (`tableau-de-bord:`) et au menu.
- `pennant:purge` effacerait ces réglages comme ceux des modules : à ne jamais lancer en production.

## Alternatives écartées

- **Rediriger vers le premier tableau activé quand Stat-Ventes est désactivé** : il aurait fallu une
  page de secours quand aucun tableau n'est activé ou autorisé, et modifier la connexion,
  l'onboarding et les fils d'Ariane.
- **Migration qui écrit les valeurs initiales** : même résultat que la première lecture, mais
  dépendante du format interne de Pennant.
