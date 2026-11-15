# ADR 0065 — Visibilité des dépenses par agence, configurable par rôle

- **Date** : 2026-11-15
- **Statut** : accepté le 2026-11-15 (décisions de l'utilisateur pendant le chantier)
- **Amende** : [ADR 0025](0025-vision-360-consultation-de-toutes-les-agences.md), « Conséquences »
  (« les dépenses […] étaient déjà consultables par toute l'organisation »)
- **Détail fonctionnel** : [docs/depenses-visibilite.md](../depenses-visibilite.md)

## Contexte

L'audit du 15/11/2026 a montré que l'écran Dépenses ne filtrait que par organisation : tout
utilisateur ayant « Dépenses — Lire » voyait les dépenses de toutes les agences, y compris dans
l'export, l'impression, la fiche d'une dépense (par son identifiant), les suggestions et la fiche
agent. Le filtre Agence de l'écran était verrouillé sur ses agences, mais seulement côté navigateur :
une URL sans `site_ids[]`, ou avec une autre agence, renvoyait tout. L'ADR 0025 avait figé ce
comportement en le constatant.

Le métier veut que chaque manager ne consulte que les dépenses de son agence, et que la vision de
toutes les agences se règle par rôle, sans exception codée en dur.

## Décision

1. **Permission du rôle** `depenses.lecture_toutes_agences` — « Dépenses — consulter les dépenses de
   toutes les agences », rubrique Dépenses → « Périmètre de visibilité ». Indépendante de « Lire » et
   de toute écriture.
2. **Pas de second moteur** : `DepensePerimetre` applique aux dépenses le périmètre existant. Toutes
   les agences = cette permission **ou** `User::voitToutesLesAgences()` (administrateur, superviseur,
   vision 360°, ADR 0025/0034, conservés tels quels). Sinon : agences `user_sites`
   (`SiteScopeService`) + agences où le rôle peut valider (Paramètres → Dépenses, mêmes critères que
   `DepensePolicy::valider`) + dépenses saisies par l'utilisateur (décisions de l'utilisateur).
3. **Le serveur décide, le navigateur choisit** : `site_ids[]` est croisé avec le périmètre. Le
   filtre Agence de l'écran reçoit du serveur les agences proposées et la vision toutes agences
   (`DataFilters` prop `toutes-agences`) : sélection libre d'une ou plusieurs agences parmi elles.
4. **Tous les points de lecture** : liste et statistiques, export, impression, fiche, historique,
   soumission, modification, suppression, suggestions, fiches agent, véhicule et propriétaire.
5. **Agence obligatoire** : `site_id` requis à la création et à la modification. Les dépenses
   historiques sans agence ne sont visibles qu'en vue toutes agences (et par leur auteur) jusqu'à
   leur régularisation ; la contrainte `NOT NULL` viendra ensuite par une migration dédiée.
6. **Valeur de départ** : migration de données accordant la permission à `super_admin`,
   `admin_entreprise`, `superviseur` et aux rôles de trinôme `ADD`. Le trinôme et les noms ne servent
   qu'ici.

## Conséquences

- Un manager sans la permission ne voit plus les dépenses des autres agences (liste, totaux, export,
  impression, fiches), même en modifiant l'URL.
- Un validateur dont le périmètre de validation dépasse ses agences de rattachement voit les
  dépenses de ces agences. Un rôle réglé sur « toutes les agences » en validation les voit donc
  toutes : c'est Paramètres → Dépenses qui le décide.
- Décocher la permission sur un rôle administrateur ou superviseur ne le restreint pas : ces rôles
  voient toutes les agences par la règle générale (ADR 0025/0034).
- Les rapports (charges du rapport d'activité, rapport par agence, « Ma situation ») gardent leur
  propre périmètre (`RapportPerimetreResolver`), déjà limité aux agences.
- Le catalogue gagne une permission.

## Vérification

`tests/Feature/Depenses/DepenseVisibiliteAgenceTest.php` (manager limité, URL forcée, plusieurs
agences, superviseur, administrateur, vision 360°, permission du rôle cochée puis décochée,
agences de validation, propres saisies, dépense sans agence, export et impression, fiche,
historique, modification, soumission et suppression hors périmètre, suggestions, isolation entre
organisations, agence obligatoire) ; `resources/js/components/filters/__tests__/DataFilters.spec.ts`
(prop `toutes-agences`).
