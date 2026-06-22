# DataFilters — Système de filtres générique

## Vue d'ensemble

`DataFilters.vue` est le composant unique de filtrage de l'application.  
Toutes les pages de liste doivent l'utiliser. Aucun module ne recrée son propre système de filtre.

**Localisation :** `resources/js/components/filters/DataFilters.vue`

---

## Barre standard

Chaque page de liste affiche dans cet ordre :

```
[ Recherche ] [ Agence / Site* ] [ Filtres ▾ (badge) ] [ N résultats ] [ Réinitialiser ]
```

`*` visible uniquement pour les admins (`is-admin` + `sites` fournis).

Le bouton **Filtres** ouvre un drawer latéral droit contenant les champs configurés via la prop `fields`.

La recherche est **immédiate** (client-side, aucun appel serveur au keystroke).  
Les filtres avancés sont appliqués uniquement au clic sur **Appliquer les filtres**.

---

## Props

| Prop | Type | Défaut | Description |
|------|------|--------|-------------|
| `url` | `string` | — | Si fourni, déclenche `router.get(url, params)` à l'application |
| `baseParams` | `Record<string, string \| string[]>` | `{}` | Params toujours inclus (ex: `{ periode: 'all' }`) |
| `values` | `Record<string, unknown>` | `{}` | Valeurs courantes des filtres (issues des props Inertia) |
| `sites` | `Array<{ id: string; nom: string }>` | `[]` | Liste des sites pour le filtre admin |
| `isAdmin` | `boolean` | `false` | Affiche le multi-select Agence/Site dans la barre principale |
| `searchPlaceholder` | `string` | `'Rechercher…'` | Placeholder du champ de recherche |
| `searchKey` | `string` | — | Si fourni, inclut la valeur de recherche dans les params serveur |
| `resultCount` | `number` | **requis** | Nombre de résultats affichés |
| `fields` | `FilterField[]` | **requis** | Configuration des champs du drawer |

## v-model

```vue
v-model:search="search"
```

Lie le champ de recherche à un ref local du parent. La valeur change immédiatement à chaque frappe.

## Événements

| Événement | Payload | Déclenchement |
|-----------|---------|---------------|
| `@apply` | `values: Record<string, unknown>` | Clic sur "Appliquer les filtres" |
| `@reset` | — | Clic sur "Réinitialiser" |

---

## Interface FilterField

```typescript
interface FilterField {
  key: string           // nom du paramètre URL
  label: string         // libellé affiché dans le drawer
  type: FilterFieldType
  options?: Array<{ value: string | number; label: string }>
  placeholder?: string
  startKey?: string     // date-range uniquement : nom du param début
  endKey?: string       // date-range uniquement : nom du param fin
}

type FilterFieldType =
  | 'text'
  | 'select'
  | 'multi-select'
  | 'date'
  | 'date-range'
  | 'number'
  | 'boolean'
```

---

## Types de champs

### `select` et `multi-select`
Les deux types utilisent `FilterMultiSelect` (PrimeVue MultiSelect avec toggle "Tous" custom).  
La différence entre les deux est sémantique — en pratique ils s'affichent identiquement.

```typescript
{ key: 'statuts', label: 'Statut', type: 'multi-select', options: [
    { value: 'en_cours', label: 'En cours' },
    { value: 'terminee', label: 'Terminée' },
] }
```

La valeur interne est toujours un `string[]`. Vide `[]` = Tous = paramètre omis de l'URL.

### `text`
Champ texte libre.
```typescript
{ key: 'vehicule', label: 'Véhicule', type: 'text', placeholder: 'Nom ou immatriculation…' }
```

### `date-range`
Deux champs date (début / fin) affichés côte à côte.  
Par défaut, génère les params `${key}_debut` et `${key}_fin`. Surchargeables via `startKey`/`endKey`.
```typescript
{ key: 'date', label: 'Période', type: 'date-range', startKey: 'date_debut', endKey: 'date_fin' }
```

### `date`
Champ date unique.
```typescript
{ key: 'date_echeance', label: 'Date d\'échéance', type: 'date' }
```

### `number`
Champ numérique.
```typescript
{ key: 'montant_min', label: 'Montant minimum', type: 'number' }
```

### `boolean`
PrimeVue Select avec les options Tous / Oui / Non. Émet `'1'`, `'0'` ou `''`.
```typescript
{ key: 'is_active', label: 'Actif', type: 'boolean' }
```

---

## Utilisation dans une page — filtrage serveur

Cas le plus courant : les données viennent du backend, les filtres déclenchent un rechargement.

```vue
<script setup lang="ts">
import DataFilters, { type FilterField } from '@/components/filters/DataFilters.vue'

const props = defineProps<{
    commandes: Commande[]
    filters: { statuts?: string[]; date_debut?: string; date_fin?: string }
    sites: { id: string; nom: string }[]
    is_admin: boolean
}>()

const search = ref('')

const commandesFiltrees = computed(() => {
    const q = search.value.toLowerCase().trim()
    if (!q) return props.commandes
    return props.commandes.filter(c => c.reference.toLowerCase().includes(q))
})

const filterValues = computed(() => ({
    statuts: props.filters.statuts ?? [],
    date_debut: props.filters.date_debut ?? '',
    date_fin: props.filters.date_fin ?? '',
}))

const filterFields: FilterField[] = [
    { key: 'statuts', label: 'Statut', type: 'multi-select',
      options: [{ value: 'en_cours', label: 'En cours' }, { value: 'terminee', label: 'Terminée' }] },
    { key: 'date', label: 'Période', type: 'date-range', startKey: 'date_debut', endKey: 'date_fin' },
]
</script>

<template>
    <DataFilters
        url="/commandes"
        :values="filterValues"
        :sites="sites"
        :is-admin="is_admin"
        v-model:search="search"
        search-placeholder="Référence, client…"
        :result-count="commandesFiltrees.length"
        :fields="filterFields"
    />
</template>
```

---

## Utilisation dans une page — filtrage client-side

Pour les pages où toutes les données sont déjà chargées et le filtrage se fait localement.

```vue
<script setup lang="ts">
import DataFilters, { type FilterField } from '@/components/filters/DataFilters.vue'

const props = defineProps<{ items: Item[] }>()

const search = ref('')
const localStatuts = ref<string[]>([])

const filterFields: FilterField[] = [
    { key: 'statuts', label: 'Statut', type: 'multi-select',
      options: [{ value: 'actif', label: 'Actif' }, { value: 'inactif', label: 'Inactif' }] },
]

const localFilterValues = computed(() => ({ statuts: localStatuts.value }))

function handleApply(vals: Record<string, unknown>) {
    localStatuts.value = (vals.statuts as string[]) ?? []
}

function resetFilters() {
    search.value = ''
    localStatuts.value = []
}

const filteredItems = computed(() => {
    let list = props.items
    if (localStatuts.value.length > 0) {
        list = list.filter(i => localStatuts.value.includes(i.statut))
    }
    const q = search.value.toLowerCase().trim()
    if (!q) return list
    return list.filter(i => i.nom.toLowerCase().includes(q))
})
</script>

<template>
    <DataFilters
        v-model:search="search"
        :values="localFilterValues"
        :fields="filterFields"
        :result-count="filteredItems.length"
        @apply="handleApply"
        @reset="resetFilters"
    />
</template>
```

---

## Params serveur — convention

Quand `url` est fourni et l'utilisateur clique **Appliquer les filtres**, le composant appelle :

```javascript
router.get(url, params, { preserveScroll: true, replace: true })
```

**Convention : vide = Tous = omis des params.**

| Valeur interne | Résultat dans l'URL |
|----------------|---------------------|
| `[]` | Param absent (= tous) |
| `['actif']` | `statuts[]=actif` |
| `['actif', 'inactif']` | `statuts[]=actif&statuts[]=inactif` |
| `''` | Param absent |
| `'2024-01-01'` | `date_debut=2024-01-01` |

Construction des params :
1. `baseParams` toujours inclus
2. `searchKey` → `params[searchKey] = search.value` (si fourni et non vide)
3. `site_ids` → inclus si au moins un site sélectionné
4. `multi-select`/`select` : inclus si `arr.length > 0 && arr.length < totalOptions`
5. `text`/`date`/`number` : inclus si non vide

---

## Option "Tous" dans les selects

`FilterMultiSelect` (utilisé pour `select` et `multi-select`) ajoute un header custom avec une case "Tous".

- **Clic "Tous"** quand rien ou tout est sélectionné → vide le modèle (= pas de filtre actif)
- **Clic "Tous"** quand une sélection partielle existe → sélectionne tout
- **Convention externe** : modèle vide `[]` = Tous = omis du param URL

---

## Agence / Site (admin)

Le filtre Agence/Site est dans la **barre principale** (pas dans le drawer), visible uniquement pour les admins.

```vue
<DataFilters
    :sites="sites"
    :is-admin="is_admin"
    ...
/>
```

Il n'est pas compté dans le badge du bouton Filtres mais est inclus dans `hasActiveFilters` (qui conditionne l'affichage de "Réinitialiser").

---

## Slot inline

Pour ajouter des contrôles directement dans la barre (hors drawer) :

```vue
<DataFilters ...>
    <template #inline>
        <Select v-model="localPeriode" :options="periodes" ... />
    </template>
</DataFilters>
```

---

## Réinitialisation

Clic sur **Réinitialiser** (dans la barre ou dans le drawer) :
1. Vide toutes les valeurs locales du composant (y compris `localSiteIds`)
2. Vide `search`
3. Appelle `router.get(url, baseParams)` si `url` est fourni
4. Émet `@reset`

---

## Ajouter un nouveau type de champ

1. Ajouter la valeur dans `FilterFieldType` dans `DataFilters.vue`
2. Ajouter un bloc `v-else-if="field.type === 'nouveau-type'"` dans le template du drawer
3. Mettre à jour `buildParams()` si le nouveau type a une sérialisation particulière
4. Mettre à jour `drawerFilterCount` pour le compter correctement
5. Mettre à jour `initLocal()` pour l'initialiser depuis `values`

---

## Maintenance

**La maintenance future se fait dans `DataFilters.vue` uniquement.**  
Toute amélioration bénéficie automatiquement à tous les modules.

Chaque module ne doit gérer que :
1. Sa configuration `filterFields`
2. Le `filterValues` computed (mapping props → values)
3. Sa logique backend (controllers)

Ne jamais recréer un système de filtre dans un module. Si un besoin n'est pas couvert par `DataFilters`, étendre le composant.

**Composants secondaires :**
- `FilterMultiSelect.vue` — wrapper PrimeVue MultiSelect avec toggle "Tous" custom
- `FilterBar.vue` — barre flex conteneur (slot default + slot `#actions`)
- `FilterDrawer.vue` — Sheet latérale avec bouton Filtres + Apply/Reset
