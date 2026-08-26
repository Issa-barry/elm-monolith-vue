<script setup lang="ts">
import StatusDot from '@/components/StatusDot.vue';
import DataFilters, {
    type FilterField,
} from '@/components/filters/DataFilters.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/vue3';
import { ArrowDown, ArrowUp, BookOpen, ChevronRight } from 'lucide-vue-next';
import Column from 'primevue/column';
import DataTable from 'primevue/datatable';
import { ref } from 'vue';

interface PieceLigne {
    compte_numero: string | null;
    compte_libelle: string | null;
    libelle: string;
    debit: number;
    credit: number;
}

interface Ligne {
    id: string;
    date_operation: string | null;
    sens: 'entree' | 'sortie';
    evenement: string;
    evenement_label: string;
    libelle: string;
    reference: string | null;
    journal: string | null;
    montant: number;
    site: { id: string; nom: string } | null;
    statut: string;
    statut_label: string;
    piece_lignes: PieceLigne[];
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
    evenement_options: Option[];
    journal_options: Option[];
    compte_options: Option[];
    sites: Site[];
    is_admin: boolean;
    filters: {
        sens?: string;
        evenement?: string;
        journal?: string;
        compte_id?: string;
        annee?: string;
        mois?: string;
        reference?: string;
        site_ids?: string[];
    };
    kpis: { total_entrees: number; total_sorties: number; solde: number };
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/backoffice/dashboard' },
    { title: 'Comptabilité' },
    { title: 'Journal financier', href: '/backoffice/comptabilite/journal' },
];

const anneeCourante = new Date().getFullYear();
const anneeOptions: Option[] = Array.from({ length: 5 }, (_, i) => {
    const annee = anneeCourante + 1 - i;
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

const filterFields: FilterField[] = [
    {
        key: 'reference',
        label: 'Référence',
        type: 'text',
        inline: true,
        placeholder: 'N° de pièce…',
    },
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
        key: 'sens',
        label: 'Sens',
        type: 'select',
        options: props.sens_options,
    },
    {
        key: 'evenement',
        label: 'Événement',
        type: 'select',
        options: props.evenement_options,
    },
    {
        key: 'journal',
        label: 'Journal',
        type: 'select',
        options: props.journal_options,
    },
    {
        key: 'compte_id',
        label: 'Compte',
        type: 'select',
        options: props.compte_options,
    },
];

const journalSites = props.sites.map((s) => ({ id: s.id, nom: s.nom }));

const expandedRows = ref<Record<string, boolean>>({});

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
                    Mouvements de trésorerie — lecture directe du grand livre
                    comptable (compta_ecritures)
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
                    v-model:expanded-rows="expandedRows"
                    :value="lignes.data"
                    data-key="id"
                    striped-rows
                    class="text-sm"
                >
                    <Column expander style="width: 40px" />

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

                    <Column header="Type" style="width: 170px">
                        <template #body="{ data }">
                            <span
                                class="rounded-full bg-muted px-2 py-0.5 text-xs text-muted-foreground"
                            >
                                {{ data.evenement_label }}
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

                    <Column header="Statut" style="width: 130px">
                        <template #body="{ data }">
                            <StatusDot
                                :status="data.statut"
                                :label="data.statut_label"
                            />
                        </template>
                    </Column>

                    <template #expansion="{ data }: { data: Ligne }">
                        <div class="bg-muted/30 px-4 py-3">
                            <p
                                class="mb-2 flex items-center gap-1 text-xs font-medium text-muted-foreground"
                            >
                                <ChevronRight class="h-3.5 w-3.5" />
                                Pièce {{ data.reference }} — toutes les lignes
                            </p>
                            <table class="w-full text-xs">
                                <thead>
                                    <tr class="text-left text-muted-foreground">
                                        <th class="py-1 pr-3 font-medium">
                                            Compte
                                        </th>
                                        <th class="py-1 pr-3 font-medium">
                                            Libellé
                                        </th>
                                        <th
                                            class="py-1 pr-3 text-right font-medium"
                                        >
                                            Débit
                                        </th>
                                        <th class="py-1 text-right font-medium">
                                            Crédit
                                        </th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-border/60">
                                    <tr
                                        v-for="(pl, i) in data.piece_lignes"
                                        :key="i"
                                    >
                                        <td class="py-1 pr-3 font-mono">
                                            {{ pl.compte_numero }} —
                                            {{ pl.compte_libelle }}
                                        </td>
                                        <td class="py-1 pr-3">
                                            {{ pl.libelle }}
                                        </td>
                                        <td
                                            class="py-1 pr-3 text-right tabular-nums"
                                        >
                                            {{
                                                pl.debit > 0
                                                    ? fmt(pl.debit)
                                                    : ''
                                            }}
                                        </td>
                                        <td
                                            class="py-1 text-right tabular-nums"
                                        >
                                            {{
                                                pl.credit > 0
                                                    ? fmt(pl.credit)
                                                    : ''
                                            }}
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </template>

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
