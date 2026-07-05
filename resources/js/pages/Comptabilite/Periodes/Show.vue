<script setup lang="ts">
import DataFilters, {
    type FilterField,
} from '@/components/filters/DataFilters.vue';
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
    ChevronRight,
    Download,
    ExternalLink,
    FileText,
    Lock,
    Trash2,
    Truck,
    Wrench,
} from 'lucide-vue-next';
import Column from 'primevue/column';
import DataTable from 'primevue/datatable';
import { useConfirm } from 'primevue/useconfirm';
import { useToast } from 'primevue/usetoast';
import { computed, onMounted, ref } from 'vue';

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

interface VehiculeCard {
    vehicule_id: string | null;
    vehicule_nom: string;
    vehicule_immat: string | null;
    nb_membres: number;
    nb_commandes: number;
    theorique: number;
    ajuste: number;
    ecart: number;
    equilibre: boolean;
    deja_paye: number;
    reste: number;
}

const props = defineProps<{
    periode: Periode;
    vehicules: VehiculeCard[];
    filters: Record<string, string>;
    recalcul: {
        effectue: boolean;
        nb_fiches: number;
    };
    stats: {
        total_brut: number;
        total_net: number;
        total_paye: number;
        reste: number;
    };
    can: {
        calculer: boolean;
        valider: boolean;
        cloturer: boolean;
        delete: boolean;
        ajuster: boolean;
    };
}>();

const filterFields: FilterField[] = [
    {
        key: 'vehicule',
        label: 'Véhicule',
        type: 'text',
        placeholder: 'Nom ou immatriculation…',
        inline: true,
    },
    {
        key: 'livreur',
        label: 'Livreur',
        type: 'text',
        placeholder: 'Nom du livreur…',
        inline: true,
    },
    {
        key: 'proprietaire',
        label: 'Propriétaire',
        type: 'text',
        placeholder: 'Nom du propriétaire…',
    },
    {
        key: 'etat',
        label: 'État',
        type: 'select',
        inline: true,
        options: [
            { value: 'valide', label: 'Validé' },
            { value: 'a_ajuster', label: 'À ajuster' },
        ],
    },
];

const { onRowClick, bodyRowPt } = useClickableTableRow<VehiculeCard>((v) =>
    props.can.ajuster ? ajustementUrl(v.vehicule_id) : null,
);

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/backoffice/dashboard' },
    { title: 'Comptabilité', href: '/backoffice/comptabilite' },
    { title: 'Périodes', href: '/backoffice/comptabilite/periodes' },
    {
        title: props.periode.reference,
        href: `/backoffice/comptabilite/periodes/${props.periode.id}`,
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
            return `/backoffice/comptabilite/commission-logistique?date_debut=${d}&date_fin=${f}`;
        case 'proprietaire':
            return `/backoffice/comptabilite/commission-proprietaire?date_debut=${d}&date_fin=${f}`;
        case 'salarie':
            return `/backoffice/comptabilite/salaires`;
        default:
            return '/backoffice/comptabilite';
    }
});

function fmt(n: number) {
    return new Intl.NumberFormat('fr-FR').format(Math.round(n)) + ' GNF';
}

const titreMetier = computed(
    () => `Paiement des ${props.periode.type_label.toLowerCase()}`,
);

const periodeFormatee = computed(() => {
    const { date_debut, date_fin } = props.periode;
    if (!date_debut || !date_fin) return null;

    const debut = new Date(date_debut);
    const fin = new Date(date_fin);
    const jourDebut = debut.getDate() === 1 ? '1er' : debut.getDate();
    const moisAnnee = fin.toLocaleDateString('fr-GN', {
        month: 'long',
        year: 'numeric',
    });

    return `${jourDebut} au ${fin.getDate()} ${moisAnnee}`;
});

function routeSegment(vehiculeId: string | null) {
    return vehiculeId ?? 'sans-vehicule';
}

function ajustementUrl(vehiculeId: string | null) {
    return `/backoffice/comptabilite/periodes/${props.periode.id}/ajustements/vehicules/${routeSegment(vehiculeId)}`;
}

function doCalculer() {
    calculerWarning.value = null;
    router.post(
        `/backoffice/comptabilite/periodes/${props.periode.id}/calculer`,
        {},
        {
            preserveScroll: true,
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

// Les fiches sont générées/mises à jour automatiquement côté serveur à l'ouverture de la page
// (cf. PeriodeCalculatorService::calculerSiNecessaire) : pas de clic requis. On informe juste
// l'utilisateur quand ce recalcul silencieux a effectivement eu lieu.
onMounted(() => {
    if (props.recalcul.effectue && props.recalcul.nb_fiches > 0) {
        toast.add({
            severity: 'success',
            summary: 'Fiches mises à jour',
            detail: `${props.recalcul.nb_fiches} fiche(s) recalculée(s) automatiquement.`,
            life: 4000,
        });
    }
});

function doValider() {
    confirm.require({
        message:
            'Valider cette période ? Les fiches ne pourront plus être recalculées.',
        header: 'Confirmer la validation',
        acceptLabel: 'Valider',
        rejectLabel: 'Annuler',
        accept: () =>
            router.post(
                `/backoffice/comptabilite/periodes/${props.periode.id}/valider`,
                {},
                {
                    onSuccess: () => {
                        const flash = (page.props as any).flash;
                        if (flash?.error) {
                            toast.add({
                                severity: 'warn',
                                summary: 'Validation impossible',
                                detail: flash.error,
                                life: 8000,
                            });
                        } else if (flash?.success) {
                            toast.add({
                                severity: 'success',
                                summary: 'Période validée',
                                detail: flash.success,
                                life: 4000,
                            });
                        }
                    },
                },
            ),
    });
}

function doCloturer() {
    confirm.require({
        message: 'Clôturer cette période ? Elle sera archivée définitivement.',
        header: 'Confirmer la clôture',
        acceptLabel: 'Clôturer',
        rejectLabel: 'Annuler',
        accept: () =>
            router.post(
                `/backoffice/comptabilite/periodes/${props.periode.id}/cloturer`,
            ),
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
            router.delete(
                `/backoffice/comptabilite/periodes/${props.periode.id}`,
                {
                    onSuccess: () =>
                        router.visit('/backoffice/comptabilite/periodes'),
                },
            ),
    });
}

function exportExcel() {
    window.open(
        `/backoffice/comptabilite/fiches/export/excel?periode_id=${props.periode.id}`,
        '_blank',
    );
}

function exportPdf() {
    window.open(
        `/backoffice/comptabilite/periodes/${props.periode.id}/pdf`,
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
                        <h1 class="text-xl font-semibold">
                            {{ titreMetier }}
                        </h1>
                        <StatusDot
                            :status="periode.statut"
                            :label="periode.statut_label"
                        />
                    </div>
                    <p class="mt-1 text-sm text-muted-foreground">
                        {{ periodeFormatee ?? '—' }}
                        <span v-if="periode.site">
                            — {{ periode.site.nom }}</span
                        >
                    </p>
                    <p class="mt-0.5 font-mono text-xs text-muted-foreground">
                        Référence : {{ periode.reference }}
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
                        Forcer le recalcul
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
                    <p class="mt-1 text-lg font-bold tabular-nums">
                        {{ fmt(stats.total_brut) }}
                    </p>
                </div>
                <div class="rounded-xl border bg-card p-4">
                    <p class="text-xs text-muted-foreground">Net à payer</p>
                    <p
                        class="mt-1 text-lg font-bold text-emerald-600 tabular-nums dark:text-emerald-400"
                    >
                        {{ fmt(stats.total_net) }}
                    </p>
                </div>
                <div class="rounded-xl border bg-card p-4">
                    <p class="text-xs text-muted-foreground">Déjà payé</p>
                    <p class="mt-1 text-lg font-bold tabular-nums">
                        {{ fmt(stats.total_paye) }}
                    </p>
                </div>
                <div class="rounded-xl border bg-card p-4">
                    <p class="text-xs text-muted-foreground">Reste</p>
                    <p
                        class="mt-1 text-lg font-bold tabular-nums"
                        :class="
                            stats.reste > 0
                                ? 'text-amber-600 dark:text-amber-400'
                                : ''
                        "
                    >
                        {{ fmt(stats.reste) }}
                    </p>
                </div>
            </div>

            <!-- Filtres -->
            <DataFilters
                :url="`/backoffice/comptabilite/periodes/${periode.id}`"
                :values="filters"
                :fields="filterFields"
                :result-count="vehicules.length"
                hide-agence-selector
            />

            <!-- Commissions par véhicule -->
            <!-- data-key="vehicule_id" : point d'extension pour un futur détail par ligne
                 (commandes/commissions composant le montant), via un DataTable expander. -->
            <div class="overflow-x-auto rounded-xl border bg-card">
                <DataTable
                    :value="vehicules"
                    :paginator="vehicules.length > 20"
                    :rows="20"
                    data-key="vehicule_id"
                    striped-rows
                    removable-sort
                    class="text-sm"
                    :pt="{
                        root: { class: 'w-full min-w-[1100px]' },
                        tbody: { class: 'divide-y' },
                        bodyRow: bodyRowPt,
                    }"
                    @row-click="onRowClick"
                >
                    <Column
                        field="vehicule_nom"
                        header="Véhicule"
                        sortable
                        style="min-width: 180px"
                    >
                        <template #body="{ data }">
                            <div class="flex items-center gap-2 font-medium">
                                <Truck
                                    class="h-4 w-4 shrink-0 text-muted-foreground"
                                />
                                {{ data.vehicule_nom }}
                            </div>
                        </template>
                    </Column>

                    <Column
                        field="vehicule_immat"
                        header="Immatriculation"
                        sortable
                        style="min-width: 140px"
                    >
                        <template #body="{ data }">
                            <span class="text-muted-foreground">{{
                                data.vehicule_immat ?? '—'
                            }}</span>
                        </template>
                    </Column>

                    <Column
                        field="nb_commandes"
                        header="Commandes"
                        sortable
                        style="width: 120px"
                    >
                        <template #body="{ data }">
                            <span class="text-muted-foreground tabular-nums">{{
                                data.nb_commandes
                            }}</span>
                        </template>
                    </Column>

                    <Column
                        field="nb_membres"
                        header="Membres"
                        sortable
                        style="width: 110px"
                    >
                        <template #body="{ data }">
                            <span class="text-muted-foreground tabular-nums">{{
                                data.nb_membres
                            }}</span>
                        </template>
                    </Column>

                    <Column
                        field="theorique"
                        header="Montant"
                        sortable
                        style="width: 150px"
                    >
                        <template #body="{ data }">
                            <span class="font-semibold tabular-nums">{{
                                fmt(data.theorique)
                            }}</span>
                        </template>
                    </Column>

                    <Column
                        field="deja_paye"
                        header="Déjà payé"
                        sortable
                        style="width: 140px"
                    >
                        <template #body="{ data }">
                            <span class="text-muted-foreground tabular-nums">{{
                                fmt(data.deja_paye)
                            }}</span>
                        </template>
                    </Column>

                    <Column
                        field="reste"
                        header="Reste à payer"
                        sortable
                        style="width: 140px"
                    >
                        <template #body="{ data }">
                            <span
                                class="tabular-nums"
                                :class="
                                    data.reste > 0
                                        ? 'font-medium text-amber-600 dark:text-amber-400'
                                        : 'text-muted-foreground'
                                "
                                >{{ fmt(data.reste) }}</span
                            >
                        </template>
                    </Column>

                    <Column
                        field="equilibre"
                        header="État"
                        sortable
                        style="width: 130px"
                    >
                        <template #body="{ data }">
                            <StatusDot
                                :status="
                                    data.equilibre ? 'validee' : 'en_attente'
                                "
                                :label="data.equilibre ? 'Validé' : 'À ajuster'"
                            />
                        </template>
                    </Column>

                    <Column header="" style="width: 130px">
                        <template #body="{ data }">
                            <div v-if="can.ajuster" class="flex justify-end">
                                <Link :href="ajustementUrl(data.vehicule_id)">
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        @click.stop
                                    >
                                        <Wrench class="mr-1.5 h-3.5 w-3.5" />
                                        Ajuster
                                        <ChevronRight
                                            class="ml-1 h-3.5 w-3.5"
                                        />
                                    </Button>
                                </Link>
                            </div>
                        </template>
                    </Column>

                    <template #empty>
                        <div
                            class="py-16 text-center text-sm text-muted-foreground"
                        >
                            Aucune commission trouvée pour cette période.
                        </div>
                    </template>
                </DataTable>
            </div>
        </div>
    </AppLayout>
</template>
