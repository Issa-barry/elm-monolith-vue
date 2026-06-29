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
    MoreVertical,
    Pencil,
    Plus,
    Trash2,
    UserRound,
} from 'lucide-vue-next';
import Column from 'primevue/column';
import DataTable from 'primevue/datatable';
import { useConfirm } from 'primevue/useconfirm';
import { useToast } from 'primevue/usetoast';

interface ContratActif {
    id: string;
    type_contrat: string;
    type_contrat_label: string;
    date_debut: string | null;
    date_fin: string | null;
}

interface Employe {
    id: string;
    matricule: string | null;
    nom_complet: string;
    nom: string;
    prenom: string;
    email: string | null;
    telephone: string | null;
    type_employe: string;
    type_employe_label: string;
    statut: string;
    statut_label: string;
    site: string | null;
    contrat_actif: ContratActif | null;
}

interface Option {
    value: string;
    label: string;
}

interface Filters {
    statut?: string;
    type_employe?: string;
    type_contrat?: string;
    search?: string;
}

const props = defineProps<{
    employes: Employe[];
    filters: Filters;
    statut_options: Option[];
    type_employe_options: Option[];
    type_contrat_options: Option[];
}>();

const { can } = usePermissions();
const confirm = useConfirm();
const toast = useToast();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/dashboard' },
    { title: 'Employés', href: '/employes' },
];

const filterFields: FilterField[] = [
    {
        key: 'statut',
        label: 'Statut',
        type: 'select',
        options: props.statut_options,
    },
    {
        key: 'type_employe',
        label: 'Type',
        type: 'select',
        options: props.type_employe_options,
    },
    {
        key: 'type_contrat',
        label: 'Contrat',
        type: 'select',
        options: props.type_contrat_options,
    },
];

// ── Couleurs ──────────────────────────────────────────────────────────────────
const STATUT_DOT: Record<string, string> = {
    actif: 'bg-emerald-500',
    suspendu: 'bg-amber-400',
    sorti: 'bg-zinc-400 dark:bg-zinc-500',
};

const TYPE_EMPLOYE_CLASS: Record<string, string> = {
    interne: 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400',
    externe:
        'bg-purple-100 text-purple-700 dark:bg-purple-900/30 dark:text-purple-400',
};

const TYPE_CONTRAT_CLASS: Record<string, string> = {
    cdi: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400',
    cdd: 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400',
};

function dotClass(statut: string) {
    return STATUT_DOT[statut] ?? 'bg-zinc-400';
}
function typeEmployeClass(type: string) {
    return TYPE_EMPLOYE_CLASS[type] ?? 'bg-muted text-muted-foreground';
}
function typeContratClass(type: string) {
    return TYPE_CONTRAT_CLASS[type] ?? 'bg-muted text-muted-foreground';
}

function initials(name: string) {
    return name
        .trim()
        .split(/\s+/)
        .map((w) => w[0])
        .slice(0, 2)
        .join('')
        .toUpperCase();
}

// ── Suppression ───────────────────────────────────────────────────────────────
function confirmDelete(e: Employe) {
    confirm.require({
        message: `Supprimer l'employé ${e.nom_complet} ? Cette action est irréversible.`,
        header: 'Confirmer la suppression',
        icon: 'pi pi-exclamation-triangle',
        rejectLabel: 'Annuler',
        acceptLabel: 'Supprimer',
        acceptClass: 'p-button-danger',
        accept: () => {
            router.delete(`/employes/${e.id}`, {
                onSuccess: () =>
                    toast.add({
                        severity: 'success',
                        summary: 'Supprimé',
                        detail: `${e.nom_complet} a été supprimé.`,
                        life: 3000,
                    }),
            });
        },
    });
}
</script>

<template>
    <Head><title>Employés</title></Head>
    <AppLayout :breadcrumbs="breadcrumbs" :hide-mobile-header="true">
        <div class="flex flex-col gap-6 p-6">
            <!-- Header -->
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-semibold tracking-tight">
                        Employés
                    </h1>
                    <p class="mt-1 text-sm text-muted-foreground">
                        {{ employes.length }} résultat{{
                            employes.length !== 1 ? 's' : ''
                        }}
                    </p>
                </div>
                <Link v-if="can('rh-employes.create')" href="/employes/create">
                    <Button><Plus class="mr-2 h-4 w-4" />Nouvel employé</Button>
                </Link>
            </div>

            <!-- Filtres -->
            <DataFilters
                url="/employes"
                :values="filters"
                :fields="filterFields"
                :result-count="employes.length"
            />

            <!-- Table -->
            <div class="overflow-hidden rounded-xl border bg-card">
                <DataTable
                    :value="employes"
                    :paginator="employes.length > 25"
                    :rows="25"
                    data-key="id"
                    striped-rows
                    removable-sort
                    class="text-sm"
                    table-class="w-full"
                >
                    <!-- Employé -->
                    <Column
                        field="nom_complet"
                        header="Employé"
                        sortable
                        style="min-width: 220px"
                    >
                        <template #body="{ data }">
                            <div class="flex items-center gap-3">
                                <div
                                    class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-primary/10 text-xs font-semibold text-primary"
                                >
                                    {{ initials(data.nom_complet) }}
                                </div>
                                <div>
                                    <div class="font-medium">
                                        {{ data.nom_complet }}
                                    </div>
                                    <div class="text-xs text-muted-foreground">
                                        {{ data.email }}
                                    </div>
                                </div>
                            </div>
                        </template>
                    </Column>

                    <!-- Matricule -->
                    <Column
                        field="matricule"
                        header="Matricule"
                        sortable
                        style="width: 110px"
                    >
                        <template #body="{ data }">
                            <span
                                v-if="data.matricule"
                                class="rounded bg-muted px-2 py-0.5 font-mono text-xs text-muted-foreground"
                            >
                                {{ data.matricule }}
                            </span>
                            <span v-else class="text-xs text-muted-foreground"
                                >—</span
                            >
                        </template>
                    </Column>

                    <!-- Type employé -->
                    <Column
                        field="type_employe_label"
                        header="Type"
                        sortable
                        style="width: 120px"
                    >
                        <template #body="{ data }">
                            <span
                                class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium"
                                :class="typeEmployeClass(data.type_employe)"
                            >
                                {{ data.type_employe_label }}
                            </span>
                        </template>
                    </Column>

                    <!-- Contrat actif -->
                    <Column header="Contrat actif" style="width: 140px">
                        <template #body="{ data }">
                            <span
                                v-if="data.contrat_actif"
                                class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium"
                                :class="
                                    typeContratClass(
                                        data.contrat_actif.type_contrat,
                                    )
                                "
                            >
                                <Briefcase class="mr-1 h-3 w-3" />
                                {{ data.contrat_actif.type_contrat_label }}
                            </span>
                            <span v-else class="text-xs text-muted-foreground"
                                >—</span
                            >
                        </template>
                    </Column>

                    <!-- Site -->
                    <Column
                        field="site"
                        header="Site"
                        sortable
                        style="width: 160px"
                    >
                        <template #body="{ data }">
                            <span class="text-sm text-muted-foreground">{{
                                data.site ?? '—'
                            }}</span>
                        </template>
                    </Column>

                    <!-- Statut -->
                    <Column
                        field="statut_label"
                        header="Statut"
                        sortable
                        style="width: 110px"
                    >
                        <template #body="{ data }">
                            <StatusDot
                                :label="data.statut_label"
                                :dot-class="dotClass(data.statut)"
                                class="text-muted-foreground"
                            />
                        </template>
                    </Column>

                    <!-- Actions -->
                    <Column header="" style="width: 56px">
                        <template #body="{ data }">
                            <div class="flex justify-end">
                                <DropdownMenu>
                                    <DropdownMenuTrigger as-child>
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            class="h-8 w-8"
                                        >
                                            <MoreVertical class="h-4 w-4" />
                                        </Button>
                                    </DropdownMenuTrigger>
                                    <DropdownMenuContent
                                        align="end"
                                        class="w-44"
                                    >
                                        <DropdownMenuItem
                                            v-if="can('rh-employes.update')"
                                            as-child
                                        >
                                            <Link
                                                :href="`/employes/${data.id}/edit`"
                                                class="flex w-full items-center gap-2"
                                            >
                                                <Pencil
                                                    class="h-4 w-4"
                                                />Modifier
                                            </Link>
                                        </DropdownMenuItem>
                                        <DropdownMenuItem
                                            v-if="can('rh-contrats.create')"
                                            as-child
                                        >
                                            <Link
                                                :href="`/contrats/create?employe_id=${data.id}`"
                                                class="flex w-full items-center gap-2"
                                            >
                                                <Briefcase
                                                    class="h-4 w-4"
                                                />Nouveau contrat
                                            </Link>
                                        </DropdownMenuItem>
                                        <DropdownMenuSeparator
                                            v-if="can('rh-employes.delete')"
                                        />
                                        <DropdownMenuItem
                                            v-if="can('rh-employes.delete')"
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
                            class="flex flex-col items-center gap-3 py-16 text-muted-foreground"
                        >
                            <UserRound class="h-12 w-12 opacity-30" />
                            <p class="text-sm">Aucun employé trouvé.</p>
                            <Link
                                v-if="can('rh-employes.create')"
                                href="/employes/create"
                            >
                                <Button variant="outline" size="sm">
                                    <Plus class="mr-2 h-4 w-4" />Créer le
                                    premier employé
                                </Button>
                            </Link>
                        </div>
                    </template>
                </DataTable>
            </div>
        </div>
    </AppLayout>
</template>
