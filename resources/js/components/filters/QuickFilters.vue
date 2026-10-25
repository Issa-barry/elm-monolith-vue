<script setup lang="ts">
/**
 * Barre rapide placée en tête du tableau d'une page de liste (recherche + filtres texte fréquents).
 * Elle complète le panneau de DataFilters (mode `trigger-only` + `quick-bar`) : mêmes champs
 * `inline`, même URL, même serveur. Chaque saisie repart des filtres déjà appliqués renvoyés par
 * le serveur et ne remplace que son propre critère : les filtres du panneau sont conservés.
 */
import type { FilterField } from '@/components/filters/DataFilters.vue';
import { router } from '@inertiajs/vue3';
import { useDebounceFn } from '@vueuse/core';
import { Search, X } from 'lucide-vue-next';
import { computed, reactive, watch } from 'vue';

const props = withDefaults(
    defineProps<{
        url: string;
        /** Champs texte de la barre (les champs `inline` du panneau). */
        fields: FilterField[];
        /** Filtres effectivement appliqués, tels que renvoyés par le serveur. */
        values: Record<string, unknown>;
        /** Champ affiché en premier, en recherche avec loupe. */
        searchKey?: string;
        baseParams?: Record<string, string | string[]>;
        /** Comme DataFilters : un choix explicite (`all=1`) ne réapplique pas la vue par défaut. */
        savedFilterScope?: string;
        debounce?: number;
    }>(),
    {
        searchKey: undefined,
        baseParams: () => ({}),
        savedFilterScope: undefined,
        debounce: 400,
    },
);

const searchField = computed(() =>
    props.fields.find((f) => f.key === props.searchKey),
);
const otherFields = computed(() =>
    props.fields.filter((f) => f.key !== props.searchKey),
);
const quickKeys = computed(() => props.fields.map((f) => f.key));
// Grille mobile/tablette : une colonne plus large pour un libellé plus long (« N° commande »).
const gridColumns = computed(() =>
    otherFields.value
        .map((f) => `minmax(0, ${Math.max(1, f.label.length / 8)}fr)`)
        .join(' '),
);

function applied(key: string): string {
    const value = props.values[key];
    return typeof value === 'string' || typeof value === 'number'
        ? String(value)
        : '';
}

const local = reactive<Record<string, string>>({});
// Dernière requête envoyée (remise à zéro à chaque réponse) : une saisie différée qui arrive
// juste après un effacement immédiat ne renvoie pas la même requête une seconde fois.
let lastSent = '';
// Saisies pas encore envoyées : la réponse d'une requête précédente ne doit pas les écraser.
const pending = new Set<string>();

function syncFromServer() {
    for (const key of quickKeys.value) {
        if (!pending.has(key)) local[key] = applied(key);
    }
}
syncFromServer();
watch(
    () => props.values,
    () => {
        lastSent = '';
        syncFromServer();
    },
    { deep: true },
);

const hasActive = computed(() =>
    quickKeys.value.some((key) => applied(key).trim() !== ''),
);

function serverParams(): Record<string, string | string[]> {
    const params: Record<string, string | string[]> = { ...props.baseParams };
    for (const [key, value] of Object.entries(props.values)) {
        if (quickKeys.value.includes(key)) continue;
        if (Array.isArray(value)) {
            if (value.length > 0) params[key] = value.map(String);
        } else if (value !== null && value !== undefined && value !== '') {
            params[key] = String(value);
        }
    }
    return params;
}

function submit() {
    pending.clear();
    const params = serverParams();
    let changed = false;
    for (const key of quickKeys.value) {
        const value = (local[key] ?? '').trim();
        if (value) params[key] = value;
        if (value !== applied(key).trim()) changed = true;
    }
    const signature = JSON.stringify(params);
    if (!changed || signature === lastSent) return;
    lastSent = signature;
    router.get(
        props.url,
        { ...params, ...(props.savedFilterScope ? { all: '1' } : {}) },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

const submitDebounced = useDebounceFn(submit, () => props.debounce);

function onInput(key: string, value: string) {
    local[key] = value;
    pending.add(key);
    void submitDebounced();
}

function clearField(key: string) {
    local[key] = '';
    submit();
}

function clearAll() {
    for (const key of quickKeys.value) local[key] = '';
    submit();
}

// La place de la croix d'effacement n'est réservée que lorsqu'elle est affichée.
function inputClass(key: string): string[] {
    return [
        'h-9 w-full rounded-md border border-input bg-background text-sm placeholder:text-muted-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:opacity-50',
        local[key] ? 'pr-8' : 'pr-2.5 sm:pr-3',
    ];
}
</script>

<template>
    <!-- Filtres rapides à gauche, recherche à droite. Sous 1280 px : les filtres rapides sur une
         ligne (grille), puis la recherche ; sur mobile, les actions du panneau en dessous. -->
    <div
        data-testid="quick-filters"
        role="search"
        aria-label="Filtres rapides"
        class="flex flex-wrap items-center gap-2 border-b bg-card px-4 py-3"
    >
        <div
            v-if="otherFields.length"
            class="grid w-full gap-2 xl:flex xl:w-auto xl:items-center"
            :style="{ gridTemplateColumns: gridColumns }"
        >
            <div
                v-for="field in otherFields"
                :key="field.key"
                class="relative min-w-0 xl:w-40"
            >
                <input
                    :value="local[field.key]"
                    type="text"
                    :data-testid="`quick-filter-${field.key}`"
                    :aria-label="field.label"
                    :placeholder="field.label"
                    :title="field.placeholder"
                    :disabled="field.disabled ?? false"
                    :class="[inputClass(field.key), 'pl-2.5 sm:pl-3']"
                    @input="
                        onInput(
                            field.key,
                            ($event.target as HTMLInputElement).value,
                        )
                    "
                    @keydown.enter.prevent="submit"
                />
                <button
                    v-if="local[field.key]"
                    type="button"
                    :data-testid="`quick-filter-clear-${field.key}`"
                    :aria-label="`Effacer ${field.label}`"
                    class="absolute top-1/2 right-2 flex h-5 w-5 -translate-y-1/2 items-center justify-center rounded text-muted-foreground hover:text-foreground"
                    @click="clearField(field.key)"
                >
                    <X class="h-3.5 w-3.5" />
                </button>
            </div>
        </div>

        <div
            v-if="searchField"
            class="relative min-w-0 flex-1 basis-48 xl:ml-auto xl:w-80 xl:flex-none xl:basis-auto"
        >
            <Search
                class="pointer-events-none absolute top-1/2 left-2.5 h-4 w-4 -translate-y-1/2 text-muted-foreground"
            />
            <input
                :value="local[searchField.key]"
                type="search"
                :data-testid="`quick-filter-${searchField.key}`"
                :aria-label="searchField.label"
                :placeholder="searchField.placeholder ?? searchField.label"
                :disabled="searchField.disabled ?? false"
                :class="[
                    inputClass(searchField.key),
                    'pl-8 [&::-webkit-search-cancel-button]:hidden',
                ]"
                @input="
                    onInput(
                        searchField.key,
                        ($event.target as HTMLInputElement).value,
                    )
                "
                @keydown.enter.prevent="submit"
            />
            <button
                v-if="local[searchField.key]"
                type="button"
                :data-testid="`quick-filter-clear-${searchField.key}`"
                :aria-label="`Effacer ${searchField.label}`"
                class="absolute top-1/2 right-2 flex h-5 w-5 -translate-y-1/2 items-center justify-center rounded text-muted-foreground hover:text-foreground"
                @click="clearField(searchField.key)"
            >
                <X class="h-3.5 w-3.5" />
            </button>
        </div>

        <div v-if="hasActive" class="flex shrink-0 items-center">
            <button
                type="button"
                data-testid="quick-filters-reset"
                class="h-9 px-1 text-xs text-muted-foreground underline-offset-2 hover:text-foreground hover:underline"
                @click="clearAll"
            >
                Effacer
            </button>
        </div>

        <!-- Contrôles additionnels (ex. bouton du panneau sur mobile) -->
        <div v-if="$slots.default" class="flex w-full items-center gap-2">
            <slot />
        </div>
    </div>
</template>
