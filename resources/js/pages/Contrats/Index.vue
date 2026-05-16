<script setup lang="ts">
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { usePermissions } from '@/composables/usePermissions';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import { FilterMatchMode } from '@primevue/core/api';
import { Briefcase, MoreVertical, Pencil, Plus, Trash2, X } from 'lucide-vue-next';
import Column from 'primevue/column';
import DataTable from 'primevue/datatable';
import Select from 'primevue/select';
import { useConfirm } from 'primevue/useconfirm';
import { useToast } from 'primevue/usetoast';
import { computed, ref, watch } from 'vue';

interface Contrat {
    id: string;
    employe_id: string;
    employe_nom_complet: string | null;
    employe_matricule: string | null;
    type_contrat: string;
    type_contrat_label: string;
    statut_contrat: string;
    statut_contrat_label: string;
    date_debut: string | null;
    date_fin: string | null;
    salaire_base: string | null;
}

interface Option { value: string; label: string }
interface Filters { statut_contrat?: string; type_contrat?: string }

const props = defineProps<{
    contrats: Contrat[];
    filters: Filters;
    type_contrat_options: Option[];
    statut_contrat_options: Option[];
}>();

const { can } = usePermissions();
const confirm = useConfirm();
const toast = useToast();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/dashboard' },
    { title: 'Contrats', href: '/contrats' },
];

// ── Filtres serveur (type + statut) ──────────────────────────────────────────
const statutContrat = ref<string | null>(props.filters.statut_contrat ?? null);
const typeContrat   = ref<string | null>(props.filters.type_contrat ?? null);

function applyFilters() {
    router.visit('/contrats', {
        method: 'get',
        data: {
            statut_contrat: statutContrat.value || undefined,
            type_contrat:   typeContrat.value || undefined,
        },
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

watch([statutContrat, typeContrat], applyFilters);

// ── Filtre global DataTable (client-side, toutes colonnes) ────────────────────
const tableFilters = ref({
    global: { value: null as string | null, matchMode: FilterMatchMode.CONTAINS },
});

const hasFilters = computed(() =>
    tableFilters.value.global.value || statutContrat.value || typeContrat.value,
);

function reset() {
    tableFilters.value.global.value = null;
    statutContrat.value = null;
    typeContrat.value   = null;
}

// ── Styles badges ─────────────────────────────────────────────────────────────
const TYPE_CONTRAT_CLASS: Record<string, string> = {
    cdi: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400',
    cdd: 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400',
};
const STATUT_CLASS: Record<string, string> = {
    actif:   'bg-emerald-100 text-emerald-700',
    termine: 'bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400',
    rompu:   'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400',
};

function confirmDelete(c: Contrat) {
    confirm.require({
        message: `Supprimer ce contrat ${c.type_contrat_label} de ${c.employe_nom_complet} ?`,
        header: 'Confirmer la suppression',
        icon: 'pi pi-exclamation-triangle',
        rejectLabel: 'Annuler',
        acceptLabel: 'Supprimer',
        acceptClass: 'p-button-danger',
        accept: () => {
            router.delete(`/contrats/${c.id}`, {
                onSuccess: () => toast.add({ severity: 'success', summary: 'Contrat supprimé', life: 3000 }),
            });
        },
    });
}
</script>

<template>
    <Head><title>Contrats</title></Head>
    <AppLayout :breadcrumbs="breadcrumbs" :hide-mobile-header="true">
        <div class="flex flex-col gap-6 p-6">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-semibold tracking-tight">Contrats</h1>
                    <p class="mt-1 text-sm text-muted-foreground">{{ contrats.length }} contrat{{ contrats.length !== 1 ? 's' : '' }}</p>
                </div>
                <Link v-if="can('rh-contrats.create')" href="/contrats/create">
                    <Button><Plus class="mr-2 h-4 w-4" />Nouveau contrat</Button>
                </Link>
            </div>

            <!-- Filtres -->
            <div class="rounded-xl border bg-card px-4 py-3">
                <div class="flex flex-wrap items-center gap-3">
                    <!-- Recherche toutes colonnes -->
                    <div class="relative flex-1 min-w-[200px]">
                        <span class="absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground pointer-events-none pi pi-search text-xs" />
                        <input
                            v-model="tableFilters.global.value"
                            type="text"
                            placeholder="Rechercher (nom, matricule, type, statut, date…)"
                            class="h-9 w-full rounded-md border border-input bg-background pl-8 pr-3 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                        />
                    </div>
                    <!-- Type contrat -->
                    <Select
                        v-model="typeContrat"
                        :options="type_contrat_options"
                        option-label="label"
                        option-value="value"
                        placeholder="Tous types"
                        show-clear
                        class="w-44"
                    />
                    <!-- Statut contrat -->
                    <Select
                        v-model="statutContrat"
                        :options="statut_contrat_options"
                        option-label="label"
                        option-value="value"
                        placeholder="Tous statuts"
                        show-clear
                        class="w-44"
                    />
                    <button
                        v-if="hasFilters"
                        type="button"
                        class="flex items-center gap-1.5 rounded-md px-2.5 py-1.5 text-xs text-muted-foreground hover:bg-muted"
                        @click="reset"
                    >
                        <X class="h-3.5 w-3.5" />Réinitialiser
                    </button>
                </div>
            </div>

            <!-- Table -->
            <div class="overflow-hidden rounded-xl border bg-card">
                <DataTable
                    :value="contrats"
                    v-model:filters="tableFilters"
                    :global-filter-fields="['employe_nom_complet', 'employe_matricule', 'type_contrat_label', 'statut_contrat_label', 'date_debut', 'date_fin']"
                    :paginator="contrats.length > 25"
                    :rows="25"
                    data-key="id"
                    striped-rows
                    removable-sort
                    class="text-sm"
                    table-class="w-full"
                >
                    <Column header="Employé" sortable sort-field="employe_nom_complet" style="min-width: 200px">
                        <template #body="{ data }">
                            <Link :href="`/employes/${data.employe_id}/edit`" class="hover:underline font-medium">
                                {{ data.employe_nom_complet }}
                            </Link>
                            <div class="font-mono text-xs text-muted-foreground">{{ data.employe_matricule }}</div>
                        </template>
                    </Column>

                    <Column header="Type" style="width: 110px">
                        <template #body="{ data }">
                            <span class="inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-medium" :class="TYPE_CONTRAT_CLASS[data.type_contrat]">
                                <Briefcase class="h-3 w-3" />{{ data.type_contrat_label }}
                            </span>
                        </template>
                    </Column>

                    <Column header="Statut" style="width: 110px">
                        <template #body="{ data }">
                            <span class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium" :class="STATUT_CLASS[data.statut_contrat]">
                                {{ data.statut_contrat_label }}
                            </span>
                        </template>
                    </Column>

                    <Column field="date_debut" header="Début" sortable style="width: 120px">
                        <template #body="{ data }">
                            <span class="text-sm">{{ data.date_debut }}</span>
                        </template>
                    </Column>

                    <Column field="date_fin" header="Fin" style="width: 120px">
                        <template #body="{ data }">
                            <span class="text-sm text-muted-foreground">{{ data.date_fin ?? '—' }}</span>
                        </template>
                    </Column>

                    <Column header="" style="width: 56px">
                        <template #body="{ data }">
                            <div class="flex justify-end">
                                <DropdownMenu>
                                    <DropdownMenuTrigger as-child>
                                        <Button variant="ghost" size="icon" class="h-8 w-8"><MoreVertical class="h-4 w-4" /></Button>
                                    </DropdownMenuTrigger>
                                    <DropdownMenuContent align="end" class="w-40">
                                        <DropdownMenuItem v-if="can('rh-contrats.update')" as-child>
                                            <Link :href="`/contrats/${data.id}/edit`" class="flex w-full items-center gap-2">
                                                <Pencil class="h-4 w-4" />Modifier
                                            </Link>
                                        </DropdownMenuItem>
                                        <DropdownMenuSeparator v-if="can('rh-contrats.delete')" />
                                        <DropdownMenuItem v-if="can('rh-contrats.delete')" class="cursor-pointer text-destructive focus:text-destructive" @click="confirmDelete(data)">
                                            <Trash2 class="h-4 w-4" />Supprimer
                                        </DropdownMenuItem>
                                    </DropdownMenuContent>
                                </DropdownMenu>
                            </div>
                        </template>
                    </Column>

                    <template #empty>
                        <div class="flex flex-col items-center gap-3 py-12 text-muted-foreground">
                            <Briefcase class="h-10 w-10 opacity-30" />
                            <p class="text-sm">Aucun contrat trouvé.</p>
                        </div>
                    </template>
                </DataTable>
            </div>
        </div>
    </AppLayout>
</template>
