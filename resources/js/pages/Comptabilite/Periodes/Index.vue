<script setup lang="ts">
import ComptabiliteFilters from '@/components/ComptabiliteFilters.vue';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import { Calendar, Plus } from 'lucide-vue-next';
import Column from 'primevue/column';
import DataTable from 'primevue/datatable';
import { computed, ref } from 'vue';

interface Periode {
    id: string;
    reference: string;
    type: string;
    type_label: string;
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

interface Kpis {
    brouillon: number;
    calculee: number;
    validee: number;
    cloturee: number;
}

const props = defineProps<{
    periodes: { data: Periode[]; links: unknown[] };
    types: Option[];
    statuts: Option[];
    filters: {
        type?: string;
        statut?: string;
        date_debut?: string;
        date_fin?: string;
        search?: string;
    };
    kpis: Kpis;
    can_create: boolean;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/dashboard' },
    { title: 'Comptabilité', href: '/comptabilite' },
    { title: 'Périodes', href: '/comptabilite/periodes' },
];

const selectedType = ref(props.filters.type ?? '');
const selectedStatut = ref(props.filters.statut ?? '');
const searchVal = ref(props.filters.search ?? '');

const typeOptions = computed(() => [
    { label: 'Tous les types', value: '' },
    ...props.types,
]);
const statutOptions = computed(() => [
    { label: 'Tous les statuts', value: '' },
    ...props.statuts,
]);

const hasActiveFilters = computed(
    () => !!(selectedType.value || selectedStatut.value || searchVal.value),
);

function applyFilters() {
    router.get(
        '/comptabilite/periodes',
        {
            type: selectedType.value || undefined,
            statut: selectedStatut.value || undefined,
            search: searchVal.value || undefined,
        },
        { preserveState: true, replace: true },
    );
}

function resetFilters() {
    selectedType.value = '';
    selectedStatut.value = '';
    searchVal.value = '';
    router.get(
        '/comptabilite/periodes',
        {},
        { preserveState: true, replace: true },
    );
}

function fmt(n: number) {
    return new Intl.NumberFormat('fr-FR').format(Math.round(n)) + ' GNF';
}

const typeBadge = (type: string) =>
    ({
        livreur:
            'bg-blue-100 text-blue-700 dark:bg-blue-950/30 dark:text-blue-400',
        proprietaire:
            'bg-violet-100 text-violet-700 dark:bg-violet-950/30 dark:text-violet-400',
        salarie:
            'bg-orange-100 text-orange-700 dark:bg-orange-950/30 dark:text-orange-400',
    })[type] ?? 'bg-muted text-muted-foreground';

const statutBadge = (statut: string) =>
    ({
        brouillon:
            'bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400',
        calculee:
            'bg-blue-100 text-blue-700 dark:bg-blue-950/30 dark:text-blue-400',
        validee:
            'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/30 dark:text-emerald-400',
        cloturee:
            'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-400',
    })[statut] ?? 'bg-muted text-muted-foreground';
</script>

<template>
    <Head title="Périodes de paiement" />
    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-col gap-6 p-6">
            <!-- Header -->
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-semibold tracking-tight">
                        Périodes de paiement
                    </h1>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Gérer les cycles de paiement livreurs, propriétaires et
                        salariés
                    </p>
                </div>
                <Link v-if="can_create" href="/comptabilite/periodes/creer">
                    <Button>
                        <Plus class="mr-2 h-4 w-4" />
                        Nouvelle période
                    </Button>
                </Link>
            </div>

            <!-- KPI cards -->
            <div class="grid gap-3 sm:grid-cols-4">
                <div class="rounded-xl border bg-card p-4 text-center">
                    <p
                        class="text-2xl font-bold text-zinc-600 dark:text-zinc-400"
                    >
                        {{ kpis.brouillon }}
                    </p>
                    <p class="mt-1 text-xs text-muted-foreground">Brouillon</p>
                </div>
                <div class="rounded-xl border bg-card p-4 text-center">
                    <p
                        class="text-2xl font-bold text-blue-600 dark:text-blue-400"
                    >
                        {{ kpis.calculee }}
                    </p>
                    <p class="mt-1 text-xs text-muted-foreground">Calculée</p>
                </div>
                <div class="rounded-xl border bg-card p-4 text-center">
                    <p
                        class="text-2xl font-bold text-emerald-600 dark:text-emerald-400"
                    >
                        {{ kpis.validee }}
                    </p>
                    <p class="mt-1 text-xs text-muted-foreground">Validée</p>
                </div>
                <div class="rounded-xl border bg-card p-4 text-center">
                    <p class="text-2xl font-bold text-slate-500">
                        {{ kpis.cloturee }}
                    </p>
                    <p class="mt-1 text-xs text-muted-foreground">Clôturée</p>
                </div>
            </div>

            <!-- Filtres -->
            <ComptabiliteFilters
                v-model:search="searchVal"
                search-placeholder="Rechercher une référence, un type..."
                :has-active-filters="hasActiveFilters"
                @filter="applyFilters"
                @reset="resetFilters"
            >
                <select
                    v-model="selectedType"
                    class="h-9 w-[180px] rounded-md border border-input bg-background px-2 text-sm"
                >
                    <option
                        v-for="t in typeOptions"
                        :key="t.value"
                        :value="t.value"
                    >
                        {{ t.label }}
                    </option>
                </select>
                <select
                    v-model="selectedStatut"
                    class="h-9 w-[160px] rounded-md border border-input bg-background px-2 text-sm"
                >
                    <option
                        v-for="s in statutOptions"
                        :key="s.value"
                        :value="s.value"
                    >
                        {{ s.label }}
                    </option>
                </select>
            </ComptabiliteFilters>

            <!-- Table -->
            <div class="overflow-hidden rounded-xl border bg-card">
                <DataTable
                    :value="periodes.data"
                    data-key="id"
                    striped-rows
                    class="text-sm"
                >
                    <Column
                        field="reference"
                        header="Référence"
                        style="width: 180px"
                    >
                        <template #body="{ data }">
                            <Link
                                :href="`/comptabilite/periodes/${data.id}`"
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
                            <span
                                class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium"
                                :class="statutBadge(data.statut)"
                            >
                                {{ data.statut_label }}
                            </span>
                        </template>
                    </Column>

                    <Column header="" style="width: 80px">
                        <template #body="{ data }">
                            <Link
                                :href="`/comptabilite/periodes/${data.id}`"
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
