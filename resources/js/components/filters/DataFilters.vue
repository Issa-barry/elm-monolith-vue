<script setup lang="ts">
import FilterBar from '@/components/FilterBar.vue';
import FilterDrawer from '@/components/FilterDrawer.vue';
import FilterAutocomplete from '@/components/filters/FilterAutocomplete.vue';
import FilterMultiSelect from '@/components/filters/FilterMultiSelect.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { router, usePage } from '@inertiajs/vue3';
import { Lock } from 'lucide-vue-next';
import Select from 'primevue/select';
import { computed, ref, watch } from 'vue';

// ── Types ─────────────────────────────────────────────────────────────────────

export type FilterFieldType =
    | 'text'
    | 'autocomplete'
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
    /** Pour type: 'autocomplete' — URL de l'endpoint de suggestions */
    suggestionsUrl?: string;
    /** Pour type: 'autocomplete' — nom du champ passé à l'endpoint (?field=xxx) */
    suggestionsField?: string;
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
        resultCount: number;
        fields: FilterField[];
        /** Masque le sélecteur générique Agence/Site */
        hideAgenceSelector?: boolean;
    }>(),
    {
        baseParams: () => ({}),
        values: () => ({}),
        sites: () => [],
        url: undefined,
        hideAgenceSelector: false,
    },
);

const emit = defineEmits<{
    apply: [values: Record<string, unknown>];
    reset: [];
}>();

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
        localSiteIds.value = authUserSites.value.map((s) => s.id);
    } else {
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

    // Filtre agence : seulement pour admin, et seulement si une sélection partielle
    if (isAdmin.value && localSiteIds.value.length > 0) {
        const totalSites = siteOptions.value.length;
        if (totalSites <= 1 || localSiteIds.value.length < totalSites) {
            params.site_ids = localSiteIds.value;
        }
    }

    for (const field of props.fields) {
        if (field.disabled) continue;
        if (field.type === 'date-range') {
            const sk = field.startKey ?? `${field.key}_debut`;
            const ek = field.endKey ?? `${field.key}_fin`;
            if (localValues.value[sk])
                params[sk] = localValues.value[sk] as string;
            if (localValues.value[ek])
                params[ek] = localValues.value[ek] as string;
        } else if (field.type === 'multi-select') {
            const raw = (localValues.value[field.key] as string[]) ?? [];
            const arr = stripSentinels(raw);
            const total = meaningfulTotal(field.options);
            if (arr.length > 0 && (total <= 1 || arr.length < total)) {
                params[field.key] = arr;
            }
        } else if (field.type === 'select') {
            const raw = (localValues.value[field.key] as string[]) ?? [];
            const arr = stripSentinels(raw);
            if (arr.length > 0) {
                params[field.key] = arr[0];
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
    if (isAdmin.value) {
        localSiteIds.value = [];
    }

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

const drawerFilterCount = computed(() => countActiveFields(drawerFields.value));

const hasActiveFilters = computed(
    () =>
        drawerFilterCount.value > 0 ||
        countActiveFields(inlineFields.value) > 0 ||
        (isAdmin.value && localSiteIds.value.length > 0),
);
</script>

<template>
    <FilterBar>
        <!-- ── 1. Agence / Site ── toujours en premier ──────────────────────── -->
        <div
            v-if="!hideAgenceSelector && siteOptions.length > 0"
            data-testid="agency-filter"
            class="relative flex shrink-0 flex-col gap-1"
        >
            <span class="text-xs font-medium text-muted-foreground"
                >Agence</span
            >
            <div class="relative w-[200px]">
                <FilterMultiSelect
                    v-model="localSiteIds"
                    :options="siteOptions"
                    placeholder="Toutes les agences"
                    :empty-means-all="isAdmin"
                    :disabled="siteSelectorLocked"
                />
                <Lock
                    v-if="siteSelectorLocked"
                    class="pointer-events-none absolute top-1/2 right-8 h-3 w-3 -translate-y-1/2 text-muted-foreground opacity-50"
                />
            </div>
        </div>

        <!-- ── 2. Champs inline ──────────────────────────────────────────────── -->
        <template v-for="field in inlineFields" :key="field.key">
            <!-- select / multi-select -->
            <div
                v-if="field.type === 'multi-select' || field.type === 'select'"
                :data-testid="`filter-inline-${field.key}`"
                class="relative flex shrink-0 flex-col gap-1"
            >
                <span class="text-xs font-medium text-muted-foreground">{{
                    field.label
                }}</span>
                <div class="relative w-[180px]">
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
            </div>

            <!-- text -->
            <div
                v-else-if="field.type === 'text'"
                class="flex shrink-0 flex-col gap-1"
            >
                <span class="text-xs font-medium text-muted-foreground">{{
                    field.label
                }}</span>
                <input
                    v-model="localValues[field.key]"
                    type="text"
                    :data-testid="`filter-inline-${field.key}`"
                    :placeholder="field.placeholder ?? ''"
                    :disabled="field.disabled ?? false"
                    class="h-9 w-[180px] rounded-md border border-input bg-background px-3 py-2 text-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:opacity-50"
                    @keydown.enter="applyFilters"
                />
            </div>

            <!-- autocomplete -->
            <FilterAutocomplete
                v-else-if="
                    field.type === 'autocomplete' && field.suggestionsUrl
                "
                v-model="localValues[field.key] as string"
                :label="field.label"
                :suggestions-url="field.suggestionsUrl"
                :field-name="field.suggestionsField ?? field.key"
                :placeholder="field.placeholder ?? ''"
                :disabled="field.disabled ?? false"
                @select="applyFilters"
            />
        </template>

        <!-- ── Slot pour contrôles inline additionnels ───────────────────────── -->
        <slot name="inline" />

        <!-- ── 3. Bouton Filtres (drawer) ── toujours en dernier ────────────── -->
        <div v-if="drawerFields.length > 0" class="shrink-0 self-end">
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

                        <!-- text -->
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
                Appliquer
            </button>
        </template>
    </FilterBar>
</template>
