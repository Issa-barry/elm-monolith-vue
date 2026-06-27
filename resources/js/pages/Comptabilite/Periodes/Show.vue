<script setup lang="ts">
import StatusDot from '@/components/StatusDot.vue';
import { Button } from '@/components/ui/button';
import { useClickableTableRow } from '@/composables/useClickableTableRow';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import {
    AlertTriangle,
    Calculator,
    CheckCircle,
    Download,
    ExternalLink,
    FileText,
    Lock,
    Trash2,
} from 'lucide-vue-next';
import Column from 'primevue/column';
import DataTable from 'primevue/datatable';
import { useConfirm } from 'primevue/useconfirm';
import { useToast } from 'primevue/usetoast';
import { computed, ref } from 'vue';

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

interface RepartitionAgence {
    site_nom: string;
    nb_beneficiaires: number;
    montant_brut: number;
    total_deductions: number;
    montant_net: number;
    montant_paye: number;
    reste: number;
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
    repartition_agences: RepartitionAgence[];
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
const page = usePage();

const calculerWarning = ref<string | null>(null);

const voirCommissionsUrl = computed(() => {
    const d = props.periode.date_debut ?? '';
    const f = props.periode.date_fin ?? '';
    switch (props.periode.type) {
        case 'livreur':
            return `/comptabilite/commission-logistique?date_debut=${d}&date_fin=${f}`;
        case 'proprietaire':
            return `/comptabilite/commission-proprietaire?date_debut=${d}&date_fin=${f}`;
        case 'salarie':
            return `/comptabilite/salaires`;
        default:
            return '/comptabilite';
    }
});

function fmt(n: number) {
    return new Intl.NumberFormat('fr-FR').format(Math.round(n)) + ' GNF';
}

const { onRowClick, bodyRowPt } = useClickableTableRow<Fiche>(
    (fiche) => `/comptabilite/fiches/${fiche.id}`,
);

function doCalculer() {
    calculerWarning.value = null;
    router.post(
        `/comptabilite/periodes/${props.periode.id}/calculer`,
        {},
        {
            onSuccess: () => {
                const flash = (page.props as any).flash;
                if (flash?.warning) {
                    calculerWarning.value = flash.warning;
                    toast.add({
                        severity: 'warn',
                        summary: 'Aucune donnée trouvée',
                        detail: flash.warning,
                        life: 8000,
                    });
                } else {
                    calculerWarning.value = null;
                    toast.add({
                        severity: 'success',
                        summary: 'Fiches générées',
                        detail: flash?.success ?? '',
                        life: 4000,
                    });
                }
            },
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

function exportPdf() {
    window.open(`/comptabilite/periodes/${props.periode.id}/pdf`, '_blank');
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
                        <StatusDot
                            :status="periode.statut"
                            :label="periode.statut_label"
                        />
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
                        Générer / mettre à jour les fiches
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
                    <Button variant="outline" size="sm" @click="exportPdf">
                        <FileText class="mr-1.5 h-4 w-4" />
                        PDF
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

            <!-- Alerte calcul vide -->
            <div
                v-if="calculerWarning"
                class="flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800 dark:border-amber-800/40 dark:bg-amber-950/20 dark:text-amber-300"
            >
                <AlertTriangle class="mt-0.5 h-4 w-4 shrink-0" />
                <div class="flex-1">
                    <p class="font-medium">Aucune donnée trouvée</p>
                    <p class="mt-0.5">{{ calculerWarning }}</p>
                </div>
                <Link
                    :href="voirCommissionsUrl"
                    class="flex shrink-0 items-center gap-1 text-xs font-medium text-amber-700 underline underline-offset-2 hover:text-amber-900 dark:text-amber-400 dark:hover:text-amber-200"
                >
                    <ExternalLink class="h-3.5 w-3.5" />
                    Voir les commissions de cette période
                </Link>
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

            <!-- Répartition par agence -->
            <div
                v-if="repartition_agences.length > 0"
                class="overflow-hidden rounded-xl border bg-card"
            >
                <div class="border-b px-5 py-3">
                    <h2 class="text-sm font-semibold">
                        Répartition par agence
                    </h2>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr
                                class="border-b bg-muted/40 text-xs text-muted-foreground"
                            >
                                <th class="px-4 py-2.5 text-left font-medium">
                                    Agence
                                </th>
                                <th class="px-4 py-2.5 text-right font-medium">
                                    Bénéficiaires
                                </th>
                                <th class="px-4 py-2.5 text-right font-medium">
                                    Montant brut
                                </th>
                                <th class="px-4 py-2.5 text-right font-medium">
                                    Déductions
                                </th>
                                <th class="px-4 py-2.5 text-right font-medium">
                                    Net à payer
                                </th>
                                <th class="px-4 py-2.5 text-right font-medium">
                                    Déjà payé
                                </th>
                                <th class="px-4 py-2.5 text-right font-medium">
                                    Reste
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="agence in repartition_agences"
                                :key="agence.site_nom"
                                class="border-b last:border-0 hover:bg-muted/30"
                            >
                                <td class="px-4 py-2.5 font-medium">
                                    {{ agence.site_nom }}
                                </td>
                                <td
                                    class="px-4 py-2.5 text-right text-muted-foreground tabular-nums"
                                >
                                    {{ agence.nb_beneficiaires }}
                                </td>
                                <td class="px-4 py-2.5 text-right tabular-nums">
                                    {{ fmt(agence.montant_brut) }}
                                </td>
                                <td
                                    class="px-4 py-2.5 text-right text-red-600 tabular-nums dark:text-red-400"
                                >
                                    {{
                                        agence.total_deductions > 0
                                            ? '-' + fmt(agence.total_deductions)
                                            : '—'
                                    }}
                                </td>
                                <td
                                    class="px-4 py-2.5 text-right font-semibold text-emerald-600 tabular-nums dark:text-emerald-400"
                                >
                                    {{ fmt(agence.montant_net) }}
                                </td>
                                <td class="px-4 py-2.5 text-right tabular-nums">
                                    {{ fmt(agence.montant_paye) }}
                                </td>
                                <td
                                    class="px-4 py-2.5 text-right font-semibold tabular-nums"
                                    :class="
                                        agence.reste > 0
                                            ? 'text-amber-600 dark:text-amber-400'
                                            : 'text-muted-foreground'
                                    "
                                >
                                    {{ fmt(agence.reste) }}
                                </td>
                            </tr>
                        </tbody>
                        <tfoot>
                            <tr
                                class="border-t-2 bg-muted/50 text-xs font-bold"
                            >
                                <td class="px-4 py-2.5 tracking-wide uppercase">
                                    Total
                                </td>
                                <td class="px-4 py-2.5 text-right tabular-nums">
                                    {{ fiches.length }}
                                </td>
                                <td class="px-4 py-2.5 text-right tabular-nums">
                                    {{ fmt(stats.total_brut) }}
                                </td>
                                <td
                                    class="px-4 py-2.5 text-right text-red-600 tabular-nums dark:text-red-400"
                                >
                                    {{
                                        stats.total_deductions > 0
                                            ? '-' + fmt(stats.total_deductions)
                                            : '—'
                                    }}
                                </td>
                                <td
                                    class="px-4 py-2.5 text-right text-emerald-600 tabular-nums dark:text-emerald-400"
                                >
                                    {{ fmt(stats.total_net) }}
                                </td>
                                <td class="px-4 py-2.5 text-right tabular-nums">
                                    {{ fmt(stats.total_paye) }}
                                </td>
                                <td class="px-4 py-2.5 text-right tabular-nums">
                                    {{
                                        fmt(
                                            Math.max(
                                                0,
                                                stats.total_net -
                                                    stats.total_paye,
                                            ),
                                        )
                                    }}
                                </td>
                            </tr>
                        </tfoot>
                    </table>
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
                    :pt="{ bodyRow: bodyRowPt }"
                    @row-click="onRowClick"
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
                            <StatusDot
                                :status="data.statut"
                                :label="data.statut_label"
                            />
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
