<script setup lang="ts">
import AuditDrawer from '@/components/AuditDrawer.vue';
import DataFilters, {
    type FilterField,
} from '@/components/filters/DataFilters.vue';
import StatusDot from '@/components/StatusDot.vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/vue3';
import {
    ChevronDown,
    Download,
    FileSpreadsheet,
    FileText,
    HandCoins,
    History,
    MoreHorizontal,
    Warehouse,
} from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface BeneficiaireRow {
    beneficiaire_id: string;
    beneficiaire_nom: string;
    site_code: string | null;
    site_type: string | null;
    site_type_label: string | null;
    categories: string[];
    total_brut_cumule: number;
    total_frais: number;
    total_net_cumule: number;
    total_verse: number;
    solde_restant: number;
    nb_commandes: number;
    statut_global: string;
    statut_label: string;
    total_genere: number;
    en_attente_periode: number;
    payable: number;
}

interface PeriodeOption {
    code: string;
    label: string;
}

const props = defineProps<{
    beneficiaires: BeneficiaireRow[];
    kpis: {
        commissions_generees: number;
        depenses: number;
        net_valide: number;
        reste_a_payer: number;
    };
    search: string;
    filtre_statut: string;
    filtre_site_ids: string[];
    filtre_categorie_id: string;
    filtre_site_type: string;
    selected_periode: string;
    periodes_disponibles: PeriodeOption[];
    sites: { id: string; nom: string }[];
    categories: { id: string; nom: string }[];
    site_types: { value: string; label: string }[];
    can_payer: boolean;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/backoffice/dashboard' },
    { title: 'Comptabilité', href: '/backoffice/comptabilite' },
    {
        title: 'Commissions des sites',
        href: '/backoffice/comptabilite/commissions/sites',
    },
];

const search = ref(props.search ?? '');

const filterFields = computed((): FilterField[] => [
    {
        key: 'statut',
        label: 'Statut',
        type: 'select' as const,
        options: [
            { value: 'creee', label: 'Créée' },
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
    {
        key: 'categorie_id',
        label: 'Catégorie',
        type: 'select' as const,
        options: props.categories.map((c) => ({ value: c.id, label: c.nom })),
    },
    {
        key: 'site_type',
        label: 'Type de site',
        type: 'select' as const,
        options: props.site_types,
    },
]);

const currentFilters = computed(() => ({
    site_ids: props.filtre_site_ids ?? [],
    statut: props.filtre_statut ?? '',
    periode: props.selected_periode ?? '',
    categorie_id: props.filtre_categorie_id ?? '',
    site_type: props.filtre_site_type ?? '',
}));

const showAudit = ref(false);
const auditBenefId = ref('');
const auditBenefNom = ref('');

function openAudit(b: BeneficiaireRow) {
    auditBenefId.value = b.beneficiaire_id;
    auditBenefNom.value = b.beneficiaire_nom;
    showAudit.value = true;
}

function buildParams(): URLSearchParams {
    const params = new URLSearchParams();
    if (props.selected_periode) params.set('periode', props.selected_periode);
    for (const id of props.filtre_site_ids ?? []) {
        params.append('site_ids[]', id);
    }
    if (props.filtre_statut) params.set('statut', props.filtre_statut);
    if (props.filtre_categorie_id)
        params.set('categorie_id', props.filtre_categorie_id);
    if (props.filtre_site_type) params.set('site_type', props.filtre_site_type);
    if (search.value) params.set('search', search.value);
    return params;
}

function exportExcel() {
    window.open(
        '/backoffice/comptabilite/commissions/sites/export/excel?' +
            buildParams().toString(),
        '_blank',
    );
}

function exportPdf() {
    window.open(
        '/backoffice/comptabilite/commissions/sites/export/pdf?' +
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

function fmt(val: number | null | undefined) {
    return (
        new Intl.NumberFormat('fr-FR')
            .format(Math.round(Math.abs(Number(val ?? 0))))
            .replace(/ /g, ' ') + ' GNF'
    );
}
</script>

<template>
    <Head title="Commissions des sites — Comptabilité" />
    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="space-y-5 p-4 sm:p-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-semibold tracking-tight">
                        Commissions des sites
                    </h1>
                    <div
                        class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-muted-foreground"
                    >
                        <span>
                            {{ beneficiaires.length }} site{{
                                beneficiaires.length !== 1 ? 's' : ''
                            }}
                        </span>
                        <span aria-hidden="true">·</span>
                        <span>{{ periodContextLabel }}</span>
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
                        url="/backoffice/comptabilite/commissions/sites"
                        :values="currentFilters"
                        :fields="filterFields"
                        :sites="sites"
                        :result-count="beneficiaires.length"
                    />
                </div>
            </div>

            <!-- 4 cartes de synthèse maximum, cf. Commission vente. -->
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
                        {{ fmt(kpis.commissions_generees) }}
                    </p>
                    <p class="mt-0.5 text-xs text-muted-foreground">
                        {{ beneficiaires.length }} site{{
                            beneficiaires.length !== 1 ? 's' : ''
                        }}
                    </p>
                </div>
                <div class="rounded-xl border bg-card p-4 shadow-sm">
                    <p class="text-sm text-muted-foreground">Dépenses</p>
                    <p
                        class="mt-1.5 text-2xl font-bold whitespace-nowrap tabular-nums"
                        :class="
                            kpis.depenses > 0
                                ? 'text-red-600 dark:text-red-400'
                                : 'text-foreground'
                        "
                    >
                        {{
                            kpis.depenses > 0
                                ? '-' + fmt(kpis.depenses)
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
                            kpis.net_valide > 0
                                ? 'text-amber-600 dark:text-amber-400'
                                : 'text-foreground'
                        "
                    >
                        {{ fmt(kpis.net_valide) }}
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
                        {{ fmt(kpis.reste_a_payer) }}
                    </p>
                </div>
            </div>

            <!-- Tableau -->
            <div class="overflow-hidden rounded-xl border bg-card shadow-sm">
                <div
                    class="flex items-center justify-between gap-4 border-b px-5 py-3"
                >
                    <h2 class="text-base font-semibold">Détail par site</h2>
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
                    <table class="w-full min-w-[1320px] text-sm">
                        <thead>
                            <tr class="border-b bg-muted/50">
                                <th
                                    scope="col"
                                    class="sticky left-0 z-20 w-[220px] min-w-[220px] border-r bg-muted px-4 py-3 text-left font-semibold text-foreground/70"
                                >
                                    Site
                                </th>
                                <th
                                    scope="col"
                                    class="px-4 py-3 text-left font-semibold text-foreground/70"
                                >
                                    Code
                                </th>
                                <th
                                    scope="col"
                                    class="px-4 py-3 text-left font-semibold text-foreground/70"
                                >
                                    Catégories
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
                                    class="px-4 py-3 text-right font-semibold whitespace-nowrap text-foreground/70"
                                >
                                    Brut validé
                                </th>
                                <th
                                    scope="col"
                                    class="px-4 py-3 text-right font-semibold text-foreground/70"
                                >
                                    Dépenses
                                </th>
                                <th
                                    scope="col"
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
                            <tr
                                v-for="b in beneficiaires"
                                :key="b.beneficiaire_id"
                                class="even:bg-muted/20"
                            >
                                <td
                                    class="sticky left-0 z-10 w-[220px] min-w-[220px] border-r bg-card px-4 py-3"
                                >
                                    <div class="flex items-center gap-1.5">
                                        <Warehouse
                                            class="h-3.5 w-3.5 shrink-0 text-muted-foreground"
                                        />
                                        <div>
                                            <p class="font-semibold">
                                                {{ b.beneficiaire_nom }}
                                            </p>
                                            <p
                                                v-if="b.site_type_label"
                                                class="text-xs text-muted-foreground"
                                            >
                                                {{ b.site_type_label }}
                                            </p>
                                        </div>
                                    </div>
                                </td>
                                <td
                                    class="px-4 py-3 font-mono text-xs text-muted-foreground"
                                >
                                    {{ b.site_code ?? '—' }}
                                </td>
                                <td
                                    class="max-w-[220px] px-4 py-3 text-xs text-muted-foreground"
                                >
                                    {{ b.categories.join(', ') || '—' }}
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
                                        :status="b.statut_global"
                                        :label="b.statut_label"
                                    />
                                </td>
                                <td
                                    class="sticky right-0 z-10 border-l bg-card px-3 py-3 text-right"
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
                                            <DropdownMenuItem
                                                class="cursor-pointer"
                                                @click="openAudit(b)"
                                            >
                                                <History class="mr-2 h-4 w-4" />
                                                Historique
                                            </DropdownMenuItem>
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
                    <p class="text-sm">Aucune commission trouvée.</p>
                </div>
            </div>
        </div>
    </AppLayout>

    <AuditDrawer
        v-model:visible="showAudit"
        :title="`Historique — ${auditBenefNom}`"
        auditable-type="App\Models\Site"
        :auditable-id="auditBenefId"
        module="commissions_sites"
    />
</template>
