<script setup lang="ts">
import AuditDrawer from '@/components/AuditDrawer.vue';
import ClickableTableRow from '@/components/ClickableTableRow.vue';
import CommissionIndexLayout from '@/components/commission/CommissionIndexLayout.vue';
import type { FilterField } from '@/components/filters/DataFilters.vue';
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
import type { CommissionIndexSummary } from '@/types/commission';
import { Head } from '@inertiajs/vue3';
import { History, MoreHorizontal, Warehouse } from 'lucide-vue-next';
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
    filtre_processus: string;
    processus_options: { value: string; label: string }[];
    selected_periode: string;
    periodes_disponibles: PeriodeOption[];
    sites: { id: string; nom: string }[];
    categories: { id: string; nom: string }[];
    site_types: { value: string; label: string }[];
    can_payer: boolean;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/backoffice/dashboard' },
    { title: 'Comptabilité' },
    {
        title: 'Commissions des sites',
        href: '/backoffice/comptabilite/commissions/sites',
    },
];

const search = ref(props.search ?? '');

const filterFields = computed((): FilterField[] => [
    {
        key: 'processus',
        label: 'Processus',
        type: 'select' as const,
        inline: true,
        options: props.processus_options,
    },
    {
        key: 'statut',
        label: 'Statut',
        type: 'select' as const,
        options: [
            { value: 'creee', label: 'À valider' },
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
    processus: props.filtre_processus ?? 'vente',
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
    if (props.filtre_processus) params.set('processus', props.filtre_processus);
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

const indexSummary = computed<CommissionIndexSummary>(() => ({
    generated: props.kpis.commissions_generees,
    expenses: props.kpis.depenses,
    netValidated: props.kpis.net_valide,
    remaining: props.kpis.reste_a_payer,
}));

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
        <CommissionIndexLayout
            title="Commissions des sites"
            :entity-count="beneficiaires.length"
            entity-label="site"
            :period-label="periodContextLabel"
            filter-url="/backoffice/comptabilite/commissions/sites"
            :filter-values="currentFilters"
            :filter-fields="filterFields"
            :sites="sites"
            :summary="indexSummary"
            table-title="Détail par site"
            :result-count="beneficiaires.length"
            @export-excel="exportExcel"
            @export-pdf="exportPdf"
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
                    <ClickableTableRow
                        v-for="b in beneficiaires"
                        :key="b.beneficiaire_id"
                        :href="`/backoffice/comptabilite/commissions/sites/${b.beneficiaire_id}?processus=${currentFilters.processus}`"
                        :aria-label="`Voir le détail de ${b.beneficiaire_nom}`"
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
                                :status="
                                    b.statut_global === 'creee'
                                        ? 'en_attente'
                                        : b.statut_global
                                "
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
                                        <MoreHorizontal class="h-4 w-4" />
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
                    </ClickableTableRow>
                </tbody>
            </table>
        </CommissionIndexLayout>
    </AppLayout>

    <AuditDrawer
        v-model:visible="showAudit"
        :title="`Historique — ${auditBenefNom}`"
        auditable-type="App\Models\Site"
        :auditable-id="auditBenefId"
        module="commissions_sites"
    />
</template>
