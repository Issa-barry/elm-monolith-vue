<script setup lang="ts">
import FilterBar from '@/components/FilterBar.vue';
import FilterDrawer from '@/components/FilterDrawer.vue';
import FilterMultiSelect from '@/components/filters/FilterMultiSelect.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { router } from '@inertiajs/vue3';
import { Search, X } from 'lucide-vue-next';
import Select from 'primevue/select';
import { computed, ref, watch } from 'vue';

// ── Types ─────────────────────────────────────────────────────────────────────

export type FilterFieldType =
    | 'text'
    | 'select'
    | 'multi-select'
    | 'date'
    | 'date-range'
    | 'number'
    | 'boolean';

export interface FilterOption {
    value: string | number;
    label: string;
}

export interface FilterField {
    key: string;
    label: string;
    type: FilterFieldType;
    options?: FilterOption[];
    placeholder?: string;
    startKey?: string;
    endKey?: string;
}

interface SiteOption {
    id: string;
    nom: string;
}

// ── Props & emits ─────────────────────────────────────────────────────────────

const props = withDefaults(
    defineProps<{
        url?: string;
        baseParams?: Record<string, string | string[]>;
        values?: Record<string, unknown>;
        sites?: SiteOption[];
        isAdmin?: boolean;
        searchPlaceholder?: string;
        searchKey?: string;
        resultCount: number;
        fields: FilterField[];
    }>(),
    {
        baseParams: () => ({}),
        values: () => ({}),
        sites: () => [],
        isAdmin: false,
        searchPlaceholder: 'Rechercher…',
        searchKey: undefined,
        url: undefined,
    },
);

const emit = defineEmits<{
    apply: [values: Record<string, unknown>];
    reset: [];
}>();

const search = defineModel<string>('search', { default: '' });

// ── État local ────────────────────────────────────────────────────────────────

const filterDrawerOpen = ref(false);
const localSiteIds = ref<string[]>([]);
const localValues = ref<Record<string, unknown>>({});

function toArray(val: unknown): string[] {
    if (Array.isArray(val)) return val.map(String);
    if (typeof val === 'string' && val) return [val];
    return [];
}

function initLocal() {
    const v = props.values ?? {};
    localSiteIds.value = toArray(v.site_ids);
    const fresh: Record<string, unknown> = {};
    for (const field of props.fields) {
        if (field.type === 'date-range') {
            const sk = field.startKey ?? `${field.key}_debut`;
            const ek = field.endKey ?? `${field.key}_fin`;
            fresh[sk] = (v[sk] as string) ?? '';
            fresh[ek] = (v[ek] as string) ?? '';
        } else if (field.type === 'multi-select' || field.type === 'select') {
            fresh[field.key] = toArray(v[field.key]);
        } else {
            fresh[field.key] = (v[field.key] as string) ?? '';
        }
    }
    localValues.value = fresh;
}

initLocal();
watch(() => props.values, initLocal, { deep: true });

// ── Sites options avec "Toutes les agences" ───────────────────────────────────

const siteOptions = computed(() =>
    props.sites.map((s) => ({ value: s.id, label: s.nom })),
);

// ── Logique apply / reset ─────────────────────────────────────────────────────

function buildParams(): Record<string, string | string[]> {
    const params: Record<string, string | string[]> = { ...props.baseParams };

    if (props.searchKey && search.value) {
        params[props.searchKey] = search.value;
    }

    if (localSiteIds.value.length > 0) {
        params.site_ids = localSiteIds.value;
    }

    for (const field of props.fields) {
        if (field.type === 'date-range') {
            const sk = field.startKey ?? `${field.key}_debut`;
            const ek = field.endKey ?? `${field.key}_fin`;
            if (localValues.value[sk]) params[sk] = localValues.value[sk] as string;
            if (localValues.value[ek]) params[ek] = localValues.value[ek] as string;
        } else if (field.type === 'multi-select' || field.type === 'select') {
            const arr = (localValues.value[field.key] as string[]) ?? [];
            const total = field.options?.length ?? 0;
            if (arr.length > 0 && arr.length < total) {
                params[field.key] = arr;
            }
        } else if (field.type === 'boolean') {
            const val = localValues.value[field.key];
            if (val !== '' && val !== null && val !== undefined) {
                params[field.key] = String(val);
            }
        } else {
            if (localValues.value[field.key]) {
                params[field.key] = localValues.value[field.key] as string;
            }
        }
    }

    return params;
}

function applyFilters() {
    const values = buildParams();
    if (props.url) {
        router.get(props.url, values, { preserveScroll: true, replace: true });
    }
    emit('apply', values);
}

function resetFilters() {
    localSiteIds.value = [];
    search.value = '';
    for (const field of props.fields) {
        if (field.type === 'date-range') {
            const sk = field.startKey ?? `${field.key}_debut`;
            const ek = field.endKey ?? `${field.key}_fin`;
            localValues.value[sk] = '';
            localValues.value[ek] = '';
        } else if (field.type === 'multi-select' || field.type === 'select') {
            localValues.value[field.key] = [];
        } else {
            localValues.value[field.key] = '';
        }
    }
    if (props.url) {
        router.get(props.url, props.baseParams, {
            preserveScroll: true,
            replace: true,
        });
    }
    emit('reset');
}

// ── Compteur filtres actifs (drawer uniquement, pas le site de la barre) ──────

const drawerFilterCount = computed(() => {
    let n = 0;
    for (const field of props.fields) {
        if (field.type === 'date-range') {
            const sk = field.startKey ?? `${field.key}_debut`;
            const ek = field.endKey ?? `${field.key}_fin`;
            if (localValues.value[sk] || localValues.value[ek]) n++;
        } else if (field.type === 'multi-select' || field.type === 'select') {
            const arr = (localValues.value[field.key] as string[]) ?? [];
            const total = field.options?.length ?? 0;
            if (arr.length > 0 && arr.length < total) n++;
        } else if (field.type === 'boolean') {
            if (localValues.value[field.key] !== '') n++;
        } else {
            if (localValues.value[field.key]) n++;
        }
    }
    return n;
});

const hasActiveFilters = computed(
    () =>
        drawerFilterCount.value > 0 ||
        localSiteIds.value.length > 0 ||
        !!search.value,
);

</script>

<template>
    <FilterBar>
        <!-- Recherche -->
        <div class="relative w-[260px] shrink-0">
            <Search
                class="pointer-events-none absolute top-1/2 left-2.5 h-4 w-4 -translate-y-1/2 text-muted-foreground"
            />
            <input
                v-model="search"
                type="text"
                :placeholder="searchPlaceholder"
                class="h-9 w-full rounded-md border border-input bg-background py-2 pr-7 pl-8 text-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                @keydown.enter.prevent="applyFilters"
            />
            <button
                v-if="search"
                type="button"
                class="absolute top-1/2 right-2 -translate-y-1/2 text-muted-foreground hover:text-foreground"
                @click="search = ''"
            >
                <X class="h-3.5 w-3.5" />
            </button>
        </div>

        <!-- Agence / Site dans la barre principale (admin) -->
        <div v-if="isAdmin && sites.length > 0" class="w-[220px] shrink-0">
            <FilterMultiSelect
                v-model="localSiteIds"
                :options="siteOptions"
                placeholder="Toutes les agences"
            />
        </div>

        <!-- Slot pour contrôles inline additionnels -->
        <slot name="inline" />

        <!-- Bouton Appliquer (barre principale) -->
        <button
            type="button"
            class="h-9 shrink-0 rounded-md bg-primary px-4 text-sm font-medium text-primary-foreground hover:bg-primary/90"
            @click="applyFilters"
        >
            Appliquer
        </button>

        <!-- Drawer filtres avancés -->
        <FilterDrawer
            v-if="fields.length > 0"
            v-model:open="filterDrawerOpen"
            title="Filtres"
            :active-count="drawerFilterCount"
            @apply="applyFilters"
            @reset="resetFilters"
        >
            <div @keydown.enter.prevent="applyFilters" class="space-y-5">
                <!-- Champs dynamiques -->
                <template v-for="field in fields" :key="field.key">

                    <!-- multi-select ou select → FilterMultiSelect avec toggle Tous -->
                    <div
                        v-if="field.type === 'multi-select' || field.type === 'select'"
                        class="space-y-1.5"
                    >
                        <Label>{{ field.label }}</Label>
                        <FilterMultiSelect
                            v-model="localValues[field.key] as (string | number)[]"
                            :options="field.options ?? []"
                            :placeholder="field.placeholder ?? 'Tous'"
                        />
                    </div>

                    <!-- date-range -->
                    <div
                        v-else-if="field.type === 'date-range'"
                        class="grid grid-cols-2 gap-2"
                    >
                        <div class="space-y-1.5">
                            <Label>Date début</Label>
                            <Input
                                v-model="localValues[field.startKey ?? `${field.key}_debut`]"
                                type="date"
                                class="h-9"
                            />
                        </div>
                        <div class="space-y-1.5">
                            <Label>Date fin</Label>
                            <Input
                                v-model="localValues[field.endKey ?? `${field.key}_fin`]"
                                type="date"
                                class="h-9"
                            />
                        </div>
                    </div>

                    <!-- date -->
                    <div v-else-if="field.type === 'date'" class="space-y-1.5">
                        <Label>{{ field.label }}</Label>
                        <Input
                            v-model="localValues[field.key]"
                            type="date"
                            class="h-9"
                        />
                    </div>

                    <!-- number -->
                    <div v-else-if="field.type === 'number'" class="space-y-1.5">
                        <Label>{{ field.label }}</Label>
                        <Input
                            v-model.number="localValues[field.key]"
                            type="number"
                            :placeholder="field.placeholder"
                            class="h-9"
                        />
                    </div>

                    <!-- boolean -->
                    <div v-else-if="field.type === 'boolean'" class="space-y-1.5">
                        <Label>{{ field.label }}</Label>
                        <Select
                            v-model="localValues[field.key]"
                            :options="[
                                { value: '', label: 'Tous' },
                                { value: '1', label: 'Oui' },
                                { value: '0', label: 'Non' },
                            ]"
                            option-label="label"
                            option-value="value"
                            class="w-full"
                            fluid
                        />
                    </div>

                    <!-- text (défaut) -->
                    <div v-else class="space-y-1.5">
                        <Label>{{ field.label }}</Label>
                        <Input
                            v-model="localValues[field.key]"
                            type="text"
                            :placeholder="field.placeholder ?? ''"
                            class="h-9"
                        />
                    </div>

                </template>
            </div>
        </FilterDrawer>

        <template #actions>
            <span
                class="shrink-0 text-xs whitespace-nowrap text-muted-foreground"
            >
                {{ resultCount }} résultat{{ resultCount !== 1 ? 's' : '' }}
            </span>
            <button
                v-if="hasActiveFilters"
                type="button"
                class="shrink-0 text-xs text-muted-foreground underline-offset-2 hover:text-foreground hover:underline"
                @click="resetFilters"
            >
                Réinitialiser
            </button>
        </template>
    </FilterBar>
</template>
