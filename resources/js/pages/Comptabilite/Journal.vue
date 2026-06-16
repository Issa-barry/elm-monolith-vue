<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/vue3';
import { ArrowDown, ArrowUp, BookOpen } from 'lucide-vue-next';
import Column from 'primevue/column';
import DataTable from 'primevue/datatable';
import Dropdown from 'primevue/dropdown';
import { computed, ref } from 'vue';

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
    };
    kpis: { total_entrees: number; total_sorties: number; solde: number };
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/dashboard' },
    { title: 'Comptabilité', href: '/comptabilite' },
    { title: 'Journal financier', href: '/comptabilite/journal' },
];

const selectedSens = ref(props.filters.sens ?? '');
const selectedCategorie = ref(props.filters.categorie ?? '');
const selectedSite = ref(props.filters.site_id ?? '');
const dateFrom = ref(props.filters.date_from ?? '');
const dateTo = ref(props.filters.date_to ?? '');

const sensOptions = computed(() => [
    { label: 'Tous les sens', value: '' },
    ...props.sens_options,
]);
const categorieOptions = computed(() => [
    { label: 'Toutes les catégories', value: '' },
    ...props.categories,
]);
const siteOptions = computed(() => [
    { label: 'Toutes les agences', value: '' },
    ...props.sites.map((s) => ({ label: s.nom, value: s.id })),
]);

function applyFilters() {
    router.get(
        '/comptabilite/journal',
        {
            sens: selectedSens.value || undefined,
            categorie: selectedCategorie.value || undefined,
            site_id: selectedSite.value || undefined,
            date_from: dateFrom.value || undefined,
            date_to: dateTo.value || undefined,
        },
        { preserveState: true, replace: true },
    );
}

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
            <div class="grid gap-3 sm:grid-cols-3">
                <div class="rounded-xl border bg-card p-4">
                    <div class="flex items-center gap-2">
                        <ArrowUp class="h-4 w-4 text-emerald-500" />
                        <span class="text-sm text-muted-foreground"
                            >Total entrées</span
                        >
                    </div>
                    <p
                        class="mt-1 text-xl font-bold text-emerald-600 dark:text-emerald-400"
                    >
                        {{ fmt(kpis.total_entrees) }}
                    </p>
                </div>
                <div class="rounded-xl border bg-card p-4">
                    <div class="flex items-center gap-2">
                        <ArrowDown class="h-4 w-4 text-red-500" />
                        <span class="text-sm text-muted-foreground"
                            >Total sorties</span
                        >
                    </div>
                    <p
                        class="mt-1 text-xl font-bold text-red-600 dark:text-red-400"
                    >
                        {{ fmt(kpis.total_sorties) }}
                    </p>
                </div>
                <div class="rounded-xl border bg-card p-4">
                    <div class="flex items-center gap-2">
                        <BookOpen class="h-4 w-4 text-blue-500" />
                        <span class="text-sm text-muted-foreground">Solde</span>
                    </div>
                    <p
                        class="mt-1 text-xl font-bold"
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
            <div class="flex flex-wrap items-end gap-3">
                <div class="flex flex-col gap-1">
                    <label class="text-xs text-muted-foreground">Du</label>
                    <input
                        v-model="dateFrom"
                        type="date"
                        class="h-9 rounded-lg border border-input bg-background px-3 text-sm focus:ring-2 focus:ring-ring focus:outline-none"
                    />
                </div>
                <div class="flex flex-col gap-1">
                    <label class="text-xs text-muted-foreground">Au</label>
                    <input
                        v-model="dateTo"
                        type="date"
                        class="h-9 rounded-lg border border-input bg-background px-3 text-sm focus:ring-2 focus:ring-ring focus:outline-none"
                    />
                </div>
                <Dropdown
                    v-model="selectedSens"
                    :options="sensOptions"
                    option-label="label"
                    option-value="value"
                    placeholder="Sens"
                    class="min-w-[140px] text-sm"
                    @change="applyFilters"
                />
                <Dropdown
                    v-model="selectedCategorie"
                    :options="categorieOptions"
                    option-label="label"
                    option-value="value"
                    placeholder="Catégorie"
                    class="min-w-[180px] text-sm"
                    @change="applyFilters"
                />
                <Dropdown
                    v-if="is_admin"
                    v-model="selectedSite"
                    :options="siteOptions"
                    option-label="label"
                    option-value="value"
                    placeholder="Agence"
                    class="min-w-[160px] text-sm"
                    @change="applyFilters"
                />
                <button
                    type="button"
                    class="h-9 rounded-lg border bg-card px-3 text-sm hover:bg-muted/50"
                    @click="applyFilters"
                >
                    Filtrer
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
