<script setup lang="ts">
import DataFilters, {
    type FilterField,
} from '@/components/filters/DataFilters.vue';
import StatusDot from '@/components/StatusDot.vue';
import { useClickableTableRow } from '@/composables/useClickableTableRow';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import { Download, ReceiptText } from 'lucide-vue-next';
import Column from 'primevue/column';
import DataTable from 'primevue/datatable';
import { computed, ref } from 'vue';

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

const search = ref(props.filters.search ?? '');

const filterFields = computed((): FilterField[] => [
    ...(props.sites.length > 0
        ? [
              {
                  key: 'site_id',
                  label: 'Agence',
                  type: 'select' as const,
                  options: props.sites.map((s) => ({
                      value: s.id,
                      label: s.nom,
                  })),
              },
          ]
        : []),
    {
        key: 'statut',
        label: 'Statut',
        type: 'select' as const,
        options: props.statuts,
    },
    {
        key: 'periode_id',
        label: 'Période',
        type: 'select' as const,
        options: props.periodes.map((p) => ({
            value: p.id,
            label: p.reference,
        })),
    },
]);

function fmt(n: number) {
    return new Intl.NumberFormat('fr-FR').format(Math.round(n)) + ' GNF';
}

const { onRowClick, bodyRowPt } = useClickableTableRow<Fiche>(
    (fiche) => `/comptabilite/fiches/${fiche.id}`,
);

function exportExcel() {
    const params = new URLSearchParams();
    params.set('type', props.type);
    if (props.filters.site_id) params.set('site_id', props.filters.site_id);
    if (props.filters.statut) params.set('statut', props.filters.statut);
    if (props.filters.periode_id)
        params.set('periode_id', props.filters.periode_id);
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
            <DataFilters
                :url="typeRoute[type]"
                :values="filters"
                :fields="filterFields"
                :result-count="fiches.data.length"
                search-key="search"
                v-model:search="search"
            />

            <!-- Table -->
            <div class="overflow-hidden rounded-xl border bg-card">
                <DataTable
                    :value="fiches.data"
                    data-key="id"
                    striped-rows
                    class="text-sm"
                    :pt="{ bodyRow: bodyRowPt }"
                    @row-click="onRowClick"
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
                            <StatusDot
                                :status="data.statut"
                                :label="data.statut_label"
                            />
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
