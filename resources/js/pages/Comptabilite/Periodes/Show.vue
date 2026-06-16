<script setup lang="ts">
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import {
    Calculator,
    CheckCircle,
    Download,
    Lock,
    Trash2,
} from 'lucide-vue-next';
import Column from 'primevue/column';
import DataTable from 'primevue/datatable';
import { useConfirm } from 'primevue/useconfirm';
import { useToast } from 'primevue/usetoast';

interface Fiche {
    id: string;
    reference: string;
    beneficiaire_nom: string;
    beneficiaire_type: string;
    site: { id: string; nom: string } | null;
    montant_brut: number;
    total_deductions: number;
    montant_net: number;
    montant_paye: number;
    statut: string;
    statut_label: string;
}

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
    observations: string | null;
    nb_fiches: number;
    total_net: number;
    total_paye: number;
}

const props = defineProps<{
    periode: Periode;
    fiches: Fiche[];
    stats: {
        total_brut: number;
        total_deductions: number;
        total_net: number;
        total_paye: number;
        nb_a_payer: number;
        nb_partiellement_paye: number;
        nb_paye: number;
    };
    can: {
        calculer: boolean;
        valider: boolean;
        cloturer: boolean;
        delete: boolean;
    };
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/dashboard' },
    { title: 'Comptabilité', href: '/comptabilite' },
    { title: 'Périodes', href: '/comptabilite/periodes' },
    {
        title: props.periode.reference,
        href: `/comptabilite/periodes/${props.periode.id}`,
    },
];

const confirm = useConfirm();
const toast = useToast();

function fmt(n: number) {
    return new Intl.NumberFormat('fr-FR').format(Math.round(n)) + ' GNF';
}

const statutBadge = (s: string) =>
    ({
        brouillon:
            'bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400',
        calculee:
            'bg-blue-100 text-blue-700 dark:bg-blue-950/30 dark:text-blue-400',
        validee:
            'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/30 dark:text-emerald-400',
        cloturee:
            'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-400',
    })[s] ?? 'bg-muted text-muted-foreground';

const ficheBadge = (s: string) =>
    ({
        a_payer: 'bg-red-100 text-red-700 dark:bg-red-950/30 dark:text-red-400',
        partiellement_paye:
            'bg-amber-100 text-amber-700 dark:bg-amber-950/30 dark:text-amber-400',
        paye: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/30 dark:text-emerald-400',
    })[s] ?? 'bg-muted text-muted-foreground';

function doCalculer() {
    router.post(
        `/comptabilite/periodes/${props.periode.id}/calculer`,
        {},
        {
            onSuccess: () =>
                toast.add({
                    severity: 'success',
                    summary: 'Fiches générées',
                    life: 3000,
                }),
        },
    );
}

function doValider() {
    confirm.require({
        message:
            'Valider cette période ? Les fiches ne pourront plus être recalculées.',
        header: 'Confirmer la validation',
        acceptLabel: 'Valider',
        rejectLabel: 'Annuler',
        accept: () =>
            router.post(`/comptabilite/periodes/${props.periode.id}/valider`),
    });
}

function doCloturer() {
    confirm.require({
        message: 'Clôturer cette période ? Elle sera archivée définitivement.',
        header: 'Confirmer la clôture',
        acceptLabel: 'Clôturer',
        rejectLabel: 'Annuler',
        accept: () =>
            router.post(`/comptabilite/periodes/${props.periode.id}/cloturer`),
    });
}

function doDelete() {
    confirm.require({
        message: 'Supprimer cette période ?',
        header: 'Confirmation',
        acceptLabel: 'Supprimer',
        rejectLabel: 'Annuler',
        acceptClass: 'p-button-danger',
        accept: () =>
            router.delete(`/comptabilite/periodes/${props.periode.id}`, {
                onSuccess: () => router.visit('/comptabilite/periodes'),
            }),
    });
}

function exportExcel() {
    window.open(
        `/comptabilite/fiches/export/excel?periode_id=${props.periode.id}`,
        '_blank',
    );
}
</script>

<template>
    <Head :title="`Période ${periode.reference}`" />
    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-col gap-6 p-6">
            <!-- Header -->
            <div class="flex items-start justify-between">
                <div>
                    <div class="flex items-center gap-3">
                        <h1 class="font-mono text-xl font-semibold">
                            {{ periode.reference }}
                        </h1>
                        <span
                            class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium"
                            :class="statutBadge(periode.statut)"
                        >
                            {{ periode.statut_label }}
                        </span>
                    </div>
                    <p class="mt-1 text-sm text-muted-foreground">
                        {{ periode.type_label }} — {{ periode.date_debut }} au
                        {{ periode.date_fin }}
                        <span v-if="periode.site">
                            — {{ periode.site.nom }}</span
                        >
                    </p>
                    <p
                        v-if="periode.observations"
                        class="mt-1 text-xs text-muted-foreground italic"
                    >
                        {{ periode.observations }}
                    </p>
                </div>

                <div class="flex items-center gap-2">
                    <Button
                        v-if="can.calculer"
                        variant="outline"
                        size="sm"
                        @click="doCalculer"
                    >
                        <Calculator class="mr-1.5 h-4 w-4" />
                        {{
                            periode.statut === 'brouillon'
                                ? 'Générer les fiches'
                                : 'Recalculer'
                        }}
                    </Button>
                    <Button v-if="can.valider" size="sm" @click="doValider">
                        <CheckCircle class="mr-1.5 h-4 w-4" />
                        Valider
                    </Button>
                    <Button
                        v-if="can.cloturer"
                        variant="outline"
                        size="sm"
                        @click="doCloturer"
                    >
                        <Lock class="mr-1.5 h-4 w-4" />
                        Clôturer
                    </Button>
                    <Button variant="outline" size="sm" @click="exportExcel">
                        <Download class="mr-1.5 h-4 w-4" />
                        Excel
                    </Button>
                    <Button
                        v-if="can.delete"
                        variant="ghost"
                        size="icon"
                        class="h-8 w-8 text-destructive hover:text-destructive"
                        @click="doDelete"
                    >
                        <Trash2 class="h-4 w-4" />
                    </Button>
                </div>
            </div>

            <!-- KPI stats -->
            <div class="grid gap-3 sm:grid-cols-4">
                <div class="rounded-xl border bg-card p-4">
                    <p class="text-xs text-muted-foreground">Total brut</p>
                    <p class="mt-1 text-lg font-bold">
                        {{ fmt(stats.total_brut) }}
                    </p>
                </div>
                <div class="rounded-xl border bg-card p-4">
                    <p class="text-xs text-muted-foreground">
                        Total déductions
                    </p>
                    <p
                        class="mt-1 text-lg font-bold text-red-600 dark:text-red-400"
                    >
                        -{{ fmt(stats.total_deductions) }}
                    </p>
                </div>
                <div class="rounded-xl border bg-card p-4">
                    <p class="text-xs text-muted-foreground">Net à payer</p>
                    <p
                        class="mt-1 text-lg font-bold text-emerald-600 dark:text-emerald-400"
                    >
                        {{ fmt(stats.total_net) }}
                    </p>
                </div>
                <div class="rounded-xl border bg-card p-4">
                    <p class="text-xs text-muted-foreground">Payé / Reste</p>
                    <p class="mt-1 text-lg font-bold">
                        {{ fmt(stats.total_paye) }}
                    </p>
                    <p class="text-xs text-muted-foreground">
                        {{ stats.nb_paye }} payé · {{ stats.nb_a_payer }} à
                        payer
                    </p>
                </div>
            </div>

            <!-- Table fiches -->
            <div class="overflow-hidden rounded-xl border bg-card">
                <div class="border-b px-5 py-3">
                    <h2 class="text-sm font-semibold">
                        {{ fiches.length }} fiche{{
                            fiches.length !== 1 ? 's' : ''
                        }}
                    </h2>
                </div>
                <DataTable
                    :value="fiches"
                    data-key="id"
                    striped-rows
                    class="text-sm"
                >
                    <Column header="Bénéficiaire" style="min-width: 200px">
                        <template #body="{ data }">
                            <Link
                                :href="`/comptabilite/fiches/${data.id}`"
                                class="font-medium hover:underline"
                            >
                                {{ data.beneficiaire_nom }}
                            </Link>
                            <div
                                class="font-mono text-xs text-muted-foreground"
                            >
                                {{ data.reference }}
                            </div>
                        </template>
                    </Column>

                    <Column header="Agence" style="width: 140px">
                        <template #body="{ data }">
                            <span class="text-sm text-muted-foreground">{{
                                data.site?.nom ?? '—'
                            }}</span>
                        </template>
                    </Column>

                    <Column header="Gains" style="width: 140px">
                        <template #body="{ data }">
                            <span class="text-sm tabular-nums">{{
                                fmt(data.montant_brut)
                            }}</span>
                        </template>
                    </Column>

                    <Column header="Déductions" style="width: 140px">
                        <template #body="{ data }">
                            <span
                                class="text-sm text-red-600 tabular-nums dark:text-red-400"
                            >
                                -{{ fmt(data.total_deductions) }}
                            </span>
                        </template>
                    </Column>

                    <Column header="Net à payer" style="width: 150px">
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
                            <span
                                class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium"
                                :class="ficheBadge(data.statut)"
                            >
                                {{ data.statut_label }}
                            </span>
                        </template>
                    </Column>

                    <template #empty>
                        <div
                            class="py-16 text-center text-sm text-muted-foreground"
                        >
                            Aucune fiche générée. Cliquez sur "Générer les
                            fiches" pour lancer le calcul.
                        </div>
                    </template>
                </DataTable>
            </div>
        </div>
    </AppLayout>
</template>
