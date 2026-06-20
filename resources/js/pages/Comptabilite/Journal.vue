<script setup lang="ts">
import FilterDrawer from '@/components/FilterDrawer.vue';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/vue3';
import { ArrowDown, ArrowUp, BookOpen, Search, X } from 'lucide-vue-next';
import Column from 'primevue/column';
import DataTable from 'primevue/datatable';
import { computed, ref, watch } from 'vue';

interface Ligne {
    id: string;
    date_operation: string | null;
    sens: 'entree' | 'sortie';
    categorie: string;
    categorie_label: string;
    libelle: string;
    reference: string | null;
    montant: number;
    site: { id: string; nom: string } | null;
}

interface Option {
    value: string;
    label: string;
}

interface Site {
    id: string;
    nom: string;
}

const props = defineProps<{
    lignes: { data: Ligne[]; links: unknown[] };
    sens_options: Option[];
    categories: Option[];
    sites: Site[];
    is_admin: boolean;
    filters: {
        sens?: string;
        categorie?: string;
        date_from?: string;
        date_to?: string;
        site_id?: string;
        search?: string;
    };
    kpis: { total_entrees: number; total_sorties: number; solde: number };
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/dashboard' },
    { title: 'Comptabilité', href: '/comptabilite' },
    { title: 'Journal financier', href: '/comptabilite/journal' },
];

const filterDrawerOpen = ref(false);
const selectedSens = ref(props.filters.sens ?? '');
const selectedCategorie = ref(props.filters.categorie ?? '');
const selectedSite = ref(props.filters.site_id ?? '');
const dateFrom = ref(props.filters.date_from ?? '');
const dateTo = ref(props.filters.date_to ?? '');
const searchVal = ref(props.filters.search ?? '');

const activeFilterCount = computed(
    () =>
        [
            !!selectedSens.value,
            !!selectedCategorie.value,
            !!selectedSite.value,
            !!dateFrom.value,
            !!dateTo.value,
        ].filter(Boolean).length,
);

const hasActiveFilters = computed(
    () => !!searchVal.value || activeFilterCount.value > 0,
);

function applyFilters() {
    router.get(
        '/comptabilite/journal',
        {
            sens: selectedSens.value || undefined,
            categorie: selectedCategorie.value || undefined,
            site_id: selectedSite.value || undefined,
            date_from: dateFrom.value || undefined,
            date_to: dateTo.value || undefined,
            search: searchVal.value || undefined,
        },
        { preserveState: true, replace: true },
    );
}

function resetFilters() {
    selectedSens.value = '';
    selectedCategorie.value = '';
    selectedSite.value = '';
    dateFrom.value = '';
    dateTo.value = '';
    searchVal.value = '';
    router.get(
        '/comptabilite/journal',
        {},
        { preserveState: true, replace: true },
    );
}

let searchDebounce: ReturnType<typeof setTimeout> | null = null;
watch(searchVal, () => {
    if (searchDebounce) clearTimeout(searchDebounce);
    searchDebounce = setTimeout(applyFilters, 400);
});

function fmt(n: number) {
    return new Intl.NumberFormat('fr-FR').format(Math.round(n)) + ' GNF';
}
</script>

<template>
    <Head title="Journal financier" />
    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-col gap-6 p-6">
            <!-- Header -->
            <div>
                <h1 class="text-2xl font-semibold tracking-tight">
                    Journal financier
                </h1>
                <p class="mt-1 text-sm text-muted-foreground">
                    Tous les mouvements de trésorerie
                </p>
            </div>

            <!-- KPI strip -->
            <div class="grid gap-4 sm:grid-cols-3">
                <div class="rounded-xl border bg-card p-5 shadow-sm">
                    <div class="flex items-center gap-2">
                        <ArrowUp class="h-4 w-4 text-emerald-500" />
                        <span class="text-sm text-muted-foreground"
                            >Total entrées</span
                        >
                    </div>
                    <p
                        class="mt-2 text-2xl font-bold text-emerald-600 tabular-nums dark:text-emerald-400"
                    >
                        {{ fmt(kpis.total_entrees) }}
                    </p>
                </div>
                <div class="rounded-xl border bg-card p-5 shadow-sm">
                    <div class="flex items-center gap-2">
                        <ArrowDown class="h-4 w-4 text-red-500" />
                        <span class="text-sm text-muted-foreground"
                            >Total sorties</span
                        >
                    </div>
                    <p
                        class="mt-2 text-2xl font-bold text-red-600 tabular-nums dark:text-red-400"
                    >
                        {{ fmt(kpis.total_sorties) }}
                    </p>
                </div>
                <div class="rounded-xl border bg-card p-5 shadow-sm">
                    <div class="flex items-center gap-2">
                        <BookOpen class="h-4 w-4 text-blue-500" />
                        <span class="text-sm text-muted-foreground">Solde</span>
                    </div>
                    <p
                        class="mt-2 text-2xl font-bold tabular-nums"
                        :class="
                            kpis.solde >= 0
                                ? 'text-blue-600 dark:text-blue-400'
                                : 'text-red-600 dark:text-red-400'
                        "
                    >
                        {{ fmt(kpis.solde) }}
                    </p>
                </div>
            </div>

            <!-- Filtres -->
            <div class="flex flex-wrap items-center gap-3">
                <div class="relative w-[260px] shrink-0">
                    <Search
                        class="pointer-events-none absolute top-1/2 left-2.5 h-4 w-4 -translate-y-1/2 text-muted-foreground"
                    />
                    <input
                        v-model="searchVal"
                        type="search"
                        placeholder="Rechercher une référence, un libellé..."
                        class="h-9 w-full rounded-md border border-input bg-background py-2 pr-7 pl-8 text-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    />
                    <button
                        v-if="searchVal"
                        type="button"
                        class="absolute top-1/2 right-2 -translate-y-1/2 text-muted-foreground hover:text-foreground"
                        @click="searchVal = ''"
                    >
                        <X class="h-3.5 w-3.5" />
                    </button>
                </div>

                <FilterDrawer
                    v-model:open="filterDrawerOpen"
                    title="Filtres"
                    :active-count="activeFilterCount"
                    @apply="applyFilters"
                    @reset="resetFilters"
                >
                    <div class="space-y-1.5">
                        <Label>Sens</Label>
                        <select
                            v-model="selectedSens"
                            class="h-9 w-full rounded-md border border-input bg-background px-2 text-sm"
                        >
                            <option value="">Tous les sens</option>
                            <option
                                v-for="o in sens_options"
                                :key="o.value"
                                :value="o.value"
                            >
                                {{ o.label }}
                            </option>
                        </select>
                    </div>
                    <div class="space-y-1.5">
                        <Label>Catégorie</Label>
                        <select
                            v-model="selectedCategorie"
                            class="h-9 w-full rounded-md border border-input bg-background px-2 text-sm"
                        >
                            <option value="">Toutes les catégories</option>
                            <option
                                v-for="c in categories"
                                :key="c.value"
                                :value="c.value"
                            >
                                {{ c.label }}
                            </option>
                        </select>
                    </div>
                    <div
                        v-if="is_admin && sites.length > 0"
                        class="space-y-1.5"
                    >
                        <Label>Agence</Label>
                        <select
                            v-model="selectedSite"
                            class="h-9 w-full rounded-md border border-input bg-background px-2 text-sm"
                        >
                            <option value="">Toutes les agences</option>
                            <option
                                v-for="s in sites"
                                :key="s.id"
                                :value="s.id"
                            >
                                {{ s.nom }}
                            </option>
                        </select>
                    </div>
                    <div class="space-y-1.5">
                        <Label>Date début</Label>
                        <input
                            v-model="dateFrom"
                            type="date"
                            class="h-9 w-full rounded-md border border-input bg-background px-2 text-sm"
                        />
                    </div>
                    <div class="space-y-1.5">
                        <Label>Date fin</Label>
                        <input
                            v-model="dateTo"
                            type="date"
                            class="h-9 w-full rounded-md border border-input bg-background px-2 text-sm"
                        />
                    </div>
                </FilterDrawer>

                <span
                    class="shrink-0 text-xs whitespace-nowrap text-muted-foreground"
                >
                    {{ lignes.data.length }} résultat{{
                        lignes.data.length !== 1 ? 's' : ''
                    }}
                </span>
                <button
                    v-if="hasActiveFilters"
                    type="button"
                    class="shrink-0 text-xs text-muted-foreground underline-offset-2 hover:text-foreground hover:underline"
                    @click="resetFilters"
                >
                    Réinitialiser
                </button>
            </div>

            <!-- Table -->
            <div class="overflow-hidden rounded-xl border bg-card">
                <DataTable
                    :value="lignes.data"
                    data-key="id"
                    striped-rows
                    class="text-sm"
                >
                    <Column header="Date" style="width: 110px">
                        <template #body="{ data }">
                            <span
                                class="font-mono text-xs text-muted-foreground"
                            >
                                {{ data.date_operation ?? '—' }}
                            </span>
                        </template>
                    </Column>

                    <Column header="Référence" style="width: 140px">
                        <template #body="{ data }">
                            <span
                                class="font-mono text-xs text-muted-foreground"
                                >{{ data.reference ?? '—' }}</span
                            >
                        </template>
                    </Column>

                    <Column header="Type" style="width: 160px">
                        <template #body="{ data }">
                            <span
                                class="rounded-full bg-muted px-2 py-0.5 text-xs text-muted-foreground"
                            >
                                {{ data.categorie_label }}
                            </span>
                        </template>
                    </Column>

                    <Column header="Libellé" style="min-width: 200px">
                        <template #body="{ data }">
                            <span class="text-sm">{{ data.libelle }}</span>
                            <div
                                v-if="data.site"
                                class="text-xs text-muted-foreground"
                            >
                                {{ data.site.nom }}
                            </div>
                        </template>
                    </Column>

                    <Column header="Entrée" style="width: 150px">
                        <template #body="{ data }">
                            <span
                                v-if="data.sens === 'entree'"
                                class="flex items-center gap-1 text-sm font-medium text-emerald-600 tabular-nums dark:text-emerald-400"
                            >
                                <ArrowUp class="h-3.5 w-3.5" />
                                {{ fmt(data.montant) }}
                            </span>
                        </template>
                    </Column>

                    <Column header="Sortie" style="width: 150px">
                        <template #body="{ data }">
                            <span
                                v-if="data.sens === 'sortie'"
                                class="flex items-center gap-1 text-sm font-medium text-red-600 tabular-nums dark:text-red-400"
                            >
                                <ArrowDown class="h-3.5 w-3.5" />
                                {{ fmt(data.montant) }}
                            </span>
                        </template>
                    </Column>

                    <template #empty>
                        <div
                            class="flex flex-col items-center gap-3 py-16 text-muted-foreground"
                        >
                            <BookOpen class="h-12 w-12 opacity-30" />
                            <p class="text-sm">
                                Aucun mouvement sur la période sélectionnée.
                            </p>
                        </div>
                    </template>
                </DataTable>
            </div>
        </div>
    </AppLayout>
</template>
