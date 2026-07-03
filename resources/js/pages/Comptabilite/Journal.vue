<script setup lang="ts">
import DataFilters, {
    type FilterField,
} from '@/components/filters/DataFilters.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/vue3';
import { ArrowDown, ArrowUp, BookOpen } from 'lucide-vue-next';
import Column from 'primevue/column';
import DataTable from 'primevue/datatable';

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
        site_ids?: string[];
        search?: string;
    };
    kpis: { total_entrees: number; total_sorties: number; solde: number };
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/backoffice/dashboard' },
    { title: 'Comptabilité', href: '/backoffice/comptabilite' },
    { title: 'Journal financier', href: '/backoffice/comptabilite/journal' },
];

const filterFields: FilterField[] = [
    {
        key: 'sens',
        label: 'Sens',
        type: 'select',
        options: props.sens_options,
    },
    {
        key: 'categorie',
        label: 'Catégorie',
        type: 'select',
        options: props.categories,
    },
    {
        key: 'date',
        label: 'Période',
        type: 'date-range',
        startKey: 'date_from',
        endKey: 'date_to',
    },
];

const journalSites = props.sites.map((s) => ({ id: s.id, nom: s.nom }));

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
            <DataFilters
                url="/backoffice/comptabilite/journal"
                :values="filters"
                :fields="filterFields"
                :sites="journalSites"
                :result-count="lignes.data.length"
            />

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
