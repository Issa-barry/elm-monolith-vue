<script setup lang="ts">
import AuditDrawer from '@/components/AuditDrawer.vue';
import ClickableTableRow from '@/components/ClickableTableRow.vue';
import DataFilters, {
    type FilterField,
} from '@/components/filters/DataFilters.vue';
import PaymentDialogCompact from '@/components/PaymentDialogCompact.vue';
import StatusDot from '@/components/StatusDot.vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import type {
    PeriodeAffichee,
    StatutCommissionResolu,
} from '@/types/commission-status';
import { Head, Link, router } from '@inertiajs/vue3';
import {
    Building2,
    ChevronDown,
    Download,
    ExternalLink,
    FileSpreadsheet,
    FileText,
    HandCoins,
    History,
    Info,
    MoreHorizontal,
    Truck,
    User,
} from 'lucide-vue-next';
import Dialog from 'primevue/dialog';
import { computed, ref } from 'vue';

interface VehiculeInfo {
    id: string;
    nom: string;
    immatriculation: string | null;
    sites: { id: string; nom: string }[];
    commission_generee: number;
}

interface BeneficiaireRow extends StatutCommissionResolu {
    beneficiaire_id: string;
    beneficiaire_nom: string;
    telephone: string | null;
    vehicules: VehiculeInfo[];
    agence: string | null;
    total_brut_cumule: number;
    total_frais: number;
    total_net_cumule: number;
    total_verse: number;
    solde_restant: number;
    remaining_amount: number;
    nb_commandes: number;
    statut_global: string;
    total_genere: number;
    en_attente_periode?: number;
    payable?: number;
}

interface PeriodeOption {
    code: string;
    label: string;
}

const props = defineProps<{
    beneficiaires: BeneficiaireRow[];
    kpis: {
        nb_proprietaires: number;
        total_brut: number;
        total_net: number;
        total_frais: number;
        total_verse: number;
        solde_total: number;
        total_genere: number;
        en_attente_periode?: number;
        payable?: number;
    };
    filtre_nom: string;
    filtre_telephone: string;
    filtre_statut: string;
    filtre_site_ids: string[];
    selected_periode: string;
    periodes_disponibles: PeriodeOption[];
    periode_courante: string;
    periode_affichee: PeriodeAffichee | null;
    sites: { id: string; nom: string }[];
    can_payer: boolean;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/backoffice/dashboard' },
    { title: 'Comptabilité', href: '/backoffice/comptabilite' },
    {
        title: 'Commission propriétaire',
        href: '/backoffice/comptabilite/commissions/proprietaires',
    },
];

const filterFields = computed((): FilterField[] => [
    {
        key: 'nom',
        label: 'Nom complet',
        type: 'text' as const,
        placeholder: 'Nom du propriétaire…',
    },
    {
        key: 'telephone',
        label: 'Téléphone',
        type: 'text' as const,
        placeholder: 'Numéro…',
    },
    {
        key: 'statut',
        label: 'Statut',
        type: 'select' as const,
        options: [
            { value: 'creee', label: 'Créée — en attente de période' },
            { value: 'impaye', label: 'Impayé' },
            { value: 'partiel', label: 'Partiel' },
            { value: 'paye', label: 'Payé' },
        ],
    },
    {
        key: 'periode',
        label: 'Période',
        type: 'select' as const,
        options: props.periodes_disponibles.map((p) => ({
            value: p.code,
            label: p.label,
        })),
    },
]);

const currentFilters = computed(() => ({
    site_ids: props.filtre_site_ids ?? [],
    nom: props.filtre_nom ?? '',
    telephone: props.filtre_telephone ?? '',
    statut: props.filtre_statut ?? '',
    periode: props.selected_periode ?? '',
}));

// Dialog paiement
const showPaiementDialog = ref(false);
const selectedBenef = ref<BeneficiaireRow | null>(null);
const paiementProcessing = ref(false);
const paiementErrors = ref<Record<string, string>>({});

const showAudit = ref(false);
const auditBenefId = ref('');
const auditBenefNom = ref('');

const vehiculesDialogVisible = ref(false);
const selectedBenefForVehicules = ref<BeneficiaireRow | null>(null);

function openVehiculesDialog(b: BeneficiaireRow) {
    selectedBenefForVehicules.value = b;
    vehiculesDialogVisible.value = true;
}

function vehiculeCountLabel(count: number): string {
    return `${count} véhicule${count !== 1 ? 's' : ''}`;
}

function sitesLabel(vehicule: VehiculeInfo): string {
    return vehicule.sites.map((site) => site.nom).join(', ') || '—';
}

function openAudit(b: BeneficiaireRow) {
    auditBenefId.value = b.beneficiaire_id;
    auditBenefNom.value = b.beneficiaire_nom;
    showAudit.value = true;
}

function openPaiement(b: BeneficiaireRow) {
    selectedBenef.value = b;
    showPaiementDialog.value = true;
}

function handlePaiementSubmit(payload: {
    montant: number;
    mode_paiement: string;
}) {
    if (!selectedBenef.value) return;
    paiementProcessing.value = true;
    paiementErrors.value = {};
    router.post(
        `/backoffice/comptabilite/commissions/proprietaires/${selectedBenef.value.beneficiaire_id}/paiements`,
        payload,
        {
            preserveScroll: true,
            onSuccess: () => {
                showPaiementDialog.value = false;
            },
            onError: (e) => {
                paiementErrors.value = e as Record<string, string>;
            },
            onFinish: () => {
                paiementProcessing.value = false;
            },
        },
    );
}

function buildParams(): URLSearchParams {
    const params = new URLSearchParams();
    if (props.selected_periode) params.set('periode', props.selected_periode);
    for (const id of props.filtre_site_ids ?? []) {
        params.append('site_ids[]', id);
    }
    if (props.filtre_statut) params.set('statut', props.filtre_statut);
    if (props.filtre_nom) params.set('nom', props.filtre_nom);
    if (props.filtre_telephone) params.set('telephone', props.filtre_telephone);
    return params;
}

function exportExcel() {
    window.open(
        '/backoffice/comptabilite/commissions/proprietaires/export/excel?' +
            buildParams().toString(),
        '_blank',
    );
}

function exportPdf() {
    window.open(
        '/backoffice/comptabilite/commissions/proprietaires/export/pdf?' +
            buildParams().toString(),
        '_blank',
    );
}

const periodContextLabel = computed(() => {
    if (!props.selected_periode) return 'Toutes les périodes';

    return (
        props.periodes_disponibles.find(
            (periode) => periode.code === props.selected_periode,
        )?.label ?? props.selected_periode
    );
});

function statusValue(b: BeneficiaireRow): string {
    return b.statut_global === 'creee' ? 'en_attente' : b.display_status;
}

function statusLabel(b: BeneficiaireRow): string {
    return b.statut_global === 'creee' ? 'Partage à valider' : b.display_label;
}

function fmt(val: number | null | undefined) {
    return (
        new Intl.NumberFormat('fr-FR')
            .format(Math.round(Math.abs(Number(val ?? 0))))
            .replace(/\u202f/g, '\u00a0') + ' GNF'
    );
}

function fmtTel(tel: string | null | undefined): string {
    if (!tel) return '—';
    const digits = tel.replace(/\s/g, '');
    if (digits.startsWith('+')) {
        const cc = digits.slice(0, 4);
        const rest = digits.slice(4).replace(/(\d{3})(?=\d)/g, '$1 ');
        return cc + ' ' + rest;
    }
    return digits.replace(/(\d{3})(?=\d)/g, '$1 ');
}
</script>

<template>
    <Head title="Commission propriétaire — Comptabilité" />
    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="space-y-5 p-4 sm:p-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-semibold tracking-tight">
                        Commission propriétaire
                    </h1>
                    <div
                        class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-muted-foreground"
                    >
                        <span>
                            {{ kpis.nb_proprietaires }} propriétaire{{
                                kpis.nb_proprietaires !== 1 ? 's' : ''
                            }}
                        </span>
                        <span aria-hidden="true">·</span>
                        <span>{{ periodContextLabel }}</span>
                        <template v-if="selected_periode && periode_affichee">
                            <span aria-hidden="true">·</span>
                            <StatusDot
                                :status="periode_affichee.statut"
                                :label="periode_affichee.statut_label"
                            />
                        </template>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <DropdownMenu>
                        <DropdownMenuTrigger as-child>
                            <Button variant="outline">
                                <Download class="mr-2 h-4 w-4" />
                                Exporter
                                <ChevronDown class="ml-2 h-3.5 w-3.5" />
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" class="w-48">
                            <DropdownMenuItem
                                class="cursor-pointer"
                                @click="exportExcel"
                            >
                                <FileSpreadsheet class="h-4 w-4" />
                                Exporter en Excel
                            </DropdownMenuItem>
                            <DropdownMenuItem
                                class="cursor-pointer"
                                @click="exportPdf"
                            >
                                <FileText class="h-4 w-4" />
                                Exporter en PDF
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                    <DataFilters
                        trigger-only
                        url="/backoffice/comptabilite/commissions/proprietaires"
                        :values="currentFilters"
                        :fields="filterFields"
                        :sites="sites"
                        :result-count="beneficiaires.length"
                    />
                </div>
            </div>

            <TooltipProvider :delay-duration="150">
                <div
                    data-testid="commission-summary-cards"
                    aria-label="Synthèse des commissions"
                    class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4"
                >
                    <div class="rounded-xl border bg-card p-4 shadow-sm">
                        <div
                            class="flex items-center gap-1.5 text-sm text-muted-foreground"
                        >
                            <span>Commissions générées</span>
                            <Tooltip>
                                <TooltipTrigger as-child>
                                    <button
                                        type="button"
                                        aria-label="Définition des commissions générées"
                                        class="rounded-sm text-muted-foreground/70 transition-colors outline-none hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring"
                                    >
                                        <Info class="h-3.5 w-3.5" />
                                    </button>
                                </TooltipTrigger>
                                <TooltipContent class="max-w-xs">
                                    <p>
                                        Total des commissions calculées à partir
                                        des ventes, avant validation de la
                                        période.
                                    </p>
                                </TooltipContent>
                            </Tooltip>
                        </div>
                        <p
                            class="mt-1.5 text-2xl font-bold whitespace-nowrap text-foreground tabular-nums"
                        >
                            {{ fmt(kpis.total_genere) }}
                        </p>
                    </div>
                    <div class="rounded-xl border bg-card p-4 shadow-sm">
                        <div
                            class="flex items-center gap-1.5 text-sm text-muted-foreground"
                        >
                            <span>Dépenses</span>
                            <Tooltip>
                                <TooltipTrigger as-child>
                                    <button
                                        type="button"
                                        aria-label="Définition des dépenses"
                                        class="rounded-sm text-muted-foreground/70 transition-colors outline-none hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring"
                                    >
                                        <Info class="h-3.5 w-3.5" />
                                    </button>
                                </TooltipTrigger>
                                <TooltipContent class="max-w-xs">
                                    <p>
                                        Dépenses déduites des commissions déjà
                                        validées.
                                    </p>
                                </TooltipContent>
                            </Tooltip>
                        </div>
                        <p
                            class="mt-1.5 text-2xl font-bold whitespace-nowrap tabular-nums"
                            :class="
                                kpis.total_frais > 0
                                    ? 'text-red-600 dark:text-red-400'
                                    : 'text-foreground'
                            "
                        >
                            {{
                                kpis.total_frais > 0
                                    ? '-' + fmt(kpis.total_frais)
                                    : fmt(0)
                            }}
                        </p>
                    </div>
                    <div class="rounded-xl border bg-card p-4 shadow-sm">
                        <div
                            class="flex items-center gap-1.5 text-sm text-muted-foreground"
                        >
                            <span>Net validé</span>
                            <Tooltip>
                                <TooltipTrigger as-child>
                                    <button
                                        type="button"
                                        aria-label="Définition du net validé"
                                        class="rounded-sm text-muted-foreground/70 transition-colors outline-none hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring"
                                    >
                                        <Info class="h-3.5 w-3.5" />
                                    </button>
                                </TooltipTrigger>
                                <TooltipContent class="max-w-xs">
                                    <p>
                                        Montant validé après déduction des
                                        dépenses et prise en compte des
                                        ajustements.
                                    </p>
                                </TooltipContent>
                            </Tooltip>
                        </div>
                        <p
                            class="mt-1.5 text-2xl font-bold whitespace-nowrap tabular-nums"
                            :class="
                                kpis.solde_total > 0
                                    ? 'text-amber-600 dark:text-amber-400'
                                    : 'text-foreground'
                            "
                        >
                            {{ fmt(kpis.total_net) }}
                        </p>
                    </div>
                    <div class="rounded-xl border bg-card p-4 shadow-sm">
                        <div
                            class="flex items-center gap-1.5 text-sm text-muted-foreground"
                        >
                            <span>Reste à payer</span>
                            <Tooltip>
                                <TooltipTrigger as-child>
                                    <button
                                        type="button"
                                        aria-label="Définition du reste à payer"
                                        class="rounded-sm text-muted-foreground/70 transition-colors outline-none hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring"
                                    >
                                        <Info class="h-3.5 w-3.5" />
                                    </button>
                                </TooltipTrigger>
                                <TooltipContent class="max-w-xs">
                                    <p>
                                        Net validé restant à verser après les
                                        paiements déjà effectués.
                                    </p>
                                </TooltipContent>
                            </Tooltip>
                        </div>
                        <p
                            class="mt-1.5 text-2xl font-bold whitespace-nowrap text-foreground tabular-nums"
                        >
                            {{ fmt(kpis.solde_total) }}
                        </p>
                    </div>
                </div>
            </TooltipProvider>

            <div class="overflow-hidden rounded-xl border bg-card shadow-sm">
                <div
                    class="flex items-center justify-between gap-4 border-b px-5 py-3"
                >
                    <h2 class="text-base font-semibold">
                        Détail par propriétaire
                    </h2>
                    <span class="text-xs text-muted-foreground">
                        {{ beneficiaires.length }} résultat{{
                            beneficiaires.length !== 1 ? 's' : ''
                        }}
                    </span>
                </div>
                <div
                    data-testid="commission-table-scroll"
                    class="max-w-full overflow-x-auto pb-1"
                >
                    <table
                        v-if="beneficiaires.length > 0"
                        class="w-full min-w-[1580px] text-sm"
                    >
                        <thead>
                            <tr class="border-b bg-muted/50">
                                <th
                                    scope="col"
                                    class="sticky left-0 z-20 w-[240px] min-w-[240px] border-r bg-muted px-4 py-3 text-left font-semibold text-foreground/70"
                                >
                                    Propriétaire
                                </th>
                                <th
                                    scope="col"
                                    class="px-4 py-3 text-left font-semibold text-foreground/70"
                                >
                                    Véhicule(s)
                                </th>
                                <th
                                    scope="col"
                                    class="px-4 py-3 text-left font-semibold text-foreground/70"
                                >
                                    Agence
                                </th>
                                <th
                                    scope="col"
                                    title="Montant calculé avant validation de la direction"
                                    class="px-4 py-3 text-right font-semibold text-foreground/70"
                                >
                                    Généré
                                </th>
                                <th
                                    scope="col"
                                    title="Montant validé avant déduction des dépenses"
                                    class="px-4 py-3 text-right font-semibold whitespace-nowrap text-foreground/70"
                                >
                                    Brut validé
                                </th>
                                <th
                                    scope="col"
                                    title="Dépenses déduites des commissions validées"
                                    class="px-4 py-3 text-right font-semibold text-foreground/70"
                                >
                                    Dépenses
                                </th>
                                <th
                                    scope="col"
                                    title="Montant validé après dépenses et ajustements"
                                    class="px-4 py-3 text-right font-semibold text-foreground/70"
                                >
                                    Net validé
                                </th>
                                <th
                                    scope="col"
                                    class="px-4 py-3 text-right font-semibold text-foreground/70"
                                >
                                    Déjà payé
                                </th>
                                <th
                                    scope="col"
                                    class="px-4 py-3 text-right font-semibold text-foreground/70"
                                >
                                    Reste à payer
                                </th>
                                <th
                                    scope="col"
                                    class="px-4 py-3 text-left font-semibold text-foreground/70"
                                >
                                    Statut
                                </th>
                                <th
                                    scope="col"
                                    aria-label="Actions"
                                    class="sticky right-0 z-20 w-10 border-l bg-muted px-3 py-3"
                                />
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <ClickableTableRow
                                v-for="b in beneficiaires"
                                :key="b.beneficiaire_id"
                                :href="`/backoffice/comptabilite/commissions/proprietaires/${b.beneficiaire_id}`"
                                :aria-label="`Voir le détail de ${b.beneficiaire_nom}`"
                                class="group even:bg-muted/20"
                            >
                                <td
                                    class="sticky left-0 z-10 w-[240px] min-w-[240px] border-r bg-card px-4 py-3 group-hover:bg-muted/50 group-focus-visible:bg-muted/50"
                                >
                                    <div class="flex items-center gap-2.5">
                                        <User
                                            class="h-4 w-4 shrink-0 text-muted-foreground"
                                        />
                                        <div>
                                            <p class="font-semibold">
                                                {{ b.beneficiaire_nom }}
                                            </p>
                                            <p
                                                v-if="b.telephone"
                                                class="mt-0.5 text-xs text-muted-foreground"
                                            >
                                                {{ fmtTel(b.telephone) }}
                                            </p>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-4 py-3">
                                    <button
                                        v-if="b.vehicules.length"
                                        type="button"
                                        :aria-label="`Voir les ${vehiculeCountLabel(b.vehicules.length)} ayant généré des ventes pour ${b.beneficiaire_nom}`"
                                        class="inline-flex items-center gap-1.5 text-sm font-medium whitespace-nowrap text-primary hover:underline focus-visible:rounded-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none"
                                        data-testid="contributing-vehicles-trigger"
                                        @click="openVehiculesDialog(b)"
                                    >
                                        <Truck class="h-4 w-4 shrink-0" />
                                        <span>{{
                                            vehiculeCountLabel(
                                                b.vehicules.length,
                                            )
                                        }}</span>
                                        <ExternalLink
                                            class="h-3.5 w-3.5 shrink-0"
                                            aria-hidden="true"
                                        />
                                    </button>
                                    <span
                                        v-else
                                        class="text-xs text-muted-foreground"
                                        >—</span
                                    >
                                </td>
                                <td class="px-4 py-3 text-sm">
                                    <div
                                        v-if="b.agence"
                                        class="flex items-center gap-1.5 text-muted-foreground"
                                    >
                                        <Building2
                                            class="h-3.5 w-3.5 shrink-0"
                                        />
                                        <span>{{ b.agence }}</span>
                                    </div>
                                    <span
                                        v-else
                                        class="text-xs text-muted-foreground"
                                        >—</span
                                    >
                                </td>
                                <td
                                    class="px-4 py-3 text-right whitespace-nowrap text-foreground/80 tabular-nums"
                                >
                                    {{ fmt(b.total_genere) }}
                                </td>
                                <td
                                    class="px-4 py-3 text-right whitespace-nowrap text-foreground/80 tabular-nums"
                                >
                                    {{ fmt(b.total_brut_cumule) }}
                                </td>
                                <td
                                    class="px-4 py-3 text-right whitespace-nowrap text-red-600 tabular-nums dark:text-red-400"
                                >
                                    {{
                                        b.total_frais > 0
                                            ? '-' + fmt(b.total_frais)
                                            : '—'
                                    }}
                                </td>
                                <td
                                    class="px-4 py-3 text-right whitespace-nowrap text-foreground/80 tabular-nums"
                                >
                                    {{ fmt(b.total_net_cumule) }}
                                </td>
                                <td
                                    class="px-4 py-3 text-right whitespace-nowrap text-foreground/80 tabular-nums"
                                >
                                    {{ fmt(b.total_verse) }}
                                </td>
                                <td
                                    class="px-4 py-3 text-right font-bold whitespace-nowrap tabular-nums"
                                >
                                    {{ fmt(b.solde_restant) }}
                                </td>
                                <td class="px-4 py-3">
                                    <StatusDot
                                        :status="statusValue(b)"
                                        :label="statusLabel(b)"
                                    />
                                </td>
                                <td
                                    class="sticky right-0 z-10 border-l bg-card px-3 py-3 text-right group-hover:bg-muted/50 group-focus-visible:bg-muted/50"
                                    @click.stop
                                >
                                    <DropdownMenu>
                                        <DropdownMenuTrigger as-child>
                                            <Button
                                                variant="ghost"
                                                size="icon"
                                                class="h-7 w-7"
                                            >
                                                <MoreHorizontal
                                                    class="h-4 w-4"
                                                />
                                            </Button>
                                        </DropdownMenuTrigger>
                                        <DropdownMenuContent align="end">
                                            <DropdownMenuItem as-child>
                                                <Link
                                                    :href="`/backoffice/comptabilite/commissions/proprietaires/${b.beneficiaire_id}`"
                                                    class="flex w-full cursor-pointer items-center"
                                                >
                                                    Détail
                                                </Link>
                                            </DropdownMenuItem>
                                            <DropdownMenuItem
                                                class="cursor-pointer"
                                                @click="openAudit(b)"
                                            >
                                                <History class="mr-2 h-4 w-4" />
                                                Historique
                                            </DropdownMenuItem>
                                            <template
                                                v-if="can_payer && b.can_pay"
                                            >
                                                <DropdownMenuSeparator />
                                                <DropdownMenuItem
                                                    class="cursor-pointer"
                                                    @click="openPaiement(b)"
                                                >
                                                    <HandCoins
                                                        class="mr-2 h-4 w-4"
                                                    />
                                                    Payer
                                                </DropdownMenuItem>
                                            </template>
                                        </DropdownMenuContent>
                                    </DropdownMenu>
                                </td>
                            </ClickableTableRow>
                        </tbody>
                    </table>
                    <div
                        v-else
                        class="flex flex-col items-center gap-3 py-16 text-muted-foreground"
                    >
                        <Building2 class="h-12 w-12 opacity-30" />
                        <p class="text-sm">
                            Aucune commission propriétaire trouvée.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>

    <PaymentDialogCompact
        v-model:visible="showPaiementDialog"
        :title="
            selectedBenef
                ? `Payer — ${selectedBenef.beneficiaire_nom}`
                : 'Payer'
        "
        :solde="selectedBenef?.solde_restant ?? 0"
        :processing="paiementProcessing"
        :errors="paiementErrors"
        @submit="handlePaiementSubmit"
    />

    <AuditDrawer
        v-model:visible="showAudit"
        :title="`Historique — ${auditBenefNom}`"
        auditable-type="App\Models\Proprietaire"
        :auditable-id="auditBenefId"
        module="commissions_proprietaires"
    />

    <Dialog
        v-model:visible="vehiculesDialogVisible"
        modal
        header="Véhicules ayant généré des ventes"
        :style="{ width: '46rem', maxWidth: 'calc(100vw - 2rem)' }"
        :breakpoints="{ '640px': 'calc(100vw - 1rem)' }"
        data-testid="contributing-vehicles-dialog"
    >
        <div class="space-y-4 px-1 py-2">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div>
                    <p class="text-sm font-medium">
                        {{ selectedBenefForVehicules?.beneficiaire_nom ?? '—' }}
                    </p>
                    <p class="text-xs text-muted-foreground">
                        Dans le périmétre actuellement filtré
                    </p>
                </div>
                <span class="text-sm text-muted-foreground">
                    {{
                        vehiculeCountLabel(
                            selectedBenefForVehicules?.vehicules.length ?? 0,
                        )
                    }}
                </span>
            </div>

            <div
                v-if="selectedBenefForVehicules?.vehicules.length"
                class="max-h-[60vh] overflow-y-auto rounded-lg border"
            >
                <div
                    v-for="vehicule in selectedBenefForVehicules.vehicules"
                    :key="vehicule.id"
                    class="grid gap-2 border-b px-4 py-3 last:border-b-0 sm:grid-cols-[minmax(0,1.4fr)_minmax(0,1fr)_auto] sm:items-center"
                    data-testid="contributing-vehicle-row"
                >
                    <div class="min-w-0">
                        <p class="truncate text-sm font-medium">
                            {{ vehicule.nom }}
                        </p>
                        <p
                            class="mt-0.5 text-xs text-muted-foreground"
                            data-testid="contributing-vehicle-registration"
                        >
                            {{
                                vehicule.immatriculation ??
                                'Sans immatriculation'
                            }}
                        </p>
                    </div>
                    <div
                        class="flex min-w-0 items-center gap-1.5 text-sm text-muted-foreground"
                    >
                        <Building2 class="h-3.5 w-3.5 shrink-0" />
                        <span class="truncate">{{ sitesLabel(vehicule) }}</span>
                    </div>
                    <p
                        class="text-left text-sm font-semibold whitespace-nowrap tabular-nums sm:text-right"
                    >
                        {{ fmt(vehicule.commission_generee) }}
                    </p>
                </div>
            </div>
            <div
                v-else
                class="rounded-lg border border-dashed px-4 py-8 text-center text-sm text-muted-foreground"
            >
                Aucun véhicule contributeur dans ce périmétre.
            </div>
        </div>
        <template #footer>
            <Button
                variant="outline"
                size="sm"
                @click="vehiculesDialogVisible = false"
            >
                Fermer
            </Button>
        </template>
    </Dialog>
</template>
