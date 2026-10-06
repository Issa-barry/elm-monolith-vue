# ADR 0017 — Découplage du type de site et du rôle de site central de trésorerie

- **Date** : 2026-10-02
- **Statut** : accepté, livré le 2026-10-02
- **Périmètre** : `sites` (type, `is_central_tresorerie`), `SiteCentralTresorerieResolver`,
  installation et onboarding du premier site, import CSV des sites, trésorerie (ADR 0009, 0016).
  Modèle complet : [docs/sites.md](../sites.md).
- **Libellé** : affiché « Trésorerie principale » depuis le 2026-10-02 (choix utilisateur) ;
  « site central » reste le nom technique (`is_central_tresorerie`, `SiteCentralTresorerieResolver`).

## Contexte

Le type `siege` de `SiteType` mélangeait deux notions :

- **ce qu'est le site** : agence, usine, dépôt… Matoto est une agence opérationnelle ;
- **le rôle qu'il joue** : c'est vers lui que remontent les flux de trésorerie (remises au siège,
  règlements inter-agences, paiement des fiches sans agence).

Le rôle était porté par `is_siege_principal`, réservé aux sites de type `siege`. Matoto ne pouvait
donc pas être à la fois une agence et le site central. Dans le code, ce flag n'était lu que par la
trésorerie : c'était déjà un rôle financier, pas un rang administratif.

## Décision

1. **`siege` est retiré de `SiteType`.** Les types restants décrivent la nature du site : `agence`,
   `usine`, `depot`, `boutique`, `restaurant`, `autre`.
2. **`is_siege_principal` devient `is_central_tresorerie`**, au libellé « Site central de
   trésorerie ». Le vocabulaire « siège principal » disparaît du code, des écrans et de la doc.
3. **Une organisation a au plus un site central de trésorerie.** En désigner un retire le rôle à
   l'ancien dans la même écriture (`Site::saving()`), quel que soit le chemin.
4. **Le site central peut être de n'importe quel type.** `SiteCentralTresorerieResolver::designer()`
   ne vérifie plus aucun type.
5. **Désignation** : le premier site créé à l'installation (`/install`, `app:install`, ou onboarding
   d'une organisation sans site) devient le site central, quel que soit son type. Aucune autre
   création de site ne le désigne automatiquement : plus de désignation déduite du type.
6. **Migration des données** (`2026_10_02_300000`) :
   - renommage de la colonne, valeurs conservées : le site central reste exactement le même ;
   - les sites `siege` passent en **`autre`**, jamais vers un type deviné. Leur vrai type est
     choisi ensuite, site par site, dans *Sites → Modifier* (pour ELM : Matoto → Agence) ;
   - les filtres enregistrés « Commissions sites » sur `site_type = siege` passent en `autre`.
7. **Import CSV** : « Siège » reste accepté comme alias historique, importé en `autre`, avec une
   normalisation signalée à l'analyse. L'import ne désigne jamais de site central.
8. **Affichage** : un site de type `autre` s'affiche sous son seul nom (« Matoto », pas « Autre de
   Matoto »). Le rôle central est signalé dans la liste et sur la fiche du site.
9. **Changer de trésorerie principale** : interrupteur « Trésorerie principale » dans le
   formulaire Site (création et modification).
   - Il est réservé à la permission dédiée `tresorerie.designer_principale` : absent de l'écran
     sans elle, et refusé côté serveur (403). Par défaut, seul le super administrateur l'a ;
     elle s'attribue à d'autres rôles depuis la page Rôles. `sites.update` ne suffit pas, car
     changer de site central redirige tous les règlements inter-agences.
   - L'activer sur un autre site transfère le rôle, après une confirmation qui nomme
     l'ancienne et la nouvelle trésorerie principale.
   - On ne la retire jamais en la décochant : l'interrupteur est verrouillé sur le site actuel,
     et un décochage envoyé au serveur est refusé. Une organisation ne se retrouve donc pas
     sans trésorerie principale par erreur.
   - Une valeur envoyée inchangée est ignorée : un utilisateur sans la permission peut modifier
     le reste du site.
10. **Hors de ce lot** :
   - aucun changement de comportement de `parent_id` (toujours sans règle métier) ;
   - aucune `designation` (siège national, régional, préfectoral) ;
   - aucun changement des règles de trésorerie de l'ADR 0016 : la destination unique reste le site
     central de trésorerie, qui est le même site qu'avant.

## Conséquences

- La règle devient : « Une organisation possède un site central de trésorerie », et non plus « un
  siège principal ». ADR 0009 et 0016, `docs/tresorerie-inter-agences.md`, `docs/encaissements.md` et
  `docs/commissions.md` sont alignés.
- Une organisation existante sans site central reste sans site central : le paiement d'une fiche
  sans agence reste bloqué avec un message explicite, comme avant, jusqu'à ce qu'un titulaire de
  `tresorerie.designer_principale` en désigne une depuis le formulaire Site.
- La colonne a été renommée dès ce lot, plutôt que de garder `is_siege_principal` derrière un
  nouveau libellé. Le renommage ne touche aucune valeur, il est couvert par la suite de tests, et
  il évite un nom technique qui contredit la règle.
- Une organisation régionale (désignation, droits ou remontée des fonds par la hiérarchie) demandera
  une nouvelle décision. Elle remplacerait la règle « destination unique » de l'ADR 0016.
