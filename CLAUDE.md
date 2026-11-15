# Standards — à respecter impérativement

La référence unique des standards d'interface et des règles transverses est
`docs/developpement/standards.md`, chargé ci-dessous. Une règle se modifie dans ce document, jamais
ici.

@docs/developpement/standards.md

Rappels :

- Avant toute modification d'interface, appliquer la partie A ; avant toute livraison, lancer les
  vérifications de B6 (au minimum `npm run lint:standards` après une modification d'un `.vue`).
- `npm run lint:standards` tourne en CI sur toutes les pull requests : une violation bloque la
  fusion. Les échappatoires (`ui-standard-ignore-file`, `periode-standard-ignore: <raison>`) et ce
  qu'elles désactivent réellement sont décrites dans le document ; aucune ne désactive la règle
  des Toasts.
- Les règles de méthode des agents (autonomie, Git, tests, compte rendu) sont dans
  `.claude/CLAUDE.md`.
