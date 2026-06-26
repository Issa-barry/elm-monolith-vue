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
import { Head, Link, router } from '@inertiajs/vue3';
import {
    Building2,
    Download,
    FileText,
    HandCoins,
    History,
    MoreHorizontal,
    Truck,
    User,
} from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface VehiculeInfo {
    nom: string;
    immatriculation: string | null;
}

interface BeneficiaireRow {
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
    nb_commandes: number;
    statut_global: string;
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
    };
    search: string;
    filtre_statut: string;
    filtre_site_ids: string[];
    selected_periode: string;
    periodes_disponibles: PeriodeOption[];
    periode_courante: string;
    sites: { id: string; nom: string }[];
    can_payer: boolean;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/dashboard' },
    { title: 'Comptabilité', href: '/comptabilite' },
    {
        title: 'Commission propriétaire',
        href: '/comptabilite/commissions/proprietaires',
    },
];

const search = ref(props.search ?? '');

const filterFields = computed((): FilterField[] => [
    {
        key: 'statut',
        label: 'Statut',
        type: 'select' as const,
        options: [
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

function statutDotClass(s: string) {
    return (
        {
            impaye: 'bg-red-500',
            partiel: 'bg-amber-500',
            paye: 'bg-emerald-500',
        }[s] ?? 'bg-zinc-400 dark:bg-zinc-500'
    );
}

function statutLabel(s: string) {
    return { impaye: 'Impayé', partiel: 'Partiel', paye: 'Payé' }[s] ?? s;
}

// Dialog paiement
const showPaiementDialog = ref(false);
const selectedBenef = ref<BeneficiaireRow | null>(null);
const paiementProcessing = ref(false);
const paiementErrors = ref<Record<string, string>>({});

const showAudit = ref(false);
const auditBenefId = ref('');
const auditBenefNom = ref('');

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
        `/comptabilite/commissions/proprietaires/${selectedBenef.value.beneficiaire_id}/paiements`,
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
        '/comptabilite/commissions/proprietaires/export/excel?' +
            buildParams().toString(),
        '_blank',
    );
}

function exportPdf() {
    window.open(
        '/comptabilite/commissions/proprietaires/export/pdf?' +
            buildParams().toString(),
        '_blank',
    );
}

function fmt(val: number | null | undefined) {
    return (
        new Intl.NumberFormat('fr-FR').format(
            Math.round(Math.abs(Number(val ?? 0))),
        ) + ' GNF'
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
        <div class="space-y-6 p-6">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-semibold tracking-tight">
                        Commission propriétaire
                    </h1>
                    <p class="mt-1 text-sm text-muted-foreground">
                        {{ kpis.nb_proprietaires }} propriétaire{{
                            kpis.nb_proprietaires !== 1 ? 's' : ''
                        }}
                    </p>
                </div>
                <div class="flex items-center gap-2">
                    <button
                        type="button"
                        class="inline-flex items-center gap-2 rounded-lg border bg-card px-3 py-2 text-sm hover:bg-muted/50"
                        @click="exportExcel"
                    >
                        <Download class="h-4 w-4" />
                        Excel
                    </button>
                    <button
                        type="button"
                        class="inline-flex items-center gap-2 rounded-lg border bg-card px-3 py-2 text-sm hover:bg-muted/50"
                        @click="exportPdf"
                    >
                        <FileText class="h-4 w-4" />
                        PDF
                    </button>
                </div>
            </div>

            <!-- KPIs -->
            <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-5">
                <div class="rounded-xl border bg-card p-5 shadow-sm">
                    <p class="text-sm text-muted-foreground">Total cumulé</p>
                    <p
                        class="mt-2 text-2xl font-bold text-foreground tabular-nums"
                    >
                        {{ fmt(kpis.total_brut) }}
                    </p>
                    <p class="mt-0.5 text-xs text-muted-foreground">
                        {{ beneficiaires.length }} propriétaire{{
                            beneficiaires.length !== 1 ? 's' : ''
                        }}
                    </p>
                </div>
                <div class="rounded-xl border bg-card p-5 shadow-sm">
                    <p class="text-sm text-muted-foreground">Frais</p>
                    <p
                        class="mt-2 text-2xl font-bold text-red-600 tabular-nums dark:text-red-400"
                    >
                        {{
                            kpis.total_frais > 0
                                ? '-' + fmt(kpis.total_frais)
                                : fmt(0)
                        }}
                    </p>
                </div>
                <div class="rounded-xl border bg-card p-5 shadow-sm">
                    <p class="text-sm text-muted-foreground">Net à payer</p>
                    <p
                        class="mt-2 text-2xl font-bold text-foreground tabular-nums"
                    >
                        {{ fmt(kpis.total_net) }}
                    </p>
                </div>
                <div class="rounded-xl border bg-card p-5 shadow-sm">
                    <p class="text-sm text-muted-foreground">Déjà payé</p>
                    <p
                        class="mt-2 text-2xl font-bold text-foreground tabular-nums"
                    >
                        {{ fmt(kpis.total_verse) }}
                    </p>
                </div>
                <div class="rounded-xl border bg-card p-5 shadow-sm">
                    <p class="text-sm text-muted-foreground">Reste à payer</p>
                    <p
                        class="mt-2 text-2xl font-bold text-foreground tabular-nums"
                    >
                        {{ fmt(kpis.solde_total) }}
                    </p>
                    <p class="mt-0.5 text-xs text-muted-foreground">
                        {{
                            beneficiaires.filter((b) => b.solde_restant > 0)
                                .length
                        }}
                        impayé{{
                            beneficiaires.filter((b) => b.solde_restant > 0)
                                .length !== 1
                                ? 's'
                                : ''
                        }}
                    </p>
                </div>
            </div>

            <!-- Filtres -->
            <DataFilters
                url="/comptabilite/commissions/proprietaires"
                :values="currentFilters"
                :fields="filterFields"
                :sites="sites"
                :result-count="beneficiaires.length"
                search-placeholder="Rechercher un propriétaire, téléphone..."
                v-model:search="search"
            />

            <!-- Tableau -->
            <div class="overflow-hidden rounded-xl border bg-card shadow-sm">
                <div v-if="beneficiaires.length > 0" class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b bg-muted/40">
                                <th
                                    class="px-5 py-3.5 text-left font-medium text-muted-foreground"
                                >
                                    Propriétaire
                                </th>
                                <th
                                    class="px-5 py-3.5 text-left font-medium text-muted-foreground"
                                >
                                    Véhicule(s)
                                </th>
                                <th
                                    class="px-5 py-3.5 text-left font-medium text-muted-foreground"
                                >
                                    Agence
                                </th>
                                <th
                                    class="px-5 py-3.5 text-right font-medium text-muted-foreground"
                                >
                                    Total cumulé
                                </th>
                                <th
                                    class="px-5 py-3.5 text-right font-medium text-muted-foreground"
                                >
                                    Frais
                                </th>
                                <th
                                    class="px-5 py-3.5 text-right font-medium text-muted-foreground"
                                >
                                    Net à payer
                                </th>
                                <th
                                    class="px-5 py-3.5 text-right font-medium text-muted-foreground"
                                >
                                    Déjà payé
                                </th>
                                <th
                                    class="px-5 py-3.5 text-right font-medium text-muted-foreground"
                                >
                                    Reste à payer
                                </th>
                                <th
                                    class="px-5 py-3.5 text-left font-medium text-muted-foreground"
                                >
                                    Statut
                                </th>
                                <th class="w-10 px-4 py-3.5" />
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <ClickableTableRow
                                v-for="b in beneficiaires"
                                :key="b.beneficiaire_id"
                                :href="`/comptabilite/commissions/proprietaires/${b.beneficiaire_id}`"
                                :aria-label="`Voir le détail de ${b.beneficiaire_nom}`"
                                class="even:bg-muted/20"
                            >
                                <td class="px-5 py-3">
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
                                <td class="px-5 py-3">
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
                                                <span>{{ v.nom }}</span>
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
                                <td class="px-5 py-3 text-sm">
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
                                    class="px-5 py-3 text-right text-muted-foreground tabular-nums"
                                >
                                    {{ fmt(b.total_brut_cumule) }}
                                </td>
                                <td
                                    class="px-5 py-3 text-right text-red-600 tabular-nums dark:text-red-400"
                                >
                                    {{
                                        b.total_frais > 0
                                            ? '-' + fmt(b.total_frais)
                                            : '—'
                                    }}
                                </td>
                                <td
                                    class="px-5 py-3 text-right text-muted-foreground tabular-nums"
                                >
                                    {{ fmt(b.total_net_cumule) }}
                                </td>
                                <td
                                    class="px-5 py-3 text-right text-muted-foreground tabular-nums"
                                >
                                    {{ fmt(b.total_verse) }}
                                </td>
                                <td
                                    class="px-5 py-3 text-right font-bold tabular-nums"
                                >
                                    {{ fmt(b.solde_restant) }}
                                </td>
                                <td class="px-5 py-3">
                                    <StatusDot
                                        :label="statutLabel(b.statut_global)"
                                        :dot-class="
                                            statutDotClass(b.statut_global)
                                        "
                                    />
                                </td>
                                <td class="px-4 py-3 text-right" @click.stop>
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
                                                    :href="`/comptabilite/commissions/proprietaires/${b.beneficiaire_id}`"
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
                                                v-if="
                                                    can_payer &&
                                                    b.solde_restant > 0
                                                "
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
                    <Building2 class="h-12 w-12 opacity-30" />
                    <p class="text-sm">
                        Aucune commission propriétaire trouvée.
                    </p>
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
</template>
