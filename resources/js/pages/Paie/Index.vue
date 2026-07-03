<script setup lang="ts">
import DataFilters, {
    type FilterField,
} from '@/components/filters/DataFilters.vue';
import { Button } from '@/components/ui/button';
import { useClickableTableRow } from '@/composables/useClickableTableRow';
import { usePermissions } from '@/composables/usePermissions';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import { CalendarDays, Plus } from 'lucide-vue-next';
import Column from 'primevue/column';
import DataTable from 'primevue/datatable';
import Tag from 'primevue/tag';
import { computed } from 'vue';

interface Periode {
    id: string;
    mois: number;
    annee: number;
    label: string;
    statut: string;
    statut_label: string;
    lignes_count: number;
    notes: string | null;
    created_at: string;
}

interface Option {
    value: string;
    label: string;
}
interface PaginatedPeriodes {
    data: Periode[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    links: any[];
}

const props = defineProps<{
    periodes: PaginatedPeriodes;
    filters: { annee?: string; statut?: string };
    statut_options: Option[];
}>();

const { can } = usePermissions();

const { onRowClick, bodyRowPt } = useClickableTableRow<Periode>(
    (periode) => `/backoffice/paie/${periode.id}`,
);

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/backoffice/dashboard' },
    { title: 'Paie', href: '/backoffice/paie' },
];

const anneesOptions = Array.from({ length: 10 }, (_, i) => {
    const y = new Date().getFullYear() - i;
    return { value: String(y), label: String(y) };
});

const filterFields: FilterField[] = [
    {
        key: 'annee',
        label: 'Année',
        type: 'select',
        options: anneesOptions,
        placeholder: 'Année',
    },
    {
        key: 'statut',
        label: 'Statut',
        type: 'select',
        options: props.statut_options,
        placeholder: 'Statut',
    },
];

const periodesFiltrees = computed(() => props.periodes.data);

function statutSeverity(statut: string) {
    const map: Record<string, string> = {
        brouillon: 'secondary',
        calcule: 'info',
        valide_rh: 'warning',
        paye: 'success',
        cloture: 'contrast',
    };
    return map[statut] ?? 'secondary';
}
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Paie" />

        <div class="space-y-6 p-6">
            <!-- En-tête -->
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="flex items-center gap-2">
                    <CalendarDays class="h-6 w-6 text-primary" />
                    <h1 class="text-2xl font-bold">Périodes de paie</h1>
                </div>
                <Link
                    v-if="can('rh-paie.create')"
                    href="/backoffice/paie/create"
                >
                    <Button size="sm">
                        <Plus class="mr-1 h-4 w-4" />
                        Nouvelle période
                    </Button>
                </Link>
            </div>

            <!-- Filtres -->
            <DataFilters
                url="/backoffice/paie"
                :values="filters"
                :fields="filterFields"
                :result-count="periodesFiltrees.length"
            />

            <!-- Table -->
            <DataTable
                :value="periodesFiltrees"
                dataKey="id"
                striped-rows
                class="text-sm"
                :pt="{ bodyRow: bodyRowPt }"
                @row-click="onRowClick"
            >
                <Column field="label" header="Période" sortable />
                <Column field="statut_label" header="Statut" sortable>
                    <template #body="{ data }">
                        <Tag
                            :value="data.statut_label"
                            :severity="statutSeverity(data.statut)"
                        />
                    </template>
                </Column>
                <Column field="lignes_count" header="Lignes" sortable />
                <Column header="Actions" style="width: 8rem">
                    <template #body="{ data }">
                        <Link :href="`/backoffice/paie/${data.id}`">
                            <Button variant="outline" size="sm"
                                >Détail →</Button
                            >
                        </Link>
                    </template>
                </Column>
            </DataTable>

            <!-- Pagination simple -->
            <div
                v-if="periodes.last_page > 1"
                class="flex items-center justify-center gap-2 text-sm"
            >
                <span
                    >Page {{ periodes.current_page }} /
                    {{ periodes.last_page }}</span
                >
            </div>
        </div>
    </AppLayout>
</template>
