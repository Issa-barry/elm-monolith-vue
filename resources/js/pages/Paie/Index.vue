<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { usePermissions } from '@/composables/usePermissions';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import { FilterMatchMode } from '@primevue/core/api';
import { CalendarDays, Plus } from 'lucide-vue-next';
import Column from 'primevue/column';
import DataTable from 'primevue/datatable';
import Select from 'primevue/select';
import Tag from 'primevue/tag';
import { ref, watch } from 'vue';

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

interface Option { value: string; label: string }
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

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/dashboard' },
    { title: 'Paie', href: '/paie' },
];

const anneeFilter  = ref<string | null>(props.filters.annee ?? null);
const statutFilter = ref<string | null>(props.filters.statut ?? null);

const globalFilter = ref('');
const filtersMeta  = ref({ global: { value: '', matchMode: FilterMatchMode.CONTAINS } });

watch(globalFilter, (v) => { filtersMeta.value.global.value = v; });

function applyFilters() {
    router.get('/paie', {
        annee:  anneeFilter.value ?? undefined,
        statut: statutFilter.value ?? undefined,
    }, { preserveState: true, replace: true });
}

watch([anneeFilter, statutFilter], applyFilters);

const anneesOptions = Array.from({ length: 10 }, (_, i) => {
    const y = new Date().getFullYear() - i;
    return { value: String(y), label: String(y) };
});

function statutSeverity(statut: string) {
    const map: Record<string, string> = {
        brouillon:  'secondary',
        calcule:    'info',
        valide_rh:  'warning',
        paye:       'success',
        cloture:    'contrast',
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
                <Link v-if="can('rh-paie.create')" href="/paie/create">
                    <Button size="sm">
                        <Plus class="mr-1 h-4 w-4" />
                        Nouvelle période
                    </Button>
                </Link>
            </div>

            <!-- Filtres -->
            <div class="flex flex-wrap gap-3">
                <Select
                    v-model="anneeFilter"
                    :options="anneesOptions"
                    option-label="label"
                    option-value="value"
                    placeholder="Année"
                    show-clear
                    class="w-36"
                />
                <Select
                    v-model="statutFilter"
                    :options="statut_options"
                    option-label="label"
                    option-value="value"
                    placeholder="Statut"
                    show-clear
                    class="w-44"
                />
                <input
                    v-model="globalFilter"
                    type="text"
                    placeholder="Rechercher…"
                    class="rounded-md border border-input bg-background px-3 py-1.5 text-sm shadow-sm placeholder:text-muted-foreground focus:outline-none focus:ring-1 focus:ring-ring"
                />
            </div>

            <!-- Table -->
            <DataTable
                :value="periodes.data"
                dataKey="id"
                :filters="filtersMeta"
                :global-filter-fields="['label', 'statut_label']"
                striped-rows
                selection-mode="single"
                class="text-sm [&_tr]:cursor-pointer"
                @row-click="(e) => router.visit(`/paie/${e.data.id}`)"
            >
                <Column field="label" header="Période" sortable />
                <Column field="statut_label" header="Statut" sortable>
                    <template #body="{ data }">
                        <Tag :value="data.statut_label" :severity="statutSeverity(data.statut)" />
                    </template>
                </Column>
                <Column field="lignes_count" header="Lignes" sortable />
                <Column header="Actions" style="width: 8rem">
                    <template #body="{ data }">
                        <Link :href="`/paie/${data.id}`" @click.stop>
                            <Button variant="outline" size="sm">Détail →</Button>
                        </Link>
                    </template>
                </Column>
            </DataTable>

            <!-- Pagination simple -->
            <div v-if="periodes.last_page > 1" class="flex items-center justify-center gap-2 text-sm">
                <span>Page {{ periodes.current_page }} / {{ periodes.last_page }}</span>
            </div>
        </div>
    </AppLayout>
</template>
