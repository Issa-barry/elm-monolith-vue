<script setup lang="ts">
import DataFilters, {
    type FilterField,
} from '@/components/filters/DataFilters.vue';
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
import { Briefcase, MoreVertical, Pencil, Plus, Trash2 } from 'lucide-vue-next';
import Column from 'primevue/column';
import DataTable from 'primevue/datatable';
import { useConfirm } from 'primevue/useconfirm';
import { useToast } from 'primevue/usetoast';
import { computed, ref } from 'vue';

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

interface Option {
    value: string;
    label: string;
}
interface Filters {
    statut_contrat?: string;
    type_contrat?: string;
    search?: string;
}

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


const filterFields: FilterField[] = [
    {
        key: 'type_contrat',
        label: 'Type',
        type: 'select',
        options: props.type_contrat_options,
        placeholder: 'Tous types',
    },
    {
        key: 'statut_contrat',
        label: 'Statut',
        type: 'select',
        options: props.statut_contrat_options,
        placeholder: 'Tous statuts',
    },
];

const filteredContrats = computed(() => props.contrats);

// ── Styles badges ─────────────────────────────────────────────────────────────
const TYPE_CONTRAT_CLASS: Record<string, string> = {
    cdi: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400',
    cdd: 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400',
};
const STATUT_CLASS: Record<string, string> = {
    actif: 'bg-emerald-100 text-emerald-700',
    termine: 'bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400',
    rompu: 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400',
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
                onSuccess: () =>
                    toast.add({
                        severity: 'success',
                        summary: 'Contrat supprimé',
                        life: 3000,
                    }),
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
                    <h1 class="text-2xl font-semibold tracking-tight">
                        Contrats
                    </h1>
                    <p class="mt-1 text-sm text-muted-foreground">
                        {{ contrats.length }} contrat{{
                            contrats.length !== 1 ? 's' : ''
                        }}
                    </p>
                </div>
                <Link v-if="can('rh-contrats.create')" href="/contrats/create">
                    <Button
                        ><Plus class="mr-2 h-4 w-4" />Nouveau contrat</Button
                    >
                </Link>
            </div>

            <!-- Filtres -->
            <DataFilters
                url="/contrats"
                :values="filters"
                :fields="filterFields"
                :result-count="filteredContrats.length"
            />

            <!-- Table -->
            <div class="overflow-hidden rounded-xl border bg-card">
                <DataTable
                    :value="filteredContrats"
                    :paginator="filteredContrats.length > 25"
                    :rows="25"
                    data-key="id"
                    striped-rows
                    removable-sort
                    class="text-sm"
                    table-class="w-full"
                >
                    <Column
                        header="Employé"
                        sortable
                        sort-field="employe_nom_complet"
                        style="min-width: 200px"
                    >
                        <template #body="{ data }">
                            <Link
                                :href="`/employes/${data.employe_id}/edit`"
                                class="font-medium hover:underline"
                            >
                                {{ data.employe_nom_complet }}
                            </Link>
                            <div
                                class="font-mono text-xs text-muted-foreground"
                            >
                                {{ data.employe_matricule }}
                            </div>
                        </template>
                    </Column>

                    <Column header="Type" style="width: 110px">
                        <template #body="{ data }">
                            <span
                                class="inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-medium"
                                :class="TYPE_CONTRAT_CLASS[data.type_contrat]"
                            >
                                <Briefcase class="h-3 w-3" />{{
                                    data.type_contrat_label
                                }}
                            </span>
                        </template>
                    </Column>

                    <Column header="Statut" style="width: 110px">
                        <template #body="{ data }">
                            <span
                                class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium"
                                :class="STATUT_CLASS[data.statut_contrat]"
                            >
                                {{ data.statut_contrat_label }}
                            </span>
                        </template>
                    </Column>

                    <Column
                        field="date_debut"
                        header="Début"
                        sortable
                        style="width: 120px"
                    >
                        <template #body="{ data }">
                            <span class="text-sm">{{ data.date_debut }}</span>
                        </template>
                    </Column>

                    <Column field="date_fin" header="Fin" style="width: 120px">
                        <template #body="{ data }">
                            <span class="text-sm text-muted-foreground">{{
                                data.date_fin ?? '—'
                            }}</span>
                        </template>
                    </Column>

                    <Column header="" style="width: 56px">
                        <template #body="{ data }">
                            <div class="flex justify-end">
                                <DropdownMenu>
                                    <DropdownMenuTrigger as-child>
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            class="h-8 w-8"
                                            ><MoreVertical class="h-4 w-4"
                                        /></Button>
                                    </DropdownMenuTrigger>
                                    <DropdownMenuContent
                                        align="end"
                                        class="w-40"
                                    >
                                        <DropdownMenuItem
                                            v-if="can('rh-contrats.update')"
                                            as-child
                                        >
                                            <Link
                                                :href="`/contrats/${data.id}/edit`"
                                                class="flex w-full items-center gap-2"
                                            >
                                                <Pencil
                                                    class="h-4 w-4"
                                                />Modifier
                                            </Link>
                                        </DropdownMenuItem>
                                        <DropdownMenuSeparator
                                            v-if="can('rh-contrats.delete')"
                                        />
                                        <DropdownMenuItem
                                            v-if="can('rh-contrats.delete')"
                                            class="cursor-pointer text-destructive focus:text-destructive"
                                            @click="confirmDelete(data)"
                                        >
                                            <Trash2 class="h-4 w-4" />Supprimer
                                        </DropdownMenuItem>
                                    </DropdownMenuContent>
                                </DropdownMenu>
                            </div>
                        </template>
                    </Column>

                    <template #empty>
                        <div
                            class="flex flex-col items-center gap-3 py-12 text-muted-foreground"
                        >
                            <Briefcase class="h-10 w-10 opacity-30" />
                            <p class="text-sm">Aucun contrat trouvé.</p>
                        </div>
                    </template>
                </DataTable>
            </div>
        </div>
    </AppLayout>
</template>
