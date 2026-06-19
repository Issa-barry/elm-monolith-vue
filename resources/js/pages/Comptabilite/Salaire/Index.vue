<script setup lang="ts">
import AuditDrawer from '@/components/AuditDrawer.vue';
import PaymentDialogCompact from '@/components/PaymentDialogCompact.vue';
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
import { Head, router } from '@inertiajs/vue3';
import {
    Building2,
    Download,
    FileText,
    HandCoins,
    History,
    MoreHorizontal,
    Search,
    Users,
    X,
} from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

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
    } | null;
    periodes_disponibles: { mois: number; annee: number; label: string }[];
    filtre_mois: number;
    filtre_annee: number;
    filtre_statut: string;
    filtre_site: string;
    search: string;
    is_admin: boolean;
    sites: { value: string; label: string }[];
    can_payer: boolean;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/dashboard' },
    { title: 'Comptabilité', href: '/comptabilite' },
    { title: 'Paiement salaire', href: '/comptabilite/salaires' },
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

const selectedMois = ref(props.filtre_mois);
const selectedAnnee = ref(props.filtre_annee);
const filtreStatut = ref(props.filtre_statut ?? '');
const filtreSite = ref(props.filtre_site ?? '');
const searchVal = ref(props.search ?? '');

const activeFilterCount = computed(
    () =>
        [
            !!filtreStatut.value,
            !!filtreSite.value,
            selectedMois.value !== new Date().getMonth() + 1,
            selectedAnnee.value !== new Date().getFullYear(),
        ].filter(Boolean).length,
);

const hasActiveFilters = computed(
    () => !!searchVal.value || activeFilterCount.value > 0,
);

function appliquerFiltres() {
    router.get(
        '/comptabilite/salaires',
        {
            mois: selectedMois.value,
            annee: selectedAnnee.value,
            statut: filtreStatut.value || undefined,
            site_id: filtreSite.value || undefined,
            search: searchVal.value || undefined,
        },
        { preserveState: true, replace: true },
    );
}

function resetFilters() {
    selectedMois.value = new Date().getMonth() + 1;
    selectedAnnee.value = new Date().getFullYear();
    filtreStatut.value = '';
    filtreSite.value = '';
    searchVal.value = '';
    router.get(
        '/comptabilite/salaires',
        {
            mois: new Date().getMonth() + 1,
            annee: new Date().getFullYear(),
        },
        { preserveState: true, replace: true },
    );
}

let searchDebounce: ReturnType<typeof setTimeout> | null = null;
watch(searchVal, () => {
    if (searchDebounce) clearTimeout(searchDebounce);
    searchDebounce = setTimeout(appliquerFiltres, 400);
});

function buildParams() {
    const p = new URLSearchParams();
    p.set('mois', String(selectedMois.value));
    p.set('annee', String(selectedAnnee.value));
    return p;
}

function exportExcel() {
    window.open(
        '/comptabilite/salaires/export/excel?' + buildParams(),
        '_blank',
    );
}
function exportPdf() {
    window.open('/comptabilite/salaires/export/pdf?' + buildParams(), '_blank');
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
        `/comptabilite/salaires/${selectedLigne.value.id}/payer`,
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

function statutClass(s: string) {
    return (
        (
            {
                paye: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/30 dark:text-emerald-400',
                partiellement_paye:
                    'bg-amber-100 text-amber-700 dark:bg-amber-950/30 dark:text-amber-400',
                calcule:
                    'bg-blue-100 text-blue-700 dark:bg-blue-950/30 dark:text-blue-400',
                en_attente: 'bg-muted text-muted-foreground',
            } as Record<string, string>
        )[s] ?? 'bg-muted text-muted-foreground'
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
    () => `${MOIS_LABELS[selectedMois.value]} ${selectedAnnee.value}`,
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
                </div>
                <div class="flex items-center gap-2">
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
            <div class="flex flex-wrap items-center gap-3">
                <div class="relative w-[260px] shrink-0">
                    <Search
                        class="pointer-events-none absolute top-1/2 left-2.5 h-4 w-4 -translate-y-1/2 text-muted-foreground"
                    />
                    <input
                        v-model="searchVal"
                        type="search"
                        placeholder="Rechercher un salarié..."
                        class="h-9 w-full rounded-md border border-input bg-background py-2 pr-7 pl-8 text-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    />
                    <button
                        v-if="searchVal"
                        type="button"
                        class="absolute top-1/2 right-2 -translate-y-1/2 text-muted-foreground hover:text-foreground"
                        @click="searchVal = ''"
                    >
                        <X class="h-3.5 w-3.5" />
                    </button>
                </div>

                <select
                    v-model="selectedMois"
                    class="h-9 rounded-md border border-input bg-background px-2 text-sm"
                >
                    <option
                        v-for="m in MOIS_OPTIONS"
                        :key="m.value"
                        :value="m.value"
                    >
                        {{ m.label }}
                    </option>
                </select>

                <select
                    v-model="selectedAnnee"
                    class="h-9 rounded-md border border-input bg-background px-2 text-sm"
                >
                    <option
                        v-for="a in ANNEES"
                        :key="a.value"
                        :value="a.value"
                    >
                        {{ a.label }}
                    </option>
                </select>

                <select
                    v-if="is_admin && sites.length > 1"
                    v-model="filtreSite"
                    class="h-9 rounded-md border border-input bg-background px-2 text-sm"
                >
                    <option value="">Toutes les agences</option>
                    <option
                        v-for="s in sites"
                        :key="s.value"
                        :value="s.value"
                    >
                        {{ s.label }}
                    </option>
                </select>
                <span
                    v-else-if="!is_admin && sites.length >= 1"
                    class="inline-flex h-9 items-center gap-1.5 rounded-md border bg-muted/40 px-3 text-sm text-muted-foreground"
                >
                    <Building2 class="h-3.5 w-3.5" />
                    {{ sites[0]?.label }}
                </span>

                <select
                    v-model="filtreStatut"
                    class="h-9 rounded-md border border-input bg-background px-2 text-sm"
                >
                    <option value="">Tous les statuts</option>
                    <option value="en_attente">En attente</option>
                    <option value="calcule">Calculé</option>
                    <option value="partiellement_paye">Part. payé</option>
                    <option value="paye">Payé</option>
                </select>

                <button
                    type="button"
                    class="h-9 rounded-md bg-primary px-3 text-sm text-primary-foreground hover:bg-primary/90"
                    @click="appliquerFiltres"
                >
                    Appliquer
                </button>

                <span
                    class="shrink-0 text-xs whitespace-nowrap text-muted-foreground"
                >
                    {{ lignes.length }} résultat{{
                        lignes.length !== 1 ? 's' : ''
                    }}
                </span>
                <button
                    v-if="hasActiveFilters"
                    type="button"
                    class="shrink-0 text-xs text-muted-foreground underline-offset-2 hover:text-foreground hover:underline"
                    @click="resetFilters"
                >
                    Réinitialiser
                </button>
            </div>

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
                            class="transition-colors hover:bg-muted/10 even:bg-muted/20"
                        >
                            <td class="px-5 py-3">
                                <p class="font-semibold">{{ l.employe_nom }}</p>
                                <p class="mt-0.5 text-xs text-muted-foreground">
                                    {{ l.poste }}
                                </p>
                            </td>
                            <td class="px-5 py-3 text-sm text-muted-foreground">
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
                                <span
                                    class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium whitespace-nowrap"
                                    :class="statutClass(l.statut)"
                                >
                                    {{ l.statut_label }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-right">
                                <DropdownMenu>
                                    <DropdownMenuTrigger as-child>
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            class="h-7 w-7"
                                        >
                                            <MoreHorizontal class="h-4 w-4" />
                                        </Button>
                                    </DropdownMenuTrigger>
                                    <DropdownMenuContent
                                        align="end"
                                        class="w-48"
                                    >
                                        <template
                                            v-if="
                                                can_payer && l.reste_a_payer > 0
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
                                                :href="`/comptabilite/salaires/export/pdf?mois=${selectedMois}&annee=${selectedAnnee}`"
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

    <AuditDrawer
        v-model:visible="showAudit"
        :title="`Historique — ${auditLigneNom}`"
        auditable-type="App\Models\PaieLigne"
        :auditable-id="auditLigneId"
        module="salaires"
    />
</template>
