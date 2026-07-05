<script setup lang="ts">
import DataFilters, {
    type FilterField,
} from '@/components/filters/DataFilters.vue';
import StatusDot from '@/components/StatusDot.vue';
import { useClickableTableRow } from '@/composables/useClickableTableRow';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import { Calendar } from 'lucide-vue-next';
import Column from 'primevue/column';
import DataTable from 'primevue/datatable';

interface Periode {
    id: string;
    reference: string;
    type: string;
    type_label: string;
    quinzaine: 'P1' | 'P2' | null;
    site: { id: string; nom: string } | null;
    date_debut: string | null;
    date_fin: string | null;
    statut: string;
    statut_label: string;
    nb_fiches: number;
    total_net: number;
    total_paye: number;
}

interface Option {
    value: string;
    label: string;
}

interface CycleParType {
    type: string;
    type_label: string;
    periode: Periode;
}

interface Cycle {
    annee_courante: number;
    periode_courante_label: string;
    periode_suivante_label: string;
    par_type: CycleParType[];
}

const props = defineProps<{
    periodes: { data: Periode[]; links: unknown[] };
    types: Option[];
    statuts: Option[];
    filters: {
        type?: string;
        statut?: string;
        annee?: string;
        mois?: string;
        quinzaine?: string;
        search?: string;
    };
    cycle: Cycle;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/backoffice/dashboard' },
    { title: 'Comptabilité', href: '/backoffice/comptabilite' },
    { title: 'Périodes', href: '/backoffice/comptabilite/periodes' },
];

const anneeOptions: Option[] = Array.from({ length: 5 }, (_, i) => {
    const annee = props.cycle.annee_courante + 1 - i;
    return { value: String(annee), label: String(annee) };
});

const moisOptions: Option[] = [
    'Janvier',
    'Février',
    'Mars',
    'Avril',
    'Mai',
    'Juin',
    'Juillet',
    'Août',
    'Septembre',
    'Octobre',
    'Novembre',
    'Décembre',
].map((label, i) => ({ value: String(i + 1), label }));

const quinzaineOptions: Option[] = [
    { value: 'P1', label: 'P1 (1 → 15)' },
    { value: 'P2', label: 'P2 (16 → fin du mois)' },
];

const filterFields: FilterField[] = [
    {
        key: 'annee',
        label: 'Année',
        type: 'select',
        inline: true,
        options: anneeOptions,
    },
    {
        key: 'mois',
        label: 'Mois',
        type: 'select',
        inline: true,
        options: moisOptions,
    },
    {
        key: 'quinzaine',
        label: 'Quinzaine',
        type: 'select',
        inline: true,
        options: quinzaineOptions,
    },
    {
        key: 'type',
        label: 'Type',
        type: 'select',
        inline: true,
        options: props.types,
    },
    {
        key: 'statut',
        label: 'Statut',
        type: 'select',
        inline: true,
        options: props.statuts,
    },
];

function fmt(n: number) {
    return new Intl.NumberFormat('fr-FR').format(Math.round(n)) + ' GNF';
}

const { onRowClick, bodyRowPt } = useClickableTableRow<Periode>(
    (periode) => `/backoffice/comptabilite/periodes/${periode.id}`,
);

const typeBadge = (type: string) =>
    ({
        livreur:
            'bg-blue-100 text-blue-700 dark:bg-blue-950/30 dark:text-blue-400',
        proprietaire:
            'bg-violet-100 text-violet-700 dark:bg-violet-950/30 dark:text-violet-400',
        salarie:
            'bg-orange-100 text-orange-700 dark:bg-orange-950/30 dark:text-orange-400',
    })[type] ?? 'bg-muted text-muted-foreground';
</script>

<template>
    <Head title="Périodes de paiement" />
    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-col gap-6 p-6">
            <!-- Header -->
            <div>
                <h1 class="text-2xl font-semibold tracking-tight">
                    Périodes de paiement
                </h1>
                <p class="mt-1 text-sm text-muted-foreground">
                    Gérer les cycles de paiement livreurs, propriétaires et
                    salariés
                </p>
            </div>

            <!-- Cycle en cours : résumé compact du dashboard, distinct de l'historique (tableau ci-dessous) -->
            <div class="rounded-xl border bg-card px-4 py-3">
                <div
                    class="flex flex-wrap items-center justify-between gap-x-6 gap-y-1 text-xs text-muted-foreground"
                >
                    <span
                        >Cycle en cours :
                        <span class="font-semibold text-foreground">{{
                            cycle.periode_courante_label
                        }}</span></span
                    >
                    <span
                        >Suivant :
                        <span class="font-medium text-foreground">{{
                            cycle.periode_suivante_label
                        }}</span></span
                    >
                </div>

                <div class="mt-2 flex flex-wrap items-center gap-x-5 gap-y-2">
                    <Link
                        v-for="entry in cycle.par_type"
                        :key="entry.type"
                        :href="`/backoffice/comptabilite/periodes/${entry.periode.id}`"
                        class="flex items-center gap-2 text-sm transition-colors hover:text-foreground"
                    >
                        <span class="font-medium">{{ entry.type_label }}</span>
                        <StatusDot
                            :status="entry.periode.statut"
                            :label="entry.periode.statut_label"
                        />
                        <span
                            v-if="entry.periode.nb_fiches > 0"
                            class="text-xs text-muted-foreground"
                        >
                            {{ entry.periode.nb_fiches }} fiches ·
                            {{ fmt(entry.periode.total_net) }}
                        </span>
                    </Link>
                </div>
            </div>

            <!-- Filtres -->
            <DataFilters
                url="/backoffice/comptabilite/periodes"
                :values="filters"
                :fields="filterFields"
                :result-count="periodes.data.length"
                hide-agence-selector
            />

            <!-- Table -->
            <div class="overflow-hidden rounded-xl border bg-card">
                <DataTable
                    :value="periodes.data"
                    data-key="id"
                    striped-rows
                    class="text-sm"
                    :pt="{ bodyRow: bodyRowPt }"
                    @row-click="onRowClick"
                >
                    <Column
                        field="reference"
                        header="Référence"
                        style="width: 200px"
                    >
                        <template #body="{ data }">
                            <Link
                                :href="`/backoffice/comptabilite/periodes/${data.id}`"
                                class="font-mono text-xs font-semibold text-primary hover:underline"
                            >
                                {{ data.reference }}
                            </Link>
                        </template>
                    </Column>

                    <Column header="Type" style="width: 140px">
                        <template #body="{ data }">
                            <span
                                class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium"
                                :class="typeBadge(data.type)"
                            >
                                {{ data.type_label }}
                            </span>
                        </template>
                    </Column>

                    <Column header="Période" style="min-width: 200px">
                        <template #body="{ data }">
                            <span class="text-sm">
                                {{ data.date_debut ?? '—' }}
                                <span class="text-muted-foreground"> → </span>
                                {{ data.date_fin ?? '—' }}
                            </span>
                        </template>
                    </Column>

                    <Column header="Agence" style="width: 160px">
                        <template #body="{ data }">
                            <span class="text-sm text-muted-foreground">
                                {{ data.site?.nom ?? 'Toutes agences' }}
                            </span>
                        </template>
                    </Column>

                    <Column header="Fiches / Net" style="width: 160px">
                        <template #body="{ data }">
                            <div class="text-sm">
                                <span class="font-medium">{{
                                    data.nb_fiches
                                }}</span>
                                <span class="text-muted-foreground">
                                    fiches</span
                                >
                                <div class="text-xs text-muted-foreground">
                                    {{ fmt(data.total_net) }}
                                </div>
                            </div>
                        </template>
                    </Column>

                    <Column header="Statut" style="width: 120px">
                        <template #body="{ data }">
                            <StatusDot
                                :status="data.statut"
                                :label="data.statut_label"
                            />
                        </template>
                    </Column>

                    <Column header="" style="width: 80px">
                        <template #body="{ data }">
                            <Link
                                :href="`/backoffice/comptabilite/periodes/${data.id}`"
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
                            <Calendar class="h-12 w-12 opacity-30" />
                            <p class="text-sm">Aucune période trouvée.</p>
                        </div>
                    </template>
                </DataTable>
            </div>
        </div>
    </AppLayout>
</template>
