<script setup lang="ts">
import FilterBar from '@/components/FilterBar.vue';
import FilterDrawer from '@/components/FilterDrawer.vue';
import FilterAutocomplete from '@/components/filters/FilterAutocomplete.vue';
import FilterMultiSelect from '@/components/filters/FilterMultiSelect.vue';
import FilterSearchSelect from '@/components/filters/FilterSearchSelect.vue';
import SavedViews, {
    type SavedView,
} from '@/components/filters/SavedViews.vue';
import { Button } from '@/components/ui/button';
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
    | 'period'
    | 'number'
    | 'boolean';

/** Valeur du champ `period` qui affiche les deux dates (bornes libres). */
const PERIODE_PERSONNALISEE = 'personnalisee';
/** Valeur du champ `period` à une seule date : envoie `startKey` = `endKey` (une journée, Periode::DATE_PRECISE). */
const PERIODE_DATE_PRECISE = 'date_precise';

export interface FilterOption {
    value: string | number;
    label: string;
    /** Texte supplémentaire pris en compte par la recherche des listes `searchable` (ex. téléphone sans espaces) */
    recherche?: string;
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
    /**
     * Pour type: 'select' — liste avec champ de recherche par nom et croix d'effacement (choix unique).
     * Pour type: 'multi-select' — ajoute un champ de recherche dans la liste déroulante.
     */
    searchable?: boolean;
    /**
     * Options calculées à partir des valeurs en cours de saisie (non encore appliquées) des
     * autres champs — ex. les véhicules du propriétaire choisi. Remplace `options` ; les choix
     * devenus absents de la liste sont retirés automatiquement.
     */
    optionsFrom?: (values: Record<string, unknown>) => FilterOption[];
    /** Champ `inline` plus large (280 px au lieu de 180) pour afficher en entier un libellé long */
    wide?: boolean;
    /** Pour type: 'autocomplete' — URL de l'endpoint de suggestions */
    suggestionsUrl?: string;
    /** Pour type: 'autocomplete' — nom du champ passé à l'endpoint (?field=xxx) */
    suggestionsField?: string;
    /**
     * Pour type: 'period' — raccourci appliqué quand rien n'est choisi (le serveur retombe dessus) :
     * ne compte pas comme un filtre actif. Les raccourcis sont résolus côté serveur (Periode, ADR
     * 0057) ; sans `options`, le champ reprend les choix standard partagés (`page.props.periodes`).
     * `personnalisee` envoie `startKey`/`endKey` (défaut `date_debut`/`date_fin`).
     */
    defaultValue?: string;
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
        /** Masque le compteur "N résultats" (pages où il n'apporte rien, ex: trésorerie) */
        hideResultCount?: boolean;
        /** N'affiche que le bouton ouvrant le drawer, pour une utilisation dans un en-tête */
        triggerOnly?: boolean;
        /** Élément où déplacer le bouton « Filtres » (ex. en-tête, à côté de « Nouveau ») ; les champs `inline` restent dans la barre */
        triggerTarget?: HTMLElement | null;
        savedFilterScope?: string;
        /**
         * Avec `triggerOnly` : les champs `inline` sont affichés par la barre rapide du tableau
         * (QuickFilters.vue), pas dans le panneau. Ils restent conservés à l'application du panneau,
         * ne comptent pas dans son badge et ne sont pas effacés par son « Réinitialiser ».
         */
        quickBar?: boolean;
    }>(),
    {
        baseParams: () => ({}),
        values: () => ({}),
        sites: () => [],
        url: undefined,
        hideAgenceSelector: false,
        hideResultCount: false,
        triggerOnly: false,
        triggerTarget: null,
        quickBar: false,
    },
);

const emit = defineEmits<{
    apply: [values: Record<string, unknown>];
    reset: [];
}>();

// ── Périmètre de consultation des agences ─────────────────────────────────────

const page = usePage();
const savedViews = ref<InstanceType<typeof SavedViews> | null>(null);
const activeSavedView = computed(
    () => (page.props.saved_view as SavedView | null) ?? null,
);

function applySavedView(view: SavedView) {
    filterDrawerOpen.value = false;
    if (props.url)
        router.get(
            props.url,
            { ...props.baseParams, saved_view: view.id },
            { preserveScroll: true, replace: true },
        );
}

function describeSavedFilters(
    values: Record<string, string | string[]>,
): string {
    const labels: string[] = [];
    if (values.site_scope === 'mine') labels.push('Mes agences');
    else if (Array.isArray(values.site_ids)) {
        labels.push(
            ...values.site_ids.map(
                (id) =>
                    siteOptions.value.find((s) => s.value === id)?.label ??
                    'Agence indisponible',
            ),
        );
    }
    for (const field of props.fields) {
        const value = values[field.key];
        if (value === undefined || value === '') continue;
        const selected = Array.isArray(value) ? value : [value];
        labels.push(
            `${field.label} : ${selected.map((v) => fieldOptions(field).find((o) => String(o.value) === String(v))?.label ?? (field.options || field.optionsFrom ? 'Choix indisponible' : v)).join(', ')}`,
        );
    }
    return labels.join(' · ');
}

// Filtre Agence libre : l'utilisateur consulte toutes les agences (administrateur ou permission
// « vision 360° », ADR 0025). Calculé par le backend (auth.voit_toutes_agences), jamais déduit du
// nom d'un rôle. Ne concerne que la consultation.
const toutesAgences = computed(() => {
    const auth = (page.props as Record<string, unknown>).auth as
        | Record<string, unknown>
        | undefined;
    return auth?.voit_toutes_agences === true;
});

// Sites de l'utilisateur connecté (vide s'il consulte toutes les agences, non-vide sinon)
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
// - toutes les agences consultables → tous les sites de l'organisation
// - sinon → uniquement ses sites
const siteOptions = computed(() => {
    if (!toutesAgences.value && authUserSites.value.length > 0) {
        return authUserSites.value.map((s) => ({ value: s.id, label: s.nom }));
    }
    return effectiveSites.value.map((s) => ({ value: s.id, label: s.nom }));
});

// Périmètre limité à ses agences : sélecteur verrouillé (périmètre imposé par le backend)
const siteSelectorLocked = computed(() => !toutesAgences.value);

// ── État local ────────────────────────────────────────────────────────────────

const filterDrawerOpen = ref(false);
const localSiteIds = ref<string[]>([]);
const localValues = ref<Record<string, unknown>>({});

// Snapshots de l'état au dernier apply (pour calculer pendingChange)
const appliedSiteIds = ref<string[]>([]);
const appliedValues = ref<Record<string, unknown>>({});

function periodKeys(field: FilterField): [string, string] {
    return [field.startKey ?? 'date_debut', field.endKey ?? 'date_fin'];
}

function toArray(val: unknown): string[] {
    if (Array.isArray(val)) return val.map(String);
    if (typeof val === 'string' && val) return [val];
    return [];
}

function initLocal() {
    const v = props.values ?? {};

    if (!toutesAgences.value && authUserSites.value.length > 0) {
        localSiteIds.value = authUserSites.value.map((s) => s.id);
    } else {
        localSiteIds.value = toArray(v.site_ids);
    }

    const fresh: Record<string, unknown> = {};
    for (const field of props.fields) {
        if (field.type === 'period') {
            const [sk, ek] = periodKeys(field);
            fresh[field.key] =
                (v[field.key] as string) || (field.defaultValue ?? '');
            fresh[sk] = (v[sk] as string) ?? '';
            fresh[ek] = (v[ek] as string) ?? '';
        } else if (field.type === 'date-range') {
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

const periodesStandard = computed<FilterOption[]>(() => {
    const periodes = (page.props as Record<string, unknown>).periodes;
    return Array.isArray(periodes) ? (periodes as FilterOption[]) : [];
});

function fieldOptions(field: FilterField): FilterOption[] {
    if (field.optionsFrom) return field.optionsFrom(localValues.value);
    if (field.type === 'period' && !field.options)
        return periodesStandard.value;
    return field.options ?? [];
}

// ── Période personnalisée : date de début obligatoire, fin vide = aujourd'hui ─
// Même règle que le serveur (Periode::regles) : rien n'est envoyé tant qu'elle n'est pas
// respectée, et une erreur renvoyée par le serveur reste affichée sous le champ. « Aujourd'hui »
// est résolu par le serveur (ADR 0057), jamais par l'horloge du navigateur.

function erreurLocalePeriode(field: FilterField): string | null {
    const [sk, ek] = periodKeys(field);
    if (localValues.value[field.key] === PERIODE_DATE_PRECISE)
        return localValues.value[sk] ? null : 'Renseignez la date.';
    if (localValues.value[field.key] !== PERIODE_PERSONNALISEE) return null;
    const debut = (localValues.value[sk] as string) || '';
    const fin = (localValues.value[ek] as string) || '';
    if (!debut) return 'Renseignez la date de début.';
    if (!fin) return null;
    return debut > fin
        ? 'La date de fin doit être postérieure ou égale à la date de début.'
        : null;
}

function erreurPeriode(field: FilterField): string | null {
    const locale = erreurLocalePeriode(field);
    if (locale) return locale;
    const errors = ((page.props as Record<string, unknown>).errors ??
        {}) as Record<string, string>;
    const [sk, ek] = periodKeys(field);
    return errors[field.key] ?? errors[sk] ?? errors[ek] ?? null;
}

/** L'écran propose des dates libres (sinon : raccourcis seuls, voir `REGISTRE_SANS_DATES`). */
function accepteDates(field: FilterField): boolean {
    return fieldOptions(field).some(
        (o) => String(o.value) === PERIODE_PERSONNALISEE,
    );
}

function choisirPeriode(field: FilterField, choix: unknown) {
    localValues.value[field.key] = choix ?? '';
    if (choix === PERIODE_PERSONNALISEE) return;
    const [sk, ek] = periodKeys(field);
    // Date précise : la date de début déjà saisie devient la journée choisie.
    if (choix === PERIODE_DATE_PRECISE) {
        localValues.value[ek] = localValues.value[sk] ?? '';
        return;
    }
    localValues.value[sk] = '';
    localValues.value[ek] = '';
}

// Date précise : une journée = date de début et date de fin identiques (jamais une fin ouverte).
function saisirDatePrecise(field: FilterField, date: unknown) {
    const [sk, ek] = periodKeys(field);
    localValues.value[sk] = (date as string) ?? '';
    localValues.value[ek] = (date as string) ?? '';
}

// Saisir une date bascule sur « Période personnalisée » ; vider les deux revient au défaut.
function saisirDatePeriode(field: FilterField, borne: 0 | 1, date: unknown) {
    const [sk, ek] = periodKeys(field);
    localValues.value[borne === 0 ? sk : ek] = (date as string) ?? '';
    if (localValues.value[sk] || localValues.value[ek]) {
        localValues.value[field.key] = PERIODE_PERSONNALISEE;
    } else if (localValues.value[field.key] === PERIODE_PERSONNALISEE) {
        localValues.value[field.key] = field.defaultValue ?? '';
    }
}

const periodeInvalide = computed(() =>
    props.fields.some(
        (f) =>
            f.type === 'period' &&
            !f.disabled &&
            erreurLocalePeriode(f) !== null,
    ),
);

// Un choix qui n'existe plus dans des options dépendantes (ex. véhicule d'un autre propriétaire)
// ne doit jamais partir silencieusement au serveur.
watch(
    localValues,
    () => {
        for (const field of props.fields) {
            if (!field.optionsFrom) continue;
            const current = localValues.value[field.key];
            if (!Array.isArray(current) || current.length === 0) continue;
            const valid = new Set(
                fieldOptions(field).map((o) => String(o.value)),
            );
            const kept = current.filter((v) => valid.has(String(v)));
            if (kept.length !== current.length) {
                localValues.value[field.key] = kept;
            }
        }
    },
    { deep: true, immediate: true },
);

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

    // Filtre agence : seulement si toutes les agences sont consultables, et seulement si une sélection partielle
    if (toutesAgences.value && localSiteIds.value.length > 0) {
        const totalSites = siteOptions.value.length;
        if (totalSites <= 1 || localSiteIds.value.length < totalSites) {
            params.site_ids = localSiteIds.value;
        }
    }

    for (const field of props.fields) {
        if (field.disabled) continue;
        if (field.type === 'period') {
            const [sk, ek] = periodKeys(field);
            const choix = localValues.value[field.key] as string;
            if (choix === PERIODE_DATE_PRECISE) {
                const jour = localValues.value[sk] as string;
                if (jour) {
                    params[sk] = jour;
                    params[ek] = jour;
                }
            } else if (choix === PERIODE_PERSONNALISEE) {
                if (localValues.value[sk])
                    params[sk] = localValues.value[sk] as string;
                if (localValues.value[ek])
                    params[ek] = localValues.value[ek] as string;
            } else if (choix && choix !== field.defaultValue) {
                params[field.key] = choix;
            }
        } else if (field.type === 'date-range') {
            const sk = field.startKey ?? `${field.key}_debut`;
            const ek = field.endKey ?? `${field.key}_fin`;
            if (localValues.value[sk])
                params[sk] = localValues.value[sk] as string;
            if (localValues.value[ek])
                params[ek] = localValues.value[ek] as string;
        } else if (field.type === 'multi-select') {
            const raw = (localValues.value[field.key] as string[]) ?? [];
            const arr = stripSentinels(raw);
            const total = meaningfulTotal(fieldOptions(field));
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
    if (periodeInvalide.value) return;
    const values = buildParams();
    if (props.url) {
        router.get(
            props.url,
            { ...values, ...(props.savedFilterScope ? { all: '1' } : {}) },
            { preserveScroll: true, replace: true },
        );
    }
    emit('apply', values);
    appliedSiteIds.value = [...localSiteIds.value];
    appliedValues.value = JSON.parse(JSON.stringify(localValues.value));
}

/** `toutEffacer` : retrait d'une vue enregistrée — vide aussi la barre rapide. */
function resetFilters(toutEffacer = false) {
    const garderBarreRapide = separateQuickFields.value && !toutEffacer;
    if (toutesAgences.value) {
        localSiteIds.value = [];
    }

    for (const field of props.fields) {
        if (field.disabled) continue;
        if (garderBarreRapide && field.inline) continue;
        if (field.type === 'period') {
            const [sk, ek] = periodKeys(field);
            localValues.value[field.key] = field.defaultValue ?? '';
            localValues.value[sk] = '';
            localValues.value[ek] = '';
        } else if (field.type === 'date-range') {
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

    // Les champs de la barre rapide survivent au « Réinitialiser » du panneau.
    if (garderBarreRapide) {
        applyFilters();
        emit('reset');
        return;
    }

    appliedSiteIds.value = [...localSiteIds.value];
    appliedValues.value = JSON.parse(JSON.stringify(localValues.value));

    if (props.url) {
        router.get(
            props.url,
            {
                ...props.baseParams,
                ...(props.savedFilterScope ? { all: '1' } : {}),
            },
            {
                preserveScroll: true,
                replace: true,
            },
        );
    }
    emit('reset');
}

// ── Champs inline vs drawer ───────────────────────────────────────────────────

const inlineFields = computed(() => props.fields.filter((f) => f.inline));
const drawerFields = computed(() => props.fields.filter((f) => !f.inline));
const separateQuickFields = computed(() => props.triggerOnly && props.quickBar);
const displayedDrawerFields = computed(() =>
    props.triggerOnly && !props.quickBar ? props.fields : drawerFields.value,
);

// ── Compteurs ─────────────────────────────────────────────────────────────────

function countActiveFields(fields: FilterField[]): number {
    let n = 0;
    for (const field of fields) {
        if (field.disabled) continue;
        if (field.type === 'period') {
            const choix = localValues.value[field.key];
            if (choix && choix !== field.defaultValue) n++;
        } else if (field.type === 'date-range') {
            const sk = field.startKey ?? `${field.key}_debut`;
            const ek = field.endKey ?? `${field.key}_fin`;
            if (localValues.value[sk] || localValues.value[ek]) n++;
        } else if (field.type === 'multi-select' || field.type === 'select') {
            const raw = (localValues.value[field.key] as string[]) ?? [];
            const arr = stripSentinels(raw);
            const total = meaningfulTotal(fieldOptions(field));
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

const activeFilterCount = computed(
    () =>
        drawerFilterCount.value +
        (separateQuickFields.value
            ? 0
            : countActiveFields(inlineFields.value)) +
        (toutesAgences.value && localSiteIds.value.length > 0 ? 1 : 0),
);

const hasActiveFilters = computed(
    () =>
        drawerFilterCount.value > 0 ||
        countActiveFields(inlineFields.value) > 0 ||
        (toutesAgences.value && localSiteIds.value.length > 0),
);
</script>

<template>
    <FilterBar :unstyled="triggerOnly">
        <!-- ── 1. Agence / Site ── toujours en premier ──────────────────────── -->
        <div
            v-if="!triggerOnly && !hideAgenceSelector && siteOptions.length > 0"
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
                    :empty-means-all="toutesAgences"
                    :disabled="siteSelectorLocked"
                />
                <Lock
                    v-if="siteSelectorLocked"
                    class="pointer-events-none absolute top-1/2 right-8 h-3 w-3 -translate-y-1/2 text-muted-foreground opacity-50"
                />
            </div>
        </div>

        <!-- ── 2. Champs inline ──────────────────────────────────────────────── -->
        <template
            v-for="field in triggerOnly ? [] : inlineFields"
            :key="field.key"
        >
            <!-- select / multi-select -->
            <div
                v-if="field.type === 'multi-select' || field.type === 'select'"
                :data-testid="`filter-inline-${field.key}`"
                class="relative flex shrink-0 flex-col gap-1"
            >
                <span class="text-xs font-medium text-muted-foreground">{{
                    field.label
                }}</span>
                <div
                    class="relative"
                    :class="field.wide ? 'w-[280px]' : 'w-[180px]'"
                >
                    <FilterSearchSelect
                        v-if="field.searchable && field.type === 'select'"
                        v-model="localValues[field.key] as (string | number)[]"
                        :options="fieldOptions(field)"
                        :placeholder="field.placeholder ?? field.label"
                        :disabled="field.disabled ?? false"
                    />
                    <FilterMultiSelect
                        v-else
                        v-model="localValues[field.key] as (string | number)[]"
                        :options="fieldOptions(field)"
                        :placeholder="field.placeholder ?? field.label"
                        :disabled="field.disabled ?? false"
                        :single-select="field.type === 'select'"
                        :filter="field.searchable ?? false"
                    />
                    <Lock
                        v-if="field.disabled"
                        class="pointer-events-none absolute top-1/2 right-8 h-3 w-3 -translate-y-1/2 text-muted-foreground opacity-50"
                    />
                </div>
            </div>

            <!-- period : raccourcis résolus côté serveur + dates si personnalisée -->
            <div
                v-else-if="field.type === 'period'"
                :data-testid="`filter-inline-${field.key}`"
                class="flex max-w-full basis-full flex-wrap items-end gap-2 sm:shrink-0 sm:basis-auto"
            >
                <div class="flex flex-col gap-1">
                    <span class="text-xs font-medium text-muted-foreground">{{
                        field.label
                    }}</span>
                    <Select
                        :model-value="localValues[field.key]"
                        :options="fieldOptions(field)"
                        option-label="label"
                        option-value="value"
                        :aria-label="field.label"
                        :disabled="field.disabled ?? false"
                        scroll-height="26rem"
                        size="small"
                        class="h-9 w-[200px] text-sm"
                        @update:model-value="choisirPeriode(field, $event)"
                    />
                </div>
                <template
                    v-if="localValues[field.key] === PERIODE_PERSONNALISEE"
                >
                    <div class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-muted-foreground"
                            >Du</span
                        >
                        <input
                            :value="localValues[periodKeys(field)[0]]"
                            type="date"
                            @input="
                                saisirDatePeriode(
                                    field,
                                    0,
                                    ($event.target as HTMLInputElement).value,
                                )
                            "
                            aria-label="Date de début"
                            :data-testid="`filter-inline-${field.key}-debut`"
                            class="h-9 w-[150px] rounded-md border border-input bg-background px-3 text-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                        />
                    </div>
                    <div class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-muted-foreground"
                            >Au (vide = aujourd'hui)</span
                        >
                        <input
                            :value="localValues[periodKeys(field)[1]]"
                            type="date"
                            @input="
                                saisirDatePeriode(
                                    field,
                                    1,
                                    ($event.target as HTMLInputElement).value,
                                )
                            "
                            aria-label="Date de fin"
                            :data-testid="`filter-inline-${field.key}-fin`"
                            class="h-9 w-[150px] rounded-md border border-input bg-background px-3 text-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                        />
                    </div>
                </template>
                <div
                    v-else-if="localValues[field.key] === PERIODE_DATE_PRECISE"
                    class="flex flex-col gap-1"
                >
                    <span class="text-xs font-medium text-muted-foreground"
                        >Date</span
                    >
                    <input
                        :value="localValues[periodKeys(field)[0]]"
                        type="date"
                        @input="
                            saisirDatePrecise(
                                field,
                                ($event.target as HTMLInputElement).value,
                            )
                        "
                        aria-label="Date"
                        :data-testid="`filter-inline-${field.key}-date`"
                        class="h-9 w-[150px] rounded-md border border-input bg-background px-3 text-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    />
                </div>
                <p
                    v-if="erreurPeriode(field)"
                    role="alert"
                    :data-testid="`filter-${field.key}-erreur`"
                    class="basis-full text-xs text-destructive"
                >
                    {{ erreurPeriode(field) }}
                </p>
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

            <!-- number -->
            <div
                v-else-if="field.type === 'number'"
                class="flex shrink-0 flex-col gap-1"
            >
                <span class="text-xs font-medium text-muted-foreground">{{
                    field.label
                }}</span>
                <input
                    v-model.number="localValues[field.key]"
                    type="number"
                    inputmode="decimal"
                    :data-testid="`filter-inline-${field.key}`"
                    :placeholder="field.placeholder ?? ''"
                    :disabled="field.disabled ?? false"
                    class="h-9 w-[180px] [appearance:textfield] rounded-md border border-input bg-background px-3 py-2 text-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:opacity-50 [&::-webkit-inner-spin-button]:appearance-none [&::-webkit-outer-spin-button]:appearance-none"
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
        <slot v-if="!triggerOnly" name="inline" />

        <!-- ── 3. Bouton Filtres (drawer) ── toujours en dernier ────────────── -->
        <Teleport :to="triggerTarget" :disabled="!triggerTarget">
            <SavedViews
                v-if="savedFilterScope && url"
                ref="savedViews"
                :scope="savedFilterScope"
                :hide-agence-selector="hideAgenceSelector"
                :active="activeSavedView"
                :get-filters="buildParams"
                :describe="describeSavedFilters"
                @apply="applySavedView"
                @clear="resetFilters(true)"
            />
            <div
                v-if="
                    displayedDrawerFields.length > 0 ||
                    (triggerOnly &&
                        !hideAgenceSelector &&
                        siteOptions.length > 0)
                "
                :class="triggerTarget ? 'shrink-0' : 'shrink-0 self-end'"
            >
                <FilterDrawer
                    v-model:open="filterDrawerOpen"
                    title="Filtres"
                    :active-count="
                        triggerOnly ? activeFilterCount : drawerFilterCount
                    "
                    :apply-disabled="!pendingChange || periodeInvalide"
                    @apply="applyFilters"
                    @reset="resetFilters()"
                >
                    <div class="space-y-5">
                        <Button
                            v-if="savedFilterScope && url"
                            variant="outline"
                            class="w-full"
                            @click="
                                filterDrawerOpen = false;
                                savedViews?.startCreate();
                            "
                            >Enregistrer cette vue</Button
                        >
                        <div
                            v-if="
                                triggerOnly &&
                                !hideAgenceSelector &&
                                siteOptions.length > 0
                            "
                            data-testid="agency-filter"
                            class="space-y-1.5"
                        >
                            <Label class="flex items-center gap-1.5">
                                Agence
                                <Lock
                                    v-if="siteSelectorLocked"
                                    class="h-3 w-3 text-muted-foreground opacity-60"
                                />
                            </Label>
                            <FilterMultiSelect
                                v-model="localSiteIds"
                                :options="siteOptions"
                                placeholder="Toutes les agences"
                                :empty-means-all="toutesAgences"
                                :disabled="siteSelectorLocked"
                            />
                        </div>

                        <template
                            v-for="field in displayedDrawerFields"
                            :key="field.key"
                        >
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
                                <FilterSearchSelect
                                    v-if="
                                        field.searchable &&
                                        field.type === 'select'
                                    "
                                    v-model="
                                        localValues[field.key] as (
                                            | string
                                            | number
                                        )[]
                                    "
                                    :options="fieldOptions(field)"
                                    :placeholder="field.placeholder ?? 'Tous'"
                                    :disabled="field.disabled ?? false"
                                />
                                <FilterMultiSelect
                                    v-else
                                    v-model="
                                        localValues[field.key] as (
                                            | string
                                            | number
                                        )[]
                                    "
                                    :options="fieldOptions(field)"
                                    :placeholder="field.placeholder ?? 'Tous'"
                                    :disabled="field.disabled ?? false"
                                    :single-select="field.type === 'select'"
                                    :filter="field.searchable ?? false"
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
                                            ] as string | number | undefined
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
                                                field.endKey ??
                                                    `${field.key}_fin`
                                            ] as string | number | undefined
                                        "
                                        type="date"
                                        class="h-9"
                                    />
                                </div>
                            </div>

                            <!-- period -->
                            <div
                                v-else-if="field.type === 'period'"
                                :data-testid="`filter-field-${field.key}`"
                                class="space-y-1.5"
                            >
                                <Label>{{ field.label }}</Label>
                                <!-- append-to="self" : la liste reste dans le panneau modal, sinon ses choix ne sont pas cliquables -->
                                <Select
                                    :model-value="localValues[field.key]"
                                    :options="fieldOptions(field)"
                                    option-label="label"
                                    option-value="value"
                                    :aria-label="field.label"
                                    append-to="self"
                                    scroll-height="26rem"
                                    class="w-full"
                                    fluid
                                    @update:model-value="
                                        choisirPeriode(field, $event)
                                    "
                                />
                                <!-- Date précise : une seule date (début = fin) -->
                                <div
                                    v-if="
                                        localValues[field.key] ===
                                        PERIODE_DATE_PRECISE
                                    "
                                    class="space-y-1"
                                >
                                    <span class="text-xs text-muted-foreground"
                                        >Date</span
                                    >
                                    <Input
                                        :model-value="
                                            String(
                                                localValues[
                                                    periodKeys(field)[0]
                                                ] ?? '',
                                            )
                                        "
                                        type="date"
                                        aria-label="Date"
                                        :data-testid="`filter-field-${field.key}-date`"
                                        class="h-9"
                                        @update:model-value="
                                            saisirDatePrecise(field, $event)
                                        "
                                    />
                                </div>
                                <!-- Dates toujours visibles : en saisir une passe en « Période personnalisée » -->
                                <div
                                    v-else-if="accepteDates(field)"
                                    class="grid grid-cols-2 gap-2"
                                >
                                    <div class="space-y-1">
                                        <span
                                            class="text-xs text-muted-foreground"
                                            >Du</span
                                        >
                                        <Input
                                            :model-value="
                                                String(
                                                    localValues[
                                                        periodKeys(field)[0]
                                                    ] ?? '',
                                                )
                                            "
                                            type="date"
                                            aria-label="Date de début"
                                            :data-testid="`filter-field-${field.key}-debut`"
                                            class="h-9"
                                            @update:model-value="
                                                saisirDatePeriode(
                                                    field,
                                                    0,
                                                    $event,
                                                )
                                            "
                                        />
                                    </div>
                                    <div class="space-y-1">
                                        <span
                                            class="text-xs text-muted-foreground"
                                            >Au (vide = aujourd'hui)</span
                                        >
                                        <Input
                                            :model-value="
                                                String(
                                                    localValues[
                                                        periodKeys(field)[1]
                                                    ] ?? '',
                                                )
                                            "
                                            type="date"
                                            aria-label="Date de fin"
                                            :data-testid="`filter-field-${field.key}-fin`"
                                            class="h-9"
                                            @update:model-value="
                                                saisirDatePeriode(
                                                    field,
                                                    1,
                                                    $event,
                                                )
                                            "
                                        />
                                    </div>
                                </div>
                                <p
                                    v-if="erreurPeriode(field)"
                                    role="alert"
                                    :data-testid="`filter-${field.key}-erreur`"
                                    class="text-xs text-destructive"
                                >
                                    {{ erreurPeriode(field) }}
                                </p>
                            </div>

                            <!-- date -->
                            <div
                                v-else-if="field.type === 'date'"
                                class="space-y-1.5"
                            >
                                <Label>{{ field.label }}</Label>
                                <Input
                                    v-model="
                                        localValues[field.key] as
                                            | string
                                            | number
                                            | undefined
                                    "
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
                                    v-model.number="
                                        localValues[field.key] as
                                            | string
                                            | number
                                            | undefined
                                    "
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
                                    append-to="self"
                                    class="w-full"
                                    fluid
                                />
                            </div>

                            <!-- autocomplete -->
                            <div
                                v-else-if="
                                    field.type === 'autocomplete' &&
                                    field.suggestionsUrl
                                "
                                :data-testid="`filter-field-${field.key}`"
                            >
                                <FilterAutocomplete
                                    v-model="localValues[field.key] as string"
                                    :label="field.label"
                                    :suggestions-url="field.suggestionsUrl"
                                    :field-name="
                                        field.suggestionsField ?? field.key
                                    "
                                    :placeholder="field.placeholder ?? ''"
                                    :disabled="field.disabled ?? false"
                                    full-width
                                />
                            </div>

                            <!-- text -->
                            <div
                                v-else
                                :data-testid="`filter-field-${field.key}`"
                                class="space-y-1.5"
                            >
                                <Label>{{ field.label }}</Label>
                                <Input
                                    v-model="
                                        localValues[field.key] as
                                            | string
                                            | number
                                            | undefined
                                    "
                                    type="text"
                                    :placeholder="field.placeholder ?? ''"
                                    class="h-9"
                                    @keydown.enter="
                                        applyFilters();
                                        filterDrawerOpen = false;
                                    "
                                />
                            </div>
                        </template>
                    </div>
                </FilterDrawer>
            </div>
        </Teleport>

        <template v-if="!triggerOnly" #actions>
            <span
                v-if="!hideResultCount"
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
                @click="resetFilters()"
            >
                Réinitialiser
            </button>
            <button
                type="button"
                data-testid="filters-search"
                :disabled="!pendingChange || periodeInvalide"
                class="h-9 shrink-0 rounded-md px-4 text-sm font-medium transition-colors"
                :class="
                    pendingChange && !periodeInvalide
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
