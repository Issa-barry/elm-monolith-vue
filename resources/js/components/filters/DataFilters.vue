<script setup lang="ts">
import FilterBar from '@/components/FilterBar.vue';
import FilterDrawer from '@/components/FilterDrawer.vue';
import FilterMultiSelect from '@/components/filters/FilterMultiSelect.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { router, usePage } from '@inertiajs/vue3';
import { useDebounceFn } from '@vueuse/core';
import { Lock, Search, X } from 'lucide-vue-next';
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
    disabled?: boolean;
    /** Affiche le champ dans la barre principale plutôt que dans le drawer */
    inline?: boolean;
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
        searchPlaceholder?: string;
        searchKey?: string;
        resultCount: number;
        fields: FilterField[];
        /** Masque le sélecteur générique Agence/Site (ex: Logistique qui a ses propres filtres site) */
        hideAgenceSelector?: boolean;
    }>(),
    {
        baseParams: () => ({}),
        values: () => ({}),
        sites: () => [],
        searchPlaceholder: 'Rechercher…',
        searchKey: undefined,
        url: undefined,
        hideAgenceSelector: false,
    },
);

const emit = defineEmits<{
    apply: [values: Record<string, unknown>];
    reset: [];
}>();

const search = defineModel<string>('search', { default: '' });

// ── Détection admin ───────────────────────────────────────────────────────────

const page = usePage();

const ADMIN_ROLES = new Set(['super_admin', 'admin_entreprise']);

const isAdmin = computed(() => {
    const auth = (page.props as Record<string, unknown>).auth as
        | Record<string, unknown>
        | undefined;
    const roles = Array.isArray(auth?.roles) ? (auth.roles as string[]) : [];
    return roles.some((r) => ADMIN_ROLES.has(r));
});

// Sites de l'utilisateur connecté (vide pour admin, non-vide pour non-admin)
const authUserSites = computed<SiteOption[]>(() => {
    const auth = (page.props as Record<string, unknown>).auth as
        | Record<string, unknown>
        | undefined;
    const us = auth?.user_sites;
    return Array.isArray(us) ? (us as SiteOption[]) : [];
});

// ── Sites : prop locale ou prop globale du middleware ─────────────────────────

const effectiveSites = computed<SiteOption[]>(() => {
    if (props.sites.length > 0) return props.sites;
    const global = (page.props as Record<string, unknown>).org_sites;
    return Array.isArray(global) ? (global as SiteOption[]) : [];
});

// Options du sélecteur :
// - admin → tous les sites de l'organisation
// - non-admin → uniquement ses sites
const siteOptions = computed(() => {
    if (!isAdmin.value && authUserSites.value.length > 0) {
        return authUserSites.value.map((s) => ({ value: s.id, label: s.nom }));
    }
    return effectiveSites.value.map((s) => ({ value: s.id, label: s.nom }));
});

// Non-admin : sélecteur verrouillé (périmètre imposé par le backend)
const siteSelectorLocked = computed(() => !isAdmin.value);

// ── État local ────────────────────────────────────────────────────────────────

const filterDrawerOpen = ref(false);
const localSiteIds = ref<string[]>([]);
const localValues = ref<Record<string, unknown>>({});

// Snapshots de l'état au dernier apply (pour calculer pendingChange)
const appliedSiteIds = ref<string[]>([]);
const appliedValues = ref<Record<string, unknown>>({});

function toArray(val: unknown): string[] {
    if (Array.isArray(val)) return val.map(String);
    if (typeof val === 'string' && val) return [val];
    return [];
}

function initLocal() {
    const v = props.values ?? {};

    if (!isAdmin.value && authUserSites.value.length > 0) {
        // Non-admin : périmètre verrouillé sur ses sites
        localSiteIds.value = authUserSites.value.map((s) => s.id);
    } else {
        // Admin : reprendre la sélection serveur, ou [] (= toutes les agences)
        localSiteIds.value = toArray(v.site_ids);
    }

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

    // Sync les snapshots avec l'état serveur reçu
    appliedSiteIds.value = [...localSiteIds.value];
    appliedValues.value = JSON.parse(JSON.stringify(fresh));
}

initLocal();
watch(() => props.values, initLocal, { deep: true });

// ── Détection de changements en attente ───────────────────────────────────────

const pendingChange = computed(() => {
    const siteChanged =
        JSON.stringify([...localSiteIds.value].sort()) !==
        JSON.stringify([...appliedSiteIds.value].sort());
    if (siteChanged) return true;
    return (
        JSON.stringify(localValues.value) !==
        JSON.stringify(appliedValues.value)
    );
});

// ── Helpers select/multi-select ───────────────────────────────────────────────

const SENTINELS = new Set(['', 'tous', 'all']);

function stripSentinels(arr: string[]): string[] {
    return arr.filter((v) => !SENTINELS.has(v));
}

function meaningfulTotal(options?: FilterOption[]): number {
    return (options ?? []).filter((o) => !SENTINELS.has(String(o.value)))
        .length;
}

// ── Logique apply / reset ─────────────────────────────────────────────────────

function buildParams(): Record<string, string | string[]> {
    const params: Record<string, string | string[]> = { ...props.baseParams };

    if (props.searchKey && search.value) {
        params[props.searchKey] = search.value;
    }

    // Filtre agence : seulement pour admin, et seulement si une sélection partielle
    if (isAdmin.value && localSiteIds.value.length > 0) {
        const totalSites = siteOptions.value.length;
        // Ne pas envoyer si tous les sites sont sélectionnés (= pas de filtre),
        // sauf s'il n'y a qu'un seul site dispo : le sélectionner est alors un
        // choix explicite, pas un "tout".
        if (totalSites <= 1 || localSiteIds.value.length < totalSites) {
            params.site_ids = localSiteIds.value;
        }
    }
    // Non-admin : le backend applique automatiquement le périmètre — rien à envoyer

    for (const field of props.fields) {
        if (field.disabled) continue;
        if (field.type === 'date-range') {
            const sk = field.startKey ?? `${field.key}_debut`;
            const ek = field.endKey ?? `${field.key}_fin`;
            if (localValues.value[sk])
                params[sk] = localValues.value[sk] as string;
            if (localValues.value[ek])
                params[ek] = localValues.value[ek] as string;
        } else if (field.type === 'multi-select' || field.type === 'select') {
            const raw = (localValues.value[field.key] as string[]) ?? [];
            const arr = stripSentinels(raw);
            const total = meaningfulTotal(field.options);
            // "Tout sélectionné = pas de filtre" n'a de sens que s'il y a
            // plusieurs options à désélectionner. Avec une seule option
            // dispo (ex: un seul "Période" existant), la sélectionner est un
            // choix explicite et doit être envoyée, sinon elle disparaît
            // silencieusement au clic sur "Appliquer".
            if (arr.length > 0 && (total <= 1 || arr.length < total)) {
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
    appliedSiteIds.value = [...localSiteIds.value];
    appliedValues.value = JSON.parse(JSON.stringify(localValues.value));
}

function resetFilters() {
    // Admin → réinitialiser la sélection agence (= toutes)
    // Non-admin → périmètre inchangé (ses sites, imposé par le backend)
    if (isAdmin.value) {
        localSiteIds.value = [];
    }

    search.value = '';
    for (const field of props.fields) {
        if (field.disabled) continue;
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

    appliedSiteIds.value = [...localSiteIds.value];
    appliedValues.value = JSON.parse(JSON.stringify(localValues.value));

    if (props.url) {
        router.get(props.url, props.baseParams, {
            preserveScroll: true,
            replace: true,
        });
    }
    emit('reset');
}

// ── Recherche — debounce 500ms, auto-apply ────────────────────────────────────

const debouncedSearchApply = useDebounceFn(() => {
    if (props.url || emit) applyFilters();
}, 500);

watch(
    search,
    () => {
        debouncedSearchApply();
    },
    { immediate: false },
);

// ── Champs inline vs drawer ───────────────────────────────────────────────────

const inlineFields = computed(() => props.fields.filter((f) => f.inline));
const drawerFields = computed(() => props.fields.filter((f) => !f.inline));

// ── Compteurs ─────────────────────────────────────────────────────────────────

function countActiveFields(fields: FilterField[]): number {
    let n = 0;
    for (const field of fields) {
        if (field.disabled) continue;
        if (field.type === 'date-range') {
            const sk = field.startKey ?? `${field.key}_debut`;
            const ek = field.endKey ?? `${field.key}_fin`;
            if (localValues.value[sk] || localValues.value[ek]) n++;
        } else if (field.type === 'multi-select' || field.type === 'select') {
            const raw = (localValues.value[field.key] as string[]) ?? [];
            const arr = stripSentinels(raw);
            const total = meaningfulTotal(field.options);
            if (arr.length > 0 && (total <= 1 || arr.length < total)) n++;
        } else if (field.type === 'boolean') {
            if (localValues.value[field.key] !== '') n++;
        } else {
            if (localValues.value[field.key]) n++;
        }
    }
    return n;
}

// Badge du drawer : seulement les filtres non-inline
const drawerFilterCount = computed(() => countActiveFields(drawerFields.value));

// Les sites verrouillés du non-admin ne comptent pas comme filtre actif
const hasActiveFilters = computed(
    () =>
        drawerFilterCount.value > 0 ||
        countActiveFields(inlineFields.value) > 0 ||
        (isAdmin.value && localSiteIds.value.length > 0) ||
        !!search.value,
);
</script>

<template>
    <FilterBar>
        <!-- Drawer filtres avancés — bouton à l'extrême gauche (uniquement les champs non-inline) -->
        <div v-if="drawerFields.length > 0" class="order-1 shrink-0">
            <FilterDrawer
                v-model:open="filterDrawerOpen"
                title="Filtres"
                :active-count="drawerFilterCount"
                :apply-disabled="!pendingChange"
                @apply="applyFilters"
                @reset="resetFilters"
            >
                <div class="space-y-5">
                    <template v-for="field in drawerFields" :key="field.key">
                        <!-- multi-select ou select -->
                        <div
                            v-if="
                                field.type === 'multi-select' ||
                                field.type === 'select'
                            "
                            :data-testid="`filter-field-${field.key}`"
                            class="space-y-1.5"
                        >
                            <Label class="flex items-center gap-1.5">
                                {{ field.label }}
                                <Lock
                                    v-if="field.disabled"
                                    class="h-3 w-3 text-muted-foreground opacity-60"
                                />
                            </Label>
                            <FilterMultiSelect
                                v-model="
                                    localValues[field.key] as (
                                        | string
                                        | number
                                    )[]
                                "
                                :options="field.options ?? []"
                                :placeholder="field.placeholder ?? 'Tous'"
                                :disabled="field.disabled ?? false"
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
                                    v-model="
                                        localValues[
                                            field.startKey ??
                                                `${field.key}_debut`
                                        ]
                                    "
                                    type="date"
                                    class="h-9"
                                />
                            </div>
                            <div class="space-y-1.5">
                                <Label>Date fin</Label>
                                <Input
                                    v-model="
                                        localValues[
                                            field.endKey ?? `${field.key}_fin`
                                        ]
                                    "
                                    type="date"
                                    class="h-9"
                                />
                            </div>
                        </div>

                        <!-- date -->
                        <div
                            v-else-if="field.type === 'date'"
                            class="space-y-1.5"
                        >
                            <Label>{{ field.label }}</Label>
                            <Input
                                v-model="localValues[field.key]"
                                type="date"
                                class="h-9"
                            />
                        </div>

                        <!-- number -->
                        <div
                            v-else-if="field.type === 'number'"
                            class="space-y-1.5"
                        >
                            <Label>{{ field.label }}</Label>
                            <Input
                                v-model.number="localValues[field.key]"
                                type="number"
                                :placeholder="field.placeholder"
                                class="h-9"
                            />
                        </div>

                        <!-- boolean -->
                        <div
                            v-else-if="field.type === 'boolean'"
                            class="space-y-1.5"
                        >
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
        </div>

        <!-- Recherche (auto-apply 500ms) — pleine largeur sur mobile (sa propre ligne) -->
        <div class="relative order-2 w-full shrink-0 sm:w-[260px]">
            <Search
                class="pointer-events-none absolute top-1/2 left-2.5 h-4 w-4 -translate-y-1/2 text-muted-foreground"
            />
            <input
                v-model="search"
                type="text"
                data-testid="search-input"
                :placeholder="searchPlaceholder"
                class="h-9 w-full rounded-md border border-input bg-background py-2 pr-7 pl-8 text-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
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

        <!-- Agence / Site générique (masqué quand la page gère ses propres filtres site) -->
        <div
            v-if="!hideAgenceSelector && siteOptions.length > 0"
            data-testid="agency-filter"
            class="relative order-3 w-[220px] shrink-0"
        >
            <FilterMultiSelect
                v-model="localSiteIds"
                :options="siteOptions"
                placeholder="Toutes les agences"
                :empty-means-all="isAdmin"
                :disabled="siteSelectorLocked"
            />
            <!-- Cadenas : indique au non-admin que le périmètre est verrouillé -->
            <Lock
                v-if="siteSelectorLocked"
                class="pointer-events-none absolute top-1/2 right-8 h-3 w-3 -translate-y-1/2 text-muted-foreground opacity-50"
            />
        </div>

        <!-- Champs inline : visibles directement dans la barre (ex: Site départ / Site arrivée) -->
        <template v-for="field in inlineFields" :key="field.key">
            <div
                v-if="field.type === 'multi-select' || field.type === 'select'"
                class="relative order-4 w-[200px] shrink-0"
            >
                <FilterMultiSelect
                    v-model="localValues[field.key] as (string | number)[]"
                    :options="field.options ?? []"
                    :placeholder="field.placeholder ?? field.label"
                    :disabled="field.disabled ?? false"
                />
                <Lock
                    v-if="field.disabled"
                    class="pointer-events-none absolute top-1/2 right-8 h-3 w-3 -translate-y-1/2 text-muted-foreground opacity-50"
                />
            </div>
        </template>

        <!-- Slot pour contrôles inline additionnels -->
        <slot name="inline" />

        <template #actions>
            <span
                data-testid="filters-result-count"
                class="shrink-0 text-xs whitespace-nowrap text-muted-foreground"
            >
                {{ resultCount }} résultat{{ resultCount !== 1 ? 's' : '' }}
            </span>
            <button
                v-if="hasActiveFilters"
                type="button"
                data-testid="filters-reset"
                class="shrink-0 text-xs text-muted-foreground underline-offset-2 hover:text-foreground hover:underline"
                @click="resetFilters"
            >
                Réinitialiser
            </button>
            <button
                type="button"
                data-testid="filters-search"
                :disabled="!pendingChange"
                class="h-9 shrink-0 rounded-md px-4 text-sm font-medium transition-colors"
                :class="
                    pendingChange
                        ? 'bg-primary text-primary-foreground hover:bg-primary/90'
                        : 'cursor-not-allowed bg-muted text-muted-foreground'
                "
                @click="applyFilters"
            >
                Rechercher
            </button>
        </template>
    </FilterBar>
</template>
