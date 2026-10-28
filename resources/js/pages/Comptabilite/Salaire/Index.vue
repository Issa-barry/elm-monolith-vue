<script setup lang="ts">
import AuditDrawer from '@/components/AuditDrawer.vue';
import DataFilters, {
    type FilterField,
} from '@/components/filters/DataFilters.vue';
import PaymentDialogCompact from '@/components/PaymentDialogCompact.vue';
import StatusDot from '@/components/StatusDot.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Spinner } from '@/components/ui/spinner';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import {
    CircleCheck,
    Download,
    Eye,
    FileText,
    HandCoins,
    History,
    MoreHorizontal,
    Users,
} from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface LignePaie {
    id: string;
    employe_id: string;
    employe_nom: string;
    poste: string;
    site: string;
    salaire_base: number;
    brut: number;
    total_primes: number;
    total_avances: number;
    total_retenues: number;
    deductions: number;
    net: number;
    deja_paye: number;
    reste_a_payer: number;
    statut: string;
    statut_label: string;
    peut_payer: boolean;
}

const props = defineProps<{
    lignes: LignePaie[];
    kpis: {
        nb_salaries: number;
        total_brut: number;
        total_net: number;
        total_paye: number;
        total_reste: number;
    };
    periode: {
        id: string;
        mois: number;
        annee: number;
        label: string;
        statut: string | null;
        statut_label: string | null;
        payable: boolean;
    } | null;
    can_valider_periode: boolean;
    periodes_disponibles: { mois: number; annee: number; label: string }[];
    filtre_mois: number;
    filtre_annee: number;
    filtre_statut: string;
    filtre_site_ids: string[];
    search: string;
    sites: { id: string; nom: string }[];
    can_payer: boolean;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/backoffice/dashboard' },
    { title: 'Comptabilité' },
    { title: 'Paiement salaire', href: '/backoffice/comptabilite/salaires' },
];

const MOIS_LABELS = [
    '',
    'Janvier',
    'Février',
    'Mars',
    'Avril',
    'Mai',
    'Juin',
    'Juillet',
    'Août',
    'Septembre',
    'Octobre',
    'Novembre',
    'Décembre',
];
const MOIS_OPTIONS = MOIS_LABELS.slice(1).map((label, i) => ({
    value: i + 1,
    label,
}));
const ANNEES = Array.from({ length: 5 }, (_, i) => {
    const y = new Date().getFullYear() - i;
    return { value: y, label: String(y) };
});

const filterFields = computed((): FilterField[] => [
    {
        key: 'mois',
        label: 'Mois',
        type: 'select',
        options: MOIS_OPTIONS.map((m) => ({
            value: String(m.value),
            label: m.label,
        })),
    },
    {
        key: 'annee',
        label: 'Année',
        type: 'select',
        options: ANNEES.map((a) => ({
            value: String(a.value),
            label: a.label,
        })),
    },
    {
        key: 'statut',
        label: 'Statut',
        type: 'select' as const,
        options: [
            { value: 'en_attente', label: 'En attente' },
            { value: 'calcule', label: 'Calculé' },
            { value: 'partiellement_paye', label: 'Part. payé' },
            { value: 'paye', label: 'Payé' },
        ],
    },
]);

const currentFilters = computed(() => ({
    mois: String(props.filtre_mois),
    annee: String(props.filtre_annee),
    statut: props.filtre_statut ?? '',
    site_ids: props.filtre_site_ids ?? [],
}));

// Les exports reprennent les filtres de l'écran ; le serveur applique en plus le périmètre d'agences.
function buildParams() {
    const p = new URLSearchParams();
    p.set('mois', String(props.filtre_mois));
    p.set('annee', String(props.filtre_annee));
    if (props.filtre_statut) p.set('statut', props.filtre_statut);
    (props.filtre_site_ids ?? []).forEach((id) => p.append('site_ids[]', id));
    return p;
}

const showValidation = ref(false);
const validationEnCours = ref(false);
const validationErreur = ref('');
const CROIX_INACTIVE =
    '[&>button:last-child]:pointer-events-none [&>button:last-child]:opacity-40';

function ouvrirValidation() {
    validationErreur.value = '';
    showValidation.value = true;
}

function validerPeriode() {
    if (!props.periode) return;
    validationEnCours.value = true;
    validationErreur.value = '';
    router.post(
        `/backoffice/comptabilite/salaires/periodes/${props.periode.id}/valider`,
        {},
        {
            preserveScroll: true,
            onSuccess: () => {
                showValidation.value = false;
            },
            onError: (e) => {
                validationErreur.value =
                    (e as Record<string, string>).periode ??
                    'La période n’a pas pu être validée.';
            },
            onFinish: () => {
                validationEnCours.value = false;
            },
        },
    );
}

function exportExcel() {
    window.open(
        '/backoffice/comptabilite/salaires/export/excel?' + buildParams(),
        '_blank',
    );
}
function exportPdf() {
    window.open(
        '/backoffice/comptabilite/salaires/export/pdf?' + buildParams(),
        '_blank',
    );
}

const showPaiementDialog = ref(false);
const selectedLigne = ref<LignePaie | null>(null);
const paiementProcessing = ref(false);
const paiementErrors = ref<Record<string, string>>({});

const showAudit = ref(false);
const auditLigneId = ref('');
const auditLigneNom = ref('');

function openAudit(l: LignePaie) {
    auditLigneId.value = l.id;
    auditLigneNom.value = l.employe_nom;
    showAudit.value = true;
}

function openPaiement(ligne: LignePaie) {
    selectedLigne.value = ligne;
    showPaiementDialog.value = true;
}

function handlePaiementSubmit(payload: {
    montant: number;
    mode_paiement: string;
}) {
    if (!selectedLigne.value) return;
    paiementProcessing.value = true;
    paiementErrors.value = {};
    router.post(
        `/backoffice/comptabilite/salaires/${selectedLigne.value.id}/payer`,
        { ...payload, date_paiement: new Date().toISOString().slice(0, 10) },
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

function statutDotClass(s: string) {
    return (
        (
            {
                paye: 'bg-emerald-500',
                partiellement_paye: 'bg-amber-500',
                calcule: 'bg-blue-500',
                en_attente: 'bg-zinc-400 dark:bg-zinc-500',
            } as Record<string, string>
        )[s] ?? 'bg-zinc-400 dark:bg-zinc-500'
    );
}

function fmt(val: number | null | undefined) {
    return (
        new Intl.NumberFormat('fr-FR').format(
            Math.round(Math.abs(Number(val ?? 0))),
        ) + ' GNF'
    );
}

const kpiDeductions = computed(() =>
    props.lignes.reduce((s, l) => s + (l.deductions ?? 0), 0),
);

const periodeCourante = computed(
    () => `${MOIS_LABELS[props.filtre_mois]} ${props.filtre_annee}`,
);
</script>

<template>
    <Head title="Paiement salaire — Comptabilité" />
    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="space-y-6 p-6">
            <!-- En-tête -->
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-semibold tracking-tight">
                        Paiement salaire
                    </h1>
                    <p class="mt-1 text-sm text-muted-foreground">
                        {{ periodeCourante }} · {{ kpis.nb_salaries }} salarié{{
                            kpis.nb_salaries !== 1 ? 's' : ''
                        }}
                    </p>
                    <div
                        v-if="periode?.statut"
                        class="mt-2 flex flex-wrap items-center gap-2 text-sm"
                        data-testid="salaire-periode-statut"
                    >
                        <StatusDot
                            :status="periode.statut"
                            :label="periode.statut_label ?? periode.statut"
                        />
                        <span
                            v-if="!periode.payable"
                            class="text-xs text-muted-foreground"
                        >
                            Les salaires seront payables une fois la période
                            validée.
                        </span>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <Button
                        v-if="can_valider_periode"
                        data-testid="salaire-valider-periode"
                        @click="ouvrirValidation"
                    >
                        <CircleCheck class="mr-1.5 h-4 w-4" />
                        Valider la période
                    </Button>
                    <button
                        type="button"
                        class="inline-flex items-center gap-2 rounded-lg border bg-card px-3 py-2 text-sm hover:bg-muted/50 disabled:opacity-40"
                        :disabled="!periode"
                        @click="exportExcel"
                    >
                        <Download class="h-4 w-4" />
                        Excel
                    </button>
                    <button
                        type="button"
                        class="inline-flex items-center gap-2 rounded-lg border bg-card px-3 py-2 text-sm hover:bg-muted/50 disabled:opacity-40"
                        :disabled="!periode"
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
                    <p class="text-sm text-muted-foreground">Salaire brut</p>
                    <p
                        class="mt-2 text-2xl font-bold text-foreground tabular-nums"
                    >
                        {{ fmt(kpis.total_brut) }}
                    </p>
                    <p class="mt-0.5 text-xs text-muted-foreground">
                        {{ lignes.length }} salarié{{
                            lignes.length !== 1 ? 's' : ''
                        }}
                    </p>
                </div>
                <div class="rounded-xl border bg-card p-5 shadow-sm">
                    <p class="text-sm text-muted-foreground">Déductions</p>
                    <p
                        class="mt-2 text-2xl font-bold text-red-600 tabular-nums dark:text-red-400"
                    >
                        {{
                            kpiDeductions > 0
                                ? '-' + fmt(kpiDeductions)
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
                        {{ fmt(kpis.total_paye) }}
                    </p>
                </div>
                <div class="rounded-xl border bg-card p-5 shadow-sm">
                    <p class="text-sm text-muted-foreground">Reste à payer</p>
                    <p
                        class="mt-2 text-2xl font-bold text-foreground tabular-nums"
                    >
                        {{ fmt(kpis.total_reste) }}
                    </p>
                    <p class="mt-0.5 text-xs text-muted-foreground">
                        {{ lignes.filter((l) => l.reste_a_payer > 0).length }}
                        impayé{{
                            lignes.filter((l) => l.reste_a_payer > 0).length !==
                            1
                                ? 's'
                                : ''
                        }}
                    </p>
                </div>
            </div>

            <!-- Filtres -->
            <DataFilters
                url="/backoffice/comptabilite/salaires"
                :values="currentFilters"
                :fields="filterFields"
                :sites="sites"
                :result-count="lignes.length"
            />

            <!-- Tableau -->
            <div class="overflow-hidden rounded-xl border bg-card shadow-sm">
                <!-- Aucun résultat -->
                <div
                    v-if="lignes.length === 0"
                    class="flex flex-col items-center gap-3 py-16 text-muted-foreground"
                >
                    <Users class="h-12 w-12 opacity-30" />
                    <p class="text-sm">Aucune fiche de paie pour ce filtre.</p>
                </div>

                <div v-else-if="lignes.length > 0" class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b bg-muted/40">
                                <th
                                    class="px-5 py-3.5 text-left font-medium text-muted-foreground"
                                >
                                    Salarié
                                </th>
                                <th
                                    class="px-5 py-3.5 text-left font-medium text-muted-foreground"
                                >
                                    Agence
                                </th>
                                <th
                                    class="px-5 py-3.5 text-right font-medium text-muted-foreground"
                                >
                                    Salaire brut
                                </th>
                                <th
                                    class="px-5 py-3.5 text-right font-medium text-muted-foreground"
                                >
                                    Primes
                                </th>
                                <th
                                    class="px-5 py-3.5 text-right font-medium text-muted-foreground"
                                >
                                    Déductions
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
                            <tr
                                v-for="l in lignes"
                                :key="l.id"
                                class="transition-colors even:bg-muted/20 hover:bg-muted/10"
                            >
                                <td class="px-5 py-3">
                                    <p class="font-semibold">
                                        {{ l.employe_nom }}
                                    </p>
                                    <p
                                        class="mt-0.5 text-xs text-muted-foreground"
                                    >
                                        {{ l.poste }}
                                    </p>
                                </td>
                                <td
                                    class="px-5 py-3 text-sm text-muted-foreground"
                                >
                                    {{ l.site }}
                                </td>
                                <td
                                    class="px-5 py-3 text-right text-muted-foreground tabular-nums"
                                >
                                    {{ fmt(l.brut) }}
                                </td>
                                <td
                                    class="px-5 py-3 text-right text-muted-foreground tabular-nums"
                                >
                                    {{
                                        l.total_primes > 0
                                            ? '+' + fmt(l.total_primes)
                                            : '—'
                                    }}
                                </td>
                                <td
                                    class="px-5 py-3 text-right font-semibold text-red-600 tabular-nums dark:text-red-400"
                                >
                                    {{
                                        l.deductions > 0
                                            ? '-' + fmt(l.deductions)
                                            : '—'
                                    }}
                                </td>
                                <td
                                    class="px-5 py-3 text-right text-muted-foreground tabular-nums"
                                >
                                    {{ fmt(l.net) }}
                                </td>
                                <td
                                    class="px-5 py-3 text-right text-muted-foreground tabular-nums"
                                >
                                    {{ fmt(l.deja_paye) }}
                                </td>
                                <td
                                    class="px-5 py-3 text-right font-bold tabular-nums"
                                >
                                    {{ fmt(l.reste_a_payer) }}
                                </td>
                                <td class="px-5 py-3">
                                    <StatusDot
                                        :label="l.statut_label"
                                        :dot-class="statutDotClass(l.statut)"
                                    />
                                </td>
                                <td class="px-4 py-3 text-right">
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
                                        <DropdownMenuContent
                                            align="end"
                                            class="w-48"
                                        >
                                            <DropdownMenuItem as-child>
                                                <Link
                                                    :href="`/backoffice/comptabilite/salaires/employes/${l.employe_id}`"
                                                    class="flex w-full cursor-pointer items-center"
                                                >
                                                    <Eye class="mr-2 h-4 w-4" />
                                                    Voir détail
                                                </Link>
                                            </DropdownMenuItem>
                                            <DropdownMenuSeparator />
                                            <template
                                                v-if="
                                                    can_payer &&
                                                    l.peut_payer &&
                                                    l.reste_a_payer > 0
                                                "
                                            >
                                                <DropdownMenuItem
                                                    class="cursor-pointer"
                                                    @click="openPaiement(l)"
                                                >
                                                    <HandCoins
                                                        class="mr-2 h-4 w-4"
                                                    />
                                                    Payer
                                                </DropdownMenuItem>
                                                <DropdownMenuSeparator />
                                            </template>
                                            <DropdownMenuItem
                                                class="cursor-pointer"
                                                @click="openAudit(l)"
                                            >
                                                <History class="mr-2 h-4 w-4" />
                                                Historique
                                            </DropdownMenuItem>
                                            <DropdownMenuSeparator />
                                            <DropdownMenuItem as-child>
                                                <a
                                                    :href="`/backoffice/comptabilite/salaires/export/pdf?mois=${filtre_mois}&annee=${filtre_annee}`"
                                                    target="_blank"
                                                    class="flex w-full cursor-pointer items-center"
                                                >
                                                    <FileText
                                                        class="mr-2 h-4 w-4"
                                                    />
                                                    Exporter PDF
                                                </a>
                                            </DropdownMenuItem>
                                        </DropdownMenuContent>
                                    </DropdownMenu>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AppLayout>

    <PaymentDialogCompact
        v-model:visible="showPaiementDialog"
        :title="
            selectedLigne ? `Payer — ${selectedLigne.employe_nom}` : 'Payer'
        "
        :solde="selectedLigne?.reste_a_payer ?? 0"
        :processing="paiementProcessing"
        :errors="paiementErrors"
        @submit="handlePaiementSubmit"
    />

    <Dialog
        :open="showValidation"
        @update:open="
            (ouvert: boolean) => {
                if (!ouvert && !validationEnCours) showValidation = false;
            }
        "
    >
        <DialogContent
            class="sm:max-w-md"
            :class="{ [CROIX_INACTIVE]: validationEnCours }"
        >
            <DialogHeader>
                <DialogTitle
                    >Valider la période {{ periode?.label }} ?</DialogTitle
                >
                <DialogDescription>
                    Les montants affichés deviennent définitifs : les variables
                    de paie ne pourront plus être modifiées et les salaires
                    pourront être payés.
                </DialogDescription>
            </DialogHeader>
            <p
                v-if="validationErreur"
                class="text-sm text-red-600 dark:text-red-400"
            >
                {{ validationErreur }}
            </p>
            <DialogFooter>
                <Button
                    variant="outline"
                    :disabled="validationEnCours"
                    @click="showValidation = false"
                >
                    Annuler
                </Button>
                <Button
                    :disabled="validationEnCours"
                    :aria-busy="validationEnCours"
                    data-testid="salaire-valider-periode-confirmer"
                    @click="validerPeriode"
                >
                    <Spinner v-if="validationEnCours" class="mr-1.5" />
                    Valider
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>

    <AuditDrawer
        v-model:visible="showAudit"
        :title="`Historique — ${auditLigneNom}`"
        auditable-type="App\Models\PaieLigne"
        :auditable-id="auditLigneId"
        module="salaires"
    />
</template>
