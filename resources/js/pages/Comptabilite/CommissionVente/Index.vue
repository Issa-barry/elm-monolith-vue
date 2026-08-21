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
    MoreHorizontal,
    Truck,
    User,
} from 'lucide-vue-next';
import Dialog from 'primevue/dialog';
import { computed, ref } from 'vue';

interface VehiculeInfo {
    nom: string;
    immatriculation: string | null;
    type: string | null;
    capacites: { categorie_nom: string; capacite_max: number }[];
    proprietaire_nom: string | null;
    proprietaire_telephone: string | null;
    proprietaire_code_phone_pays: string | null;
}

interface BeneficiaireRow extends StatutCommissionResolu {
    beneficiaire_id: string;
    beneficiaire_nom: string;
    telephone: string | null;
    agence: string | null;
    vehicules: VehiculeInfo[];
    total_brut_cumule: number;
    total_frais: number;
    total_net_cumule: number;
    total_verse: number;
    solde_restant: number;
    remaining_amount: number;
    nb_commandes: number;
    statut_global: string;
    /** V2 uniquement (CommissionKpiBuckets) — absents en Legacy. */
    total_genere?: number;
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
        nb_livreurs: number;
        total_brut: number;
        total_frais?: number;
        total_net: number;
        total_verse: number;
        solde_total: number;
        /** V2 uniquement (CommissionKpiBuckets) — absents en Legacy. */
        total_genere?: number;
        en_attente_periode?: number;
        payable?: number;
    };
    search: string;
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
        title: 'Commissions des livreurs sur les ventes',
        href: '/backoffice/comptabilite/commissions/vente',
    },
];

const search = ref(props.search ?? '');

const filterFields = computed((): FilterField[] => [
    {
        key: 'statut',
        label: 'Statut',
        type: 'select' as const,
        options: [
            { value: 'creee', label: 'Partage à valider' },
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

const vehiculeDialogVisible = ref(false);
const selectedVehicule = ref<VehiculeInfo | null>(null);

function openVehicule(v: VehiculeInfo) {
    selectedVehicule.value = v;
    vehiculeDialogVisible.value = true;
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
        `/backoffice/comptabilite/commissions/vente/livreurs/${selectedBenef.value.beneficiaire_id}/paiements`,
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
    if (search.value) params.set('search', search.value);
    return params;
}

function exportExcel() {
    window.open(
        '/backoffice/comptabilite/commissions/vente/export/excel?' +
            buildParams().toString(),
        '_blank',
    );
}

function exportPdf() {
    window.open(
        '/backoffice/comptabilite/commissions/vente/export/pdf?' +
            buildParams().toString(),
        '_blank',
    );
}

const totalGenere = computed(
    () => props.kpis.total_genere ?? props.kpis.total_brut,
);

const totalDepenses = computed(
    () =>
        props.kpis.total_frais ??
        props.beneficiaires.reduce((total, beneficiaire) => {
            return total + beneficiaire.total_frais;
        }, 0),
);

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
    <Head title="Commissions des livreurs sur les ventes — Comptabilité" />
    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="space-y-5 p-4 sm:p-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-semibold tracking-tight">
                        Commissions des livreurs sur les ventes
                    </h1>
                    <div
                        class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-muted-foreground"
                    >
                        <span>
                            {{ kpis.nb_livreurs }} livreur{{
                                kpis.nb_livreurs !== 1 ? 's' : ''
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
                        url="/backoffice/comptabilite/commissions/vente"
                        :values="currentFilters"
                        :fields="filterFields"
                        :sites="sites"
                        :result-count="beneficiaires.length"
                    />
                </div>
            </div>

            <!-- Synthèse métier compacte : généré, dépenses, net validé et paiement.
                 Le montant en attente de validation reste détaillé dans le tableau. -->
            <div
                data-testid="commission-summary-cards"
                aria-label="Synthèse des commissions"
                class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4"
            >
                <div class="rounded-xl border bg-card p-4 shadow-sm">
                    <p class="text-sm text-muted-foreground">
                        Commissions générées
                    </p>
                    <p
                        class="mt-1.5 text-2xl font-bold whitespace-nowrap text-foreground tabular-nums"
                    >
                        {{ fmt(totalGenere) }}
                    </p>
                    <p class="mt-0.5 text-xs text-muted-foreground">
                        {{ kpis.nb_livreurs }} livreur{{
                            kpis.nb_livreurs !== 1 ? 's' : ''
                        }}
                    </p>
                </div>
                <div class="rounded-xl border bg-card p-4 shadow-sm">
                    <p class="text-sm text-muted-foreground">Dépenses</p>
                    <p
                        class="mt-1.5 text-2xl font-bold whitespace-nowrap tabular-nums"
                        :class="
                            totalDepenses > 0
                                ? 'text-red-600 dark:text-red-400'
                                : 'text-foreground'
                        "
                    >
                        {{
                            totalDepenses > 0
                                ? '-' + fmt(totalDepenses)
                                : fmt(0)
                        }}
                    </p>
                    <p class="mt-0.5 text-xs text-muted-foreground">
                        Déduites des commissions validées
                    </p>
                </div>
                <div class="rounded-xl border bg-card p-4 shadow-sm">
                    <p class="text-sm text-muted-foreground">Net validé</p>
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
                    <p class="mt-0.5 text-xs text-muted-foreground">
                        Après dépenses et ajustements
                    </p>
                </div>
                <div class="rounded-xl border bg-card p-4 shadow-sm">
                    <p class="text-sm text-muted-foreground">Reste à payer</p>
                    <p
                        class="mt-1.5 text-2xl font-bold whitespace-nowrap text-foreground tabular-nums"
                    >
                        {{ fmt(kpis.solde_total) }}
                    </p>
                    <p class="mt-0.5 text-xs tabular-nums">
                        <span class="text-muted-foreground">Déjà payé : </span>
                        <span
                            :class="
                                kpis.total_verse > 0
                                    ? 'text-emerald-600 dark:text-emerald-400'
                                    : 'text-muted-foreground'
                            "
                        >
                            {{ fmt(kpis.total_verse) }}
                        </span>
                    </p>
                </div>
            </div>

            <!-- Tableau -->
            <div class="overflow-hidden rounded-xl border bg-card shadow-sm">
                <div
                    class="flex items-center justify-between gap-4 border-b px-5 py-3"
                >
                    <h2 class="text-base font-semibold">Détail par livreur</h2>
                    <span class="text-xs text-muted-foreground">
                        {{ beneficiaires.length }} résultat{{
                            beneficiaires.length !== 1 ? 's' : ''
                        }}
                    </span>
                </div>
                <div
                    v-if="beneficiaires.length > 0"
                    data-testid="commission-table-scroll"
                    class="max-w-full overflow-x-auto pb-1"
                >
                    <table class="w-full min-w-[1460px] text-sm">
                        <thead>
                            <tr class="border-b bg-muted/50">
                                <th
                                    scope="col"
                                    class="sticky left-0 z-20 w-[240px] min-w-[240px] border-r bg-muted px-4 py-3 text-left font-semibold text-foreground/70"
                                >
                                    Livreur
                                </th>
                                <th
                                    scope="col"
                                    class="px-4 py-3 text-left font-semibold text-foreground/70"
                                >
                                    Véhicule
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
                                :href="`/backoffice/comptabilite/commissions/vente/livreurs/${b.beneficiaire_id}`"
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
                                <td class="px-4 py-3" @click.stop>
                                    <div
                                        v-if="b.vehicules.length"
                                        class="flex items-start gap-1.5 text-sm text-muted-foreground"
                                    >
                                        <Truck
                                            class="mt-0.5 h-3.5 w-3.5 shrink-0"
                                        />
                                        <div>
                                            <div
                                                v-for="(v, idx) in b.vehicules"
                                                :key="idx"
                                            >
                                                <button
                                                    type="button"
                                                    class="flex items-center gap-1 font-medium text-primary hover:underline focus:outline-none"
                                                    @click="openVehicule(v)"
                                                >
                                                    {{ v.nom }}
                                                    <ExternalLink
                                                        class="h-3 w-3 shrink-0"
                                                    />
                                                </button>
                                                <span
                                                    v-if="v.immatriculation"
                                                    class="block text-xs text-muted-foreground/80"
                                                    >{{
                                                        v.immatriculation
                                                    }}</span
                                                >
                                            </div>
                                        </div>
                                    </div>
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
                                    {{
                                        fmt(
                                            b.total_genere ??
                                                b.total_brut_cumule,
                                        )
                                    }}
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
                                                :aria-label="`Actions pour ${b.beneficiaire_nom}`"
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
                                                    :href="`/backoffice/comptabilite/commissions/vente/livreurs/${b.beneficiaire_id}`"
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
                </div>
                <div
                    v-else
                    class="flex flex-col items-center gap-3 py-16 text-muted-foreground"
                >
                    <HandCoins class="h-12 w-12 opacity-30" />
                    <p class="text-sm">Aucune commission trouvée.</p>
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
        auditable-type="App\Models\Livreur"
        :auditable-id="auditBenefId"
        module="commissions_vente"
    />

    <Dialog
        v-model:visible="vehiculeDialogVisible"
        modal
        header="Détail véhicule"
        :style="{ width: '28rem' }"
    >
        <div class="space-y-3 px-1 py-2">
            <div class="flex justify-between">
                <span class="text-sm text-muted-foreground">Nom</span>
                <span class="text-sm font-medium">{{
                    selectedVehicule?.nom ?? '—'
                }}</span>
            </div>
            <div class="flex justify-between">
                <span class="text-sm text-muted-foreground"
                    >Immatriculation</span
                >
                <span class="text-sm font-medium">{{
                    selectedVehicule?.immatriculation ?? '—'
                }}</span>
            </div>
            <div class="flex justify-between">
                <span class="text-sm text-muted-foreground">Type</span>
                <span class="text-sm font-medium">{{
                    selectedVehicule?.type ?? '—'
                }}</span>
            </div>
            <div class="flex justify-between">
                <span class="text-sm text-muted-foreground">Capacité</span>
                <span class="text-sm font-medium">
                    <template v-if="!selectedVehicule?.capacites.length">
                        —
                    </template>
                    <template v-else>
                        <span
                            v-for="(c, i) in selectedVehicule.capacites"
                            :key="c.categorie_nom"
                        >
                            {{ i > 0 ? ' · ' : '' }}{{ c.categorie_nom }} :
                            {{ c.capacite_max }}
                        </span>
                    </template>
                </span>
            </div>
            <div class="border-t pt-3">
                <p
                    class="mb-2 text-xs font-medium tracking-wider text-muted-foreground uppercase"
                >
                    Propriétaire
                </p>
                <div class="flex justify-between">
                    <span class="text-sm text-muted-foreground">Nom</span>
                    <span class="text-sm font-medium">{{
                        selectedVehicule?.proprietaire_nom ?? '—'
                    }}</span>
                </div>
                <div
                    v-if="selectedVehicule?.proprietaire_telephone"
                    class="mt-2 flex justify-between"
                >
                    <span class="text-sm text-muted-foreground">Téléphone</span>
                    <span class="text-sm font-medium">
                        {{ fmtTel(selectedVehicule.proprietaire_telephone) }}
                    </span>
                </div>
            </div>
        </div>
        <template #footer>
            <Button
                variant="outline"
                size="sm"
                @click="vehiculeDialogVisible = false"
                >Fermer</Button
            >
        </template>
    </Dialog>
</template>
