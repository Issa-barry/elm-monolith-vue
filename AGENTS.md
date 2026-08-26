# Standards UI — à respecter impérativement

Ces trois règles sont contrôlées automatiquement par `node scripts/check-ui-standards.mjs`,
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

## 2. Pages de liste : actions d'en-tête + filtres en mode `trigger-only`

Toute page de liste (Index) doit suivre la structure d'en-tête standard, portée par
`resources/js/components/ListPageActions.vue` :

```
[Titre + sous-titre]                     [Exporter] [Importer] [Filtres] [Nouveau]
```

```vue
<ListPageActions>
    <template #export>...</template>
    <template #import>...</template>
    <template #filters>
        <DataFilters trigger-only url="/produits" :values="filters" :fields="filterFields" />
    </template>
    <template #primary>...</template>
</ListPageActions>
```

- **Ordre strict, imposé par le composant** (pas par la page) : `Exporter → Importer → Filtres →
  Nouveau`. Une page ne place plus ces boutons elle-même dans un `<div class="flex gap-2">` fait
  main — elle passe par les slots `#export`/`#import`/`#filters`/`#primary` de `ListPageActions`.
- Si une action n'existe pas pour la page, le slot correspondant est simplement omis (pas de
  bouton fantôme).
- Le bouton primaire (`#primary`) est toujours `Nouveau <entité>`, tout à droite.
- Toute page de liste avec des filtres doit utiliser
  `resources/js/components/filters/DataFilters.vue` en mode **`trigger-only`** : un seul bouton
  **Filtres** dans l'en-tête (slot `#filters`), qui ouvre le drawer — plus de grosse barre de
  champs au-dessus du tableau. Le drawer reste l'unique endroit où les champs sont affichés.
- **Aucun champ de recherche globale** (`search`) : supprimé du composant et de toutes les pages.
  Les pages font leur propre filtre texte via un champ `type: 'text'`.
- Ordre des champs dans le drawer : **Agence (auto, si données multi-sites) → Statut → Recherche →
  période/dates → autres filtres métier** (véhicule, client, propriétaire, type, catégorie,
  montant...).
- Le filtre Agence est obligatoire dès qu'une page affiche des données multi-sites, et doit
  envoyer `site_ids[]` au backend.
- `DataFilters`/l'URL/le backend restent l'unique source de vérité des filtres (préparation d'une
  future évolution "filtres de colonnes" façon Apollo/PrimeVue — pas encore développée). Ne crée
  jamais un filtrage local différent sur une liste déjà pilotée par le backend.
- Ne déclare pas plusieurs `ref` nommées `filterXxx`/`filtreXxx` sans importer `DataFilters.vue` —
  c'est exactement le pattern détecté par le check CI comme "filtre fait maison".
- Une liste sans filtre pertinent n'a pas de slot `#filters` ; une liste sans création n'a pas de
  slot `#primary`.

## 3. Toasts : toujours en haut à droite

Tout composant PrimeVue `<Toast>` doit déclarer explicitement :

```vue
<Toast position="top-right" />
```

- **Interdit** : `bottom-right`, `bottom-left`, `bottom-center`, `center` ou une position dynamique.
- Cette règle s'applique au Toast global comme aux groupes spécialisés déclarés dans une page.
- Les appels `toast.add(...)` restent inchangés : la position est contrôlée par le composant `<Toast>` qui reçoit le message.
- Cette règle est vérifiée dans les pages, les composants et les layouts, sans échappatoire.

## 4. Règles métier : backend obligatoire

Toute règle qui bloque ou autorise une action métier (stock, solde, statut, permission,
plafond...) doit être contrôlée côté **backend**. Une désactivation de bouton, un filtre
frontend ou un message dans Vue améliore l'expérience, mais ne remplace jamais la règle
métier serveur.

- Centralise la règle dans le service, la policy ou le mécanisme métier existant.
- Contrôle à nouveau les opérations différées ou concurrentes au moment de leur exécution
  réelle (ex. création de commande puis validation du chargement).
- Une page ou une action bloquée côté interface doit toujours avoir son équivalent backend.

## 5. Paramètres et choix Oui / Non

Tout paramètre d'organisation doit être isolé par `organization_id`, protégé par la permission
existante la plus adaptée et avoir une valeur par défaut sûre.

- Pour un booléen, utiliser une seule case à cocher clairement libellée.
- Si les deux choix **Oui** et **Non** doivent être visibles, utiliser des boutons radio ; deux
  cases à cocher seraient ambiguës.
- Les paramètres ne doivent jamais être modifiables seulement dans le frontend : vérifier la
  permission également côté backend.

## 6. Historique, motifs et filtres de modale

Le motif d'un mouvement ou d'une activité doit provenir de sa source métier réelle (vente,
transfert, ajustement, production...), jamais être déduit seulement du signe ou du montant.

- `DataFilters.vue` est obligatoire pour les filtres des pages **Index**. Un filtre simple dans
  une modale d'historique peut suivre le composant déjà utilisé dans cette modale.
- N'ajoute au filtre que des motifs réellement pris en charge par les données et le backend.

## 7. Vérifications avant livraison

Après toute modification UI, lance au minimum :

```bash
npm run lint:standards
```

Ajoute et exécute les tests pertinents (Feature, Unit et/ou E2E) pour les règles métier ou les
parcours utilisateurs modifiés. Dans le compte rendu final, indique les commandes exécutées et
leurs résultats.

## Échappatoire

Si un cas est légitimement hors-périmètre (ex: un badge qui ressemble à un statut mais qui est
en fait une catégorie), ajoute le commentaire `ui-standard-ignore-file` n'importe où dans le
fichier `.vue` pour désactiver les checks Badge/DataFilters sur ce fichier. Ajoute à côté une
brève explication du cas hors-périmètre. À utiliser avec parcimonie — ce commentaire ne
désactive jamais la règle Toast en haut à droite.
