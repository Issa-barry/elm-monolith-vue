<script setup lang="ts">
import FilterDrawer from '@/components/FilterDrawer.vue';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import { Download, ReceiptText, Search, X } from 'lucide-vue-next';
import Column from 'primevue/column';
import DataTable from 'primevue/datatable';
import { computed, ref, watch } from 'vue';

interface Fiche {
    id: string;
    reference: string;
    beneficiaire_nom: string;
    beneficiaire_type: string;
    site: { id: string; nom: string } | null;
    periode_reference: string | null;
    periode_id: string | null;
    montant_brut: number;
    total_deductions: number;
    montant_net: number;
    montant_paye: number;
    montant_restant: number;
    statut: string;
    statut_label: string;
    mode_paiement: string | null;
    date_paiement: string | null;
}

interface Option {
    value: string;
    label: string;
}

interface Site {
    id: string;
    nom: string;
}

interface PeriodeOption {
    id: string;
    reference: string;
}

interface Stats {
    nb_a_payer: number;
    nb_partiellement_paye: number;
    nb_paye: number;
    total_net: number;
    total_paye: number;
}

const props = defineProps<{
    type: 'livreur' | 'proprietaire' | 'salarie';
    fiches: { data: Fiche[]; links: unknown[] };
    sites: Site[];
    periodes: PeriodeOption[];
    statuts: Option[];
    filters: {
        site_id?: string;
        statut?: string;
        periode_id?: string;
        search?: string;
    };
    stats: Stats;
}>();

const typeRoute = {
    livreur: '/comptabilite/fiches/livreurs',
    proprietaire: '/comptabilite/fiches/proprietaires',
    salarie: '/comptabilite/fiches/salaries',
};

const typeTitle = {
    livreur: 'Paiements livreurs',
    proprietaire: 'Paiements propriétaires',
    salarie: 'Paiements salariés',
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/dashboard' },
    { title: 'Comptabilité', href: '/comptabilite' },
    { title: typeTitle[props.type], href: typeRoute[props.type] },
];

const filterDrawerOpen = ref(false);
const searchInput = ref(props.filters.search ?? '');
const selectedSite = ref(props.filters.site_id ?? '');
const selectedStatut = ref(props.filters.statut ?? '');
const selectedPeriode = ref(props.filters.periode_id ?? '');

const activeFilterCount = computed(
    () =>
        [
            !!selectedSite.value,
            !!selectedStatut.value,
            !!selectedPeriode.value,
        ].filter(Boolean).length,
);

const hasActiveFilters = computed(
    () => !!searchInput.value || activeFilterCount.value > 0,
);

function applyFilters() {
    router.get(
        typeRoute[props.type],
        {
            search: searchInput.value || undefined,
            site_id: selectedSite.value || undefined,
            statut: selectedStatut.value || undefined,
            periode_id: selectedPeriode.value || undefined,
        },
        { preserveState: true, replace: true },
    );
}

function resetFilters() {
    searchInput.value = '';
    selectedSite.value = '';
    selectedStatut.value = '';
    selectedPeriode.value = '';
    router.get(
        typeRoute[props.type],
        {},
        { preserveState: true, replace: true },
    );
}

let searchDebounce: ReturnType<typeof setTimeout> | null = null;
watch(searchInput, () => {
    if (searchDebounce) clearTimeout(searchDebounce);
    searchDebounce = setTimeout(applyFilters, 400);
});

function fmt(n: number) {
    return new Intl.NumberFormat('fr-FR').format(Math.round(n)) + ' GNF';
}

const ficheBadge = (s: string) =>
    ({
        a_payer: 'bg-red-100 text-red-700 dark:bg-red-950/30 dark:text-red-400',
        partiellement_paye:
            'bg-amber-100 text-amber-700 dark:bg-amber-950/30 dark:text-amber-400',
        paye: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/30 dark:text-emerald-400',
    })[s] ?? 'bg-muted text-muted-foreground';

function exportExcel() {
    const params = new URLSearchParams();
    params.set('type', props.type);
    if (selectedSite.value) params.set('site_id', selectedSite.value);
    if (selectedStatut.value) params.set('statut', selectedStatut.value);
    if (selectedPeriode.value) params.set('periode_id', selectedPeriode.value);
    window.open(
        '/comptabilite/fiches/export/excel?' + params.toString(),
        '_blank',
    );
}
</script>

<template>
    <Head :title="typeTitle[type]" />
    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-col gap-6 p-6">
            <!-- Header -->
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-semibold tracking-tight">
                        {{ typeTitle[type] }}
                    </h1>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Fiches de règlement par bénéficiaire
                    </p>
                </div>
                <button
                    type="button"
                    class="inline-flex items-center gap-2 rounded-lg border bg-card px-3 py-2 text-sm hover:bg-muted/50"
                    @click="exportExcel"
                >
                    <Download class="h-4 w-4" />
                    Exporter Excel
                </button>
            </div>

            <!-- KPI cards -->
            <div class="grid gap-3 sm:grid-cols-3 lg:grid-cols-5">
                <div class="rounded-xl border bg-card p-4 text-center">
                    <p
                        class="text-2xl font-bold text-red-600 dark:text-red-400"
                    >
                        {{ stats.nb_a_payer }}
                    </p>
                    <p class="mt-1 text-xs text-muted-foreground">À payer</p>
                </div>
                <div class="rounded-xl border bg-card p-4 text-center">
                    <p
                        class="text-2xl font-bold text-amber-600 dark:text-amber-400"
                    >
                        {{ stats.nb_partiellement_paye }}
                    </p>
                    <p class="mt-1 text-xs text-muted-foreground">Partiel</p>
                </div>
                <div class="rounded-xl border bg-card p-4 text-center">
                    <p
                        class="text-2xl font-bold text-emerald-600 dark:text-emerald-400"
                    >
                        {{ stats.nb_paye }}
                    </p>
                    <p class="mt-1 text-xs text-muted-foreground">Payé</p>
                </div>
                <div
                    class="col-span-1 rounded-xl border bg-card p-4 text-center sm:col-span-2 lg:col-span-1"
                >
                    <p class="text-lg font-bold">{{ fmt(stats.total_net) }}</p>
                    <p class="mt-1 text-xs text-muted-foreground">Total net</p>
                </div>
                <div
                    class="col-span-1 rounded-xl border bg-card p-4 text-center sm:col-span-2 lg:col-span-1"
                >
                    <p
                        class="text-lg font-bold text-emerald-600 dark:text-emerald-400"
                    >
                        {{ fmt(stats.total_paye) }}
                    </p>
                    <p class="mt-1 text-xs text-muted-foreground">Déjà payé</p>
                </div>
            </div>

            <!-- Filtres -->
            <div class="flex flex-wrap items-center gap-3">
                <div class="relative w-[260px] shrink-0">
                    <Search
                        class="pointer-events-none absolute top-1/2 left-2.5 h-4 w-4 -translate-y-1/2 text-muted-foreground"
                    />
                    <input
                        v-model="searchInput"
                        type="search"
                        placeholder="Rechercher…"
                        class="h-9 w-full rounded-md border border-input bg-background py-2 pr-7 pl-8 text-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    />
                    <button
                        v-if="searchInput"
                        type="button"
                        class="absolute top-1/2 right-2 -translate-y-1/2 text-muted-foreground hover:text-foreground"
                        @click="searchInput = ''"
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
                    <div v-if="sites.length > 0" class="space-y-1.5">
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
                        <Label>Statut</Label>
                        <select
                            v-model="selectedStatut"
                            class="h-9 w-full rounded-md border border-input bg-background px-2 text-sm"
                        >
                            <option value="">Tous les statuts</option>
                            <option
                                v-for="s in statuts"
                                :key="s.value"
                                :value="s.value"
                            >
                                {{ s.label }}
                            </option>
                        </select>
                    </div>
                    <div class="space-y-1.5">
                        <Label>Période</Label>
                        <select
                            v-model="selectedPeriode"
                            class="h-9 w-full rounded-md border border-input bg-background px-2 text-sm"
                        >
                            <option value="">Toutes les périodes</option>
                            <option
                                v-for="p in periodes"
                                :key="p.id"
                                :value="p.id"
                            >
                                {{ p.reference }}
                            </option>
                        </select>
                    </div>
                </FilterDrawer>

                <span
                    class="shrink-0 text-xs whitespace-nowrap text-muted-foreground"
                >
                    {{ fiches.data.length }} résultat{{
                        fiches.data.length !== 1 ? 's' : ''
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
                    :value="fiches.data"
                    data-key="id"
                    striped-rows
                    class="text-sm"
                >
                    <Column header="Référence" style="width: 160px">
                        <template #body="{ data }">
                            <span
                                class="font-mono text-xs text-muted-foreground"
                                >{{ data.reference }}</span
                            >
                        </template>
                    </Column>

                    <Column header="Période" style="width: 160px">
                        <template #body="{ data }">
                            <Link
                                v-if="data.periode_id"
                                :href="`/comptabilite/periodes/${data.periode_id}`"
                                class="font-mono text-xs text-primary hover:underline"
                            >
                                {{ data.periode_reference }}
                            </Link>
                            <span v-else class="text-xs text-muted-foreground"
                                >—</span
                            >
                        </template>
                    </Column>

                    <Column header="Agence" style="width: 130px">
                        <template #body="{ data }">
                            <span class="text-sm text-muted-foreground">{{
                                data.site?.nom ?? '—'
                            }}</span>
                        </template>
                    </Column>

                    <Column header="Bénéficiaire" style="min-width: 180px">
                        <template #body="{ data }">
                            <Link
                                :href="`/comptabilite/fiches/${data.id}`"
                                class="font-medium hover:underline"
                            >
                                {{ data.beneficiaire_nom }}
                            </Link>
                        </template>
                    </Column>

                    <Column header="Brut" style="width: 130px">
                        <template #body="{ data }">
                            <span class="text-sm tabular-nums">{{
                                fmt(data.montant_brut)
                            }}</span>
                        </template>
                    </Column>

                    <Column header="Déductions" style="width: 130px">
                        <template #body="{ data }">
                            <span
                                class="text-sm text-red-600 tabular-nums dark:text-red-400"
                            >
                                -{{ fmt(data.total_deductions) }}
                            </span>
                        </template>
                    </Column>

                    <Column header="Net à payer" style="width: 140px">
                        <template #body="{ data }">
                            <span
                                class="text-sm font-semibold text-emerald-600 tabular-nums dark:text-emerald-400"
                            >
                                {{ fmt(data.montant_net) }}
                            </span>
                        </template>
                    </Column>

                    <Column header="Statut" style="width: 140px">
                        <template #body="{ data }">
                            <span
                                class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium"
                                :class="ficheBadge(data.statut)"
                            >
                                {{ data.statut_label }}
                            </span>
                        </template>
                    </Column>

                    <Column header="" style="width: 60px">
                        <template #body="{ data }">
                            <Link
                                :href="`/comptabilite/fiches/${data.id}`"
                                class="text-xs text-primary hover:underline"
                            >
                                Voir
                            </Link>
                        </template>
                    </Column>

                    <template #empty>
                        <div
                            class="flex flex-col items-center gap-3 py-16 text-muted-foreground"
                        >
                            <ReceiptText class="h-12 w-12 opacity-30" />
                            <p class="text-sm">Aucune fiche trouvée.</p>
                        </div>
                    </template>
                </DataTable>
            </div>
        </div>
    </AppLayout>
</template>
