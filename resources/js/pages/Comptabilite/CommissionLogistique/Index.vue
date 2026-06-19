<script setup lang="ts">
import AuditDrawer from '@/components/AuditDrawer.vue';
import FilterDrawer from '@/components/FilterDrawer.vue';
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
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import {
    Download,
    FileText,
    HandCoins,
    History,
    MoreHorizontal,
    Search,
    Truck,
    User,
    X,
} from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

interface LivreurRow {
    livreur_id: string;
    nom: string;
    telephone: string | null;
    vehicules: string | null;
    agence: string | null;
    frais_depenses: number;
    impaye: number;
    paye: number;
}

interface Kpis {
    nb_livreurs: number;
    total_impaye: number;
    total_paye: number;
}

interface PeriodeOption {
    code: string;
    label: string;
}

const props = defineProps<{
    livreurs: LivreurRow[];
    kpis: Kpis;
    search: string;
    filtre_statut: string;
    filtre_site: string;
    selected_periode: string;
    periodes_disponibles: PeriodeOption[];
    sites: { value: string; label: string }[];
    can_payer: boolean;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/dashboard' },
    { title: 'Comptabilité', href: '/comptabilite' },
    {
        title: 'Commissions logistique',
        href: '/comptabilite/commissions/logistique',
    },
];

const filterDrawerOpen = ref(false);
const searchVal = ref(props.search ?? '');
const statutFiltre = ref(props.filtre_statut ?? '');
const periodeFiltre = ref(props.selected_periode ?? '');
const siteFiltre = ref(props.filtre_site ?? '');

const activeFilterCount = computed(
    () =>
        [
            !!statutFiltre.value,
            !!periodeFiltre.value,
            !!siteFiltre.value,
        ].filter(Boolean).length,
);

const hasActiveFilters = computed(
    () => !!searchVal.value || activeFilterCount.value > 0,
);

function appliquerFiltres() {
    router.get(
        '/comptabilite/commissions/logistique',
        {
            search: searchVal.value || undefined,
            statut: statutFiltre.value || undefined,
            periode: periodeFiltre.value || undefined,
            site: siteFiltre.value || undefined,
        },
        { preserveState: true, replace: true },
    );
}

function resetFilters() {
    searchVal.value = '';
    statutFiltre.value = '';
    periodeFiltre.value = '';
    siteFiltre.value = '';
    router.get(
        '/comptabilite/commissions/logistique',
        {},
        { preserveState: true, replace: true },
    );
}

let searchDebounce: ReturnType<typeof setTimeout> | null = null;
watch(searchVal, () => {
    if (searchDebounce) clearTimeout(searchDebounce);
    searchDebounce = setTimeout(appliquerFiltres, 400);
});

const kpiTotalBrut = computed(() =>
    props.livreurs.reduce(
        (s, l) => s + l.impaye + l.paye + l.frais_depenses,
        0,
    ),
);
const kpiTotalFrais = computed(() =>
    props.livreurs.reduce((s, l) => s + l.frais_depenses, 0),
);
const kpiTotalNet = computed(() =>
    props.livreurs.reduce((s, l) => s + l.impaye + l.paye, 0),
);
const kpiTotalPaye = computed(() =>
    props.livreurs.reduce((s, l) => s + l.paye, 0),
);
const kpiTotalReste = computed(() =>
    props.livreurs.reduce((s, l) => s + l.impaye, 0),
);

const showPaiementDialog = ref(false);
const selectedLivreur = ref<LivreurRow | null>(null);
const paiementProcessing = ref(false);
const paiementErrors = ref<Record<string, string>>({});

const showAudit = ref(false);
const auditLivreurId = ref('');
const auditLivreurNom = ref('');

function openAudit(l: LivreurRow) {
    auditLivreurId.value = l.livreur_id;
    auditLivreurNom.value = l.nom;
    showAudit.value = true;
}

function openPaiement(livreur: LivreurRow) {
    selectedLivreur.value = livreur;
    showPaiementDialog.value = true;
}

function handlePaiementSubmit(payload: {
    montant: number;
    mode_paiement: string;
}) {
    if (!selectedLivreur.value) return;
    paiementProcessing.value = true;
    paiementErrors.value = {};
    router.post(
        `/comptabilite/commissions/logistique/livreurs/${selectedLivreur.value.livreur_id}/paiements`,
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

function livreurStatuts(l: LivreurRow) {
    const badges = [];
    if (l.impaye > 0) badges.push({ label: 'Impayé', dotClass: 'bg-red-500' });
    if (l.paye > 0) badges.push({ label: 'Payé', dotClass: 'bg-emerald-500' });
    return badges;
}

function buildParams(): URLSearchParams {
    const params = new URLSearchParams();
    if (periodeFiltre.value) params.set('periode', periodeFiltre.value);
    if (siteFiltre.value) params.set('site', siteFiltre.value);
    if (statutFiltre.value) params.set('statut', statutFiltre.value);
    if (searchVal.value) params.set('search', searchVal.value);
    return params;
}

function exportExcel() {
    window.open(
        '/comptabilite/commissions/logistique/export/excel?' +
            buildParams().toString(),
        '_blank',
    );
}

function exportPdf() {
    window.open(
        '/comptabilite/commissions/logistique/export/pdf?' +
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
    <Head title="Commissions logistique — Comptabilité" />
    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="space-y-6 p-6">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-semibold tracking-tight">
                        Commission livreur logistique
                    </h1>
                    <p class="mt-1 text-sm text-muted-foreground">
                        {{ kpis.nb_livreurs }} livreur{{
                            kpis.nb_livreurs !== 1 ? 's' : ''
                        }}
                        avec commissions
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
                        {{ fmt(kpiTotalBrut) }}
                    </p>
                    <p class="mt-0.5 text-xs text-muted-foreground">
                        {{ livreurs.length }} livreur{{
                            livreurs.length !== 1 ? 's' : ''
                        }}
                    </p>
                </div>
                <div class="rounded-xl border bg-card p-5 shadow-sm">
                    <p class="text-sm text-muted-foreground">Frais</p>
                    <p
                        class="mt-2 text-2xl font-bold text-red-600 tabular-nums dark:text-red-400"
                    >
                        {{
                            kpiTotalFrais > 0
                                ? '-' + fmt(kpiTotalFrais)
                                : fmt(0)
                        }}
                    </p>
                </div>
                <div class="rounded-xl border bg-card p-5 shadow-sm">
                    <p class="text-sm text-muted-foreground">Net à payer</p>
                    <p
                        class="mt-2 text-2xl font-bold text-foreground tabular-nums"
                    >
                        {{ fmt(kpiTotalNet) }}
                    </p>
                </div>
                <div class="rounded-xl border bg-card p-5 shadow-sm">
                    <p class="text-sm text-muted-foreground">Déjà payé</p>
                    <p
                        class="mt-2 text-2xl font-bold text-foreground tabular-nums"
                    >
                        {{ fmt(kpiTotalPaye) }}
                    </p>
                </div>
                <div class="rounded-xl border bg-card p-5 shadow-sm">
                    <p class="text-sm text-muted-foreground">Reste à payer</p>
                    <p
                        class="mt-2 text-2xl font-bold text-foreground tabular-nums"
                    >
                        {{ fmt(kpiTotalReste) }}
                    </p>
                    <p class="mt-0.5 text-xs text-muted-foreground">
                        {{ livreurs.filter((l) => l.impaye > 0).length }}
                        impayé{{
                            livreurs.filter((l) => l.impaye > 0).length !== 1
                                ? 's'
                                : ''
                        }}
                    </p>
                </div>
            </div>

            <!-- Filtres -->
            <div class="flex flex-wrap items-center gap-3">
                <div class="relative w-[280px] shrink-0">
                    <Search
                        class="pointer-events-none absolute top-1/2 left-2.5 h-4 w-4 -translate-y-1/2 text-muted-foreground"
                    />
                    <input
                        v-model="searchVal"
                        type="search"
                        placeholder="Rechercher un livreur, téléphone, véhicule..."
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

                <FilterDrawer
                    v-model:open="filterDrawerOpen"
                    title="Filtres"
                    :active-count="activeFilterCount"
                    @apply="appliquerFiltres"
                    @reset="resetFilters"
                >
                    <div class="space-y-1.5">
                        <Label>Statut</Label>
                        <select
                            v-model="statutFiltre"
                            class="h-9 w-full rounded-md border border-input bg-background px-2 text-sm"
                        >
                            <option value="">Tous les statuts</option>
                            <option value="impaye">Impayé</option>
                            <option value="paye">Payé</option>
                        </select>
                    </div>
                    <div class="space-y-1.5">
                        <Label>Période</Label>
                        <select
                            v-model="periodeFiltre"
                            class="h-9 w-full rounded-md border border-input bg-background px-2 text-sm"
                        >
                            <option value="">Toutes les périodes</option>
                            <option
                                v-for="p in periodes_disponibles"
                                :key="p.code"
                                :value="p.code"
                            >
                                {{ p.label }}
                            </option>
                        </select>
                    </div>
                    <div v-if="sites && sites.length > 0" class="space-y-1.5">
                        <Label>Agence</Label>
                        <select
                            v-model="siteFiltre"
                            class="h-9 w-full rounded-md border border-input bg-background px-2 text-sm"
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
                    </div>
                </FilterDrawer>

                <span
                    class="shrink-0 text-xs whitespace-nowrap text-muted-foreground"
                >
                    {{ livreurs.length }} résultat{{
                        livreurs.length !== 1 ? 's' : ''
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
                <div v-if="livreurs.length > 0" class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b bg-muted/40">
                            <th
                                class="px-5 py-3.5 text-left font-medium text-muted-foreground"
                            >
                                Livreur
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
                        <tr
                            v-for="l in livreurs"
                            :key="l.livreur_id"
                            class="cursor-pointer transition-colors hover:bg-muted/10 even:bg-muted/20"
                            @click="
                                router.visit(
                                    '/comptabilite/commissions/logistique/livreurs/' +
                                        l.livreur_id,
                                )
                            "
                        >
                            <td class="px-5 py-3">
                                <div class="flex items-center gap-2.5">
                                    <User
                                        class="h-4 w-4 shrink-0 text-muted-foreground"
                                    />
                                    <div>
                                        <p class="font-semibold">{{ l.nom }}</p>
                                        <p
                                            v-if="l.telephone"
                                            class="mt-0.5 text-xs text-muted-foreground"
                                        >
                                            {{ fmtTel(l.telephone) }}
                                        </p>
                                    </div>
                                </div>
                            </td>
                            <td class="px-5 py-3">
                                <div
                                    v-if="l.vehicules"
                                    class="flex items-center gap-1.5 text-sm text-muted-foreground"
                                >
                                    <Truck class="h-3.5 w-3.5 shrink-0" />
                                    <span>{{ l.vehicules }}</span>
                                </div>
                                <span
                                    v-else
                                    class="text-xs text-muted-foreground"
                                    >—</span
                                >
                            </td>
                            <td class="px-5 py-3 text-sm">
                                <span v-if="l.agence">{{ l.agence }}</span>
                                <span v-else class="text-muted-foreground"
                                    >—</span
                                >
                            </td>
                            <td
                                class="px-5 py-3 text-right text-muted-foreground tabular-nums"
                            >
                                {{ fmt(l.impaye + l.paye + l.frais_depenses) }}
                            </td>
                            <td
                                class="px-5 py-3 text-right text-red-600 tabular-nums dark:text-red-400"
                            >
                                {{
                                    l.frais_depenses > 0
                                        ? '-' + fmt(l.frais_depenses)
                                        : '—'
                                }}
                            </td>
                            <td
                                class="px-5 py-3 text-right text-muted-foreground tabular-nums"
                            >
                                {{ fmt(l.impaye + l.paye) }}
                            </td>
                            <td
                                class="px-5 py-3 text-right text-muted-foreground tabular-nums"
                            >
                                {{ fmt(l.paye) }}
                            </td>
                            <td
                                class="px-5 py-3 text-right font-bold tabular-nums"
                            >
                                {{ fmt(l.impaye) }}
                            </td>
                            <td class="px-5 py-3">
                                <div class="flex flex-col gap-1">
                                    <StatusDot
                                        v-for="s in livreurStatuts(l)"
                                        :key="s.label"
                                        :label="s.label"
                                        :dot-class="s.dotClass"
                                        class="text-xs text-muted-foreground"
                                    />
                                </div>
                            </td>
                            <td class="px-4 py-3 text-right" @click.stop>
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
                                    <DropdownMenuContent align="end">
                                        <DropdownMenuItem as-child>
                                            <Link
                                                :href="`/comptabilite/commissions/logistique/livreurs/${l.livreur_id}`"
                                                class="flex w-full cursor-pointer items-center"
                                            >
                                                Détail
                                            </Link>
                                        </DropdownMenuItem>
                                        <DropdownMenuItem
                                            class="cursor-pointer"
                                            @click="openAudit(l)"
                                        >
                                            <History class="mr-2 h-4 w-4" />
                                            Historique
                                        </DropdownMenuItem>
                                        <template
                                            v-if="can_payer && l.impaye > 0"
                                        >
                                            <DropdownMenuSeparator />
                                            <DropdownMenuItem
                                                class="cursor-pointer"
                                                @click="openPaiement(l)"
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
                        </tr>
                    </tbody>
                </table>
                </div>
                <div
                    v-else
                    class="flex flex-col items-center gap-3 py-16 text-muted-foreground"
                >
                    <HandCoins class="h-12 w-12 opacity-30" />
                    <p class="text-sm">
                        Aucune commission trouvée pour ce filtre.
                    </p>
                </div>
            </div>
        </div>
    </AppLayout>

    <PaymentDialogCompact
        v-model:visible="showPaiementDialog"
        :title="selectedLivreur ? `Payer — ${selectedLivreur.nom}` : 'Payer'"
        :solde="selectedLivreur?.impaye ?? 0"
        :processing="paiementProcessing"
        :errors="paiementErrors"
        @submit="handlePaiementSubmit"
    />

    <AuditDrawer
        v-model:visible="showAudit"
        :title="`Historique — ${auditLivreurNom}`"
        auditable-type="App\Models\Livreur"
        :auditable-id="auditLivreurId"
        module="commissions_logistique"
    />
</template>
