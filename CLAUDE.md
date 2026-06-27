# Standards UI — à respecter impérativement

Ces deux règles sont contrôlées automatiquement par `node scripts/check-ui-standards.mjs`,
exécuté dans le CI (`.github/workflows/lint.yml`, job `quality`, step "Check UI standards").
Une PR qui les viole échoue le check **avant merge**. Lance `npm run lint:standards` en local
pour vérifier avant de pousser.

## 1. Badge de statut : point coloré, jamais de fond coloré

Tout affichage d'un **statut** (commande, facture, dépense, transfert, période, paiement...)
doit utiliser le composant `resources/js/components/StatusDot.vue` :

```vue
<StatusDot :status="commande.statut" :label="commande.statut_label" />
```

- Le prop `status` (la valeur brute backend, ex: `"livraison_en_cours"`) résout automatiquement
  la couleur du point via la table centralisée dans `StatusDot.vue`.
- **Interdit** : un `<span>`/`<Badge>` avec des classes `rounded-full` + `bg-*-50` / `bg-*-100`
  (pill à fond coloré) pour afficher un statut. Le seul élément coloré est le point (`<span class="rounded-full ...">`
  interne au composant), jamais le fond du texte.
- Si une **nouvelle valeur de statut** apparaît (nouveau workflow, nouvel enum), ajoute-la
  UNIQUEMENT dans `STATUS_COLOR_MAP` au sommet de `StatusDot.vue` — ne crée pas de map de
  couleur locale dans la page.
- Cette règle ne s'applique qu'aux **statuts d'entité**. Les badges de catégorie/type/rôle
  (ex: type de produit, rôle utilisateur, catégorie de dépense) peuvent garder un fond coloré —
  ce ne sont pas des statuts.

## 2. Filtres de liste : toujours `DataFilters.vue`

Toute page de liste (Index) avec des filtres doit utiliser
`resources/js/components/filters/DataFilters.vue`, pas des `<select>`/`<Dropdown>` faits main :

```vue
<DataFilters url="/produits" :values="filters" :fields="filterFields" v-model:search="search" />
```

- Ordre standard de la barre : **Filtres → Recherche → Agence**.
- Le filtre Agence est obligatoire dès qu'une page affiche des données multi-sites, et doit
  envoyer `site_ids[]` au backend.
- Ne déclare pas plusieurs `ref` nommées `filterXxx`/`filtreXxx` sans importer `DataFilters.vue` —
  c'est exactement le pattern détecté par le check CI comme "filtre fait maison".

## Échappatoire

Si un cas est légitimement hors-périmètre (ex: un badge qui ressemble à un statut mais qui est
en fait une catégorie), ajoute le commentaire `ui-standard-ignore-file` n'importe où dans le
fichier `.vue` pour désactiver les deux checks sur ce fichier. À utiliser avec parcimonie — c'est
une échappatoire, pas un moyen de contourner la règle par défaut.
