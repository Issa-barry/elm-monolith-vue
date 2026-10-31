<script setup lang="ts">
import DataFilters, {
    type FilterField,
} from '@/components/filters/DataFilters.vue';
import StatusDot from '@/components/StatusDot.vue';
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
import {
    Briefcase,
    Download,
    Eye,
    FilePlus2,
    History,
    MoreVertical,
    Pencil,
    Plus,
    Trash2,
} from 'lucide-vue-next';
import Column from 'primevue/column';
import DataTable from 'primevue/datatable';
import { useConfirm } from 'primevue/useconfirm';
import { useToast } from 'primevue/usetoast';
import { computed } from 'vue';

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
    // Dernière version générée du contrat de travail (ADR 0044).
    document_courant: {
        version: number;
        statut: string;
        statut_label: string;
    } | null;
    // Génération autorisée (permissions + agence de rattachement), calculée par le serveur.
    peut_generer: boolean;
}

interface Option {
    value: string;
    label: string;
}
type Filters = {
    statut_contrat?: string;
    type_contrat?: string;
    site_ids?: string[];
    search?: string;
};

const props = defineProps<{
    contrats: Contrat[];
    filters: Filters;
    // Agences du filtre (agence de l'employé) ; périmètre appliqué par le serveur.
    sites: { id: string; nom: string }[];
    type_contrat_options: Option[];
    statut_contrat_options: Option[];
}>();

const { can } = usePermissions();

// Le menu ⋮ n'est affiché que s'il propose au moins une action.
function aDesActions(contrat: Contrat): boolean {
    return (
        contrat.peut_generer ||
        (contrat.document_courant !== null &&
            can('rh-contrats.read') &&
            can('rh-remunerations.read')) ||
        can('rh-contrats.update') ||
        can('rh-contrats.delete')
    );
}
const confirm = useConfirm();
const toast = useToast();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/backoffice/dashboard' },
    { title: 'Contrats', href: '/backoffice/contrats' },
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

function confirmDelete(c: Contrat) {
    confirm.require({
        message: `Supprimer ce contrat ${c.type_contrat_label} de ${c.employe_nom_complet} ?`,
        header: 'Confirmer la suppression',
        icon: 'pi pi-exclamation-triangle',
        rejectLabel: 'Annuler',
        acceptLabel: 'Supprimer',
        acceptClass: 'p-button-danger',
        accept: () => {
            router.delete(`/backoffice/contrats/${c.id}`, {
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
                <Link
                    v-if="can('rh-contrats.create')"
                    href="/backoffice/contrats/create"
                >
                    <Button
                        ><Plus class="mr-2 h-4 w-4" />Nouveau contrat</Button
                    >
                </Link>
            </div>

            <!-- Filtres -->
            <DataFilters
                url="/backoffice/contrats"
                :values="filters"
                :fields="filterFields"
                :sites="sites"
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
                                :href="`/backoffice/employes/${data.employe_id}/edit`"
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
                            <StatusDot
                                :status="data.statut_contrat"
                                :label="data.statut_contrat_label"
                            />
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

                    <Column header="Contrat de travail" style="width: 170px">
                        <template #body="{ data }">
                            <StatusDot
                                v-if="data.document_courant"
                                :status="data.document_courant.statut"
                                :label="`V${data.document_courant.version} · ${data.document_courant.statut_label}`"
                            />
                            <span v-else class="text-sm text-muted-foreground"
                                >Non généré</span
                            >
                        </template>
                    </Column>

                    <Column header="" style="width: 56px">
                        <template #body="{ data }">
                            <div class="flex justify-end">
                                <DropdownMenu v-if="aDesActions(data)">
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
                                        class="w-56"
                                    >
                                        <DropdownMenuItem
                                            v-if="
                                                data.peut_generer &&
                                                !data.document_courant
                                            "
                                            as-child
                                            data-testid="action-generer-contrat"
                                        >
                                            <Link
                                                :href="`/backoffice/contrats/${data.id}/document`"
                                                class="flex w-full items-center gap-2"
                                            >
                                                <FilePlus2
                                                    class="h-4 w-4"
                                                />Générer le contrat
                                            </Link>
                                        </DropdownMenuItem>
                                        <template
                                            v-if="
                                                can('rh-contrats.read') &&
                                                can('rh-remunerations.read') &&
                                                data.document_courant
                                            "
                                        >
                                            <DropdownMenuItem
                                                as-child
                                                data-testid="action-voir-contrat-pdf"
                                            >
                                                <a
                                                    :href="`/backoffice/contrats/${data.id}/pdf?preview=1`"
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                    class="flex w-full cursor-pointer items-center gap-2"
                                                >
                                                    <Eye class="h-4 w-4" />Voir
                                                    le contrat (PDF)
                                                </a>
                                            </DropdownMenuItem>
                                            <DropdownMenuItem
                                                as-child
                                                data-testid="action-telecharger-contrat-pdf"
                                            >
                                                <a
                                                    :href="`/backoffice/contrats/${data.id}/pdf`"
                                                    class="flex w-full cursor-pointer items-center gap-2"
                                                >
                                                    <Download
                                                        class="h-4 w-4"
                                                    />Télécharger le PDF
                                                </a>
                                            </DropdownMenuItem>
                                            <DropdownMenuItem
                                                as-child
                                                data-testid="action-versions-contrat"
                                            >
                                                <Link
                                                    :href="`/backoffice/contrats/${data.id}/document`"
                                                    class="flex w-full items-center gap-2"
                                                >
                                                    <History
                                                        class="h-4 w-4"
                                                    />{{
                                                        data.peut_generer
                                                            ? 'Versions, régénérer, signer'
                                                            : 'Versions du contrat'
                                                    }}
                                                </Link>
                                            </DropdownMenuItem>
                                        </template>
                                        <DropdownMenuSeparator
                                            v-if="
                                                ((data.document_courant &&
                                                    can(
                                                        'rh-remunerations.read',
                                                    )) ||
                                                    data.peut_generer) &&
                                                (can('rh-contrats.update') ||
                                                    can('rh-contrats.delete'))
                                            "
                                        />
                                        <DropdownMenuItem
                                            v-if="can('rh-contrats.update')"
                                            as-child
                                        >
                                            <Link
                                                :href="`/backoffice/contrats/${data.id}/edit`"
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
