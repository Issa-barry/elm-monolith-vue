<script setup lang="ts">
import StatusDot from '@/components/StatusDot.vue';
import DataFilters, {
    type FilterField,
} from '@/components/filters/DataFilters.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatGNF } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import { Wallet } from 'lucide-vue-next';
import { computed } from 'vue';

interface Row {
    site_id: string | null;
    site_nom: string;
    livreurs_p1: number;
    livreurs_p2: number;
    proprietaires: number;
    salaires: number;
    total_a_regler: number;
    disponible: number | null;
    fonds_en_transit: number | null;
    deja_finance: number | null;
    a_financer: number | null;
    statut:
        | 'couvert'
        | 'a_financer'
        | 'fonds_en_transit'
        | 'donnees_incompletes';
}

type Totaux = {
    total_a_regler: number;
    disponible: number;
    fonds_en_transit: number;
    deja_finance: number;
    a_financer: number;
};

const props = defineProps<{
    rows: Row[];
    total_general: Totaux;
    filters: {
        annee: string;
        mois: string;
        echeance: 'p1' | 'p2' | 'mensuel';
        site_ids: string[];
    };
    echeance_debut: string;
    echeance_fin: string;
    sites: { value: string; label: string }[];
    is_admin: boolean;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/backoffice/dashboard' },
    { title: 'Comptabilité' },
    { title: 'Financement des agences', href: '#' },
];

const anneeCourante = new Date().getFullYear();
const anneeOptions = Array.from({ length: 4 }, (_, i) => {
    const annee = anneeCourante + 1 - i;
    return { value: String(annee), label: String(annee) };
});

const moisNoms = [
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
const moisOptions = moisNoms.map((label, i) => ({
    value: String(i + 1),
    label,
}));

const filterFields: FilterField[] = [
    {
        key: 'annee',
        label: 'Année',
        type: 'select',
        inline: true,
        options: anneeOptions,
    },
    {
        key: 'mois',
        label: 'Mois',
        type: 'select',
        inline: true,
        options: moisOptions,
    },
];

const dernierJourDuMois = computed(() =>
    new Date(
        Number(props.filters.annee),
        Number(props.filters.mois),
        0,
    ).getDate(),
);

const echeanceTabs = computed(() => [
    { value: 'p1' as const, label: '1re quinzaine' },
    {
        value: 'p2' as const,
        label: `Fin de mois (16 – ${dernierJourDuMois.value})`,
    },
    { value: 'mensuel' as const, label: 'Mois complet' },
]);

function changerEcheance(echeance: 'p1' | 'p2' | 'mensuel') {
    router.get(
        '/backoffice/comptabilite/tresorerie/financement',
        { ...props.filters, echeance },
        { preserveScroll: true, replace: true },
    );
}

// ── Colonnes de commissions affichées selon l'échéance ────────────────────────
// P1 = seulement livreurs_p1 ; P2/mensuel = tout le reste (jamais les deux
// mélangés pour P1, cf. règle "ne jamais recompter le P1 dans le P2").

const colonnesVisibles = computed(() => {
    if (props.filters.echeance === 'p1') return ['livreurs_p1'] as const;
    if (props.filters.echeance === 'p2')
        return ['livreurs_p2', 'proprietaires', 'salaires'] as const;
    return ['livreurs_p1', 'livreurs_p2', 'proprietaires', 'salaires'] as const;
});

const labelsColonnes: Record<string, string> = {
    livreurs_p1: 'Livreurs P1',
    livreurs_p2: 'Livreurs P2',
    proprietaires: 'Propriétaires',
    salaires: 'Salaires',
};

function detailHref(row: Row): string {
    const site = row.site_id ?? 'sans-agence';
    return `/backoffice/comptabilite/tresorerie/financement/${site}?annee=${props.filters.annee}&mois=${props.filters.mois}`;
}

const statutLabels: Record<Row['statut'], string> = {
    couvert: 'Couvert',
    a_financer: 'À financer',
    fonds_en_transit: 'Fonds en transit',
    donnees_incompletes: 'Données incomplètes',
};

// DataFilters attend { id, nom } (convention Site), pas { value, label }.
const sitesPourFiltre = computed(() =>
    props.sites.map((s) => ({ id: s.value, nom: s.label })),
);

function valeurColonne(row: Row, col: string): number {
    return (row as unknown as Record<string, number>)[col] ?? 0;
}

function nouveauFinancementHref(row: Row): string {
    if (!row.site_id || !row.a_financer)
        return '/backoffice/comptabilite/tresorerie/mouvements/create';
    const params = new URLSearchParams({
        site_id: row.site_id,
        montant: String(Math.round(row.a_financer)),
        echeance_debut: props.echeance_debut,
        echeance_fin: props.echeance_fin,
    });
    return `/backoffice/comptabilite/tresorerie/mouvements/create?${params.toString()}`;
}
</script>

<template>
    <Head title="Financement des agences" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="w-full space-y-6 p-4 sm:p-6">
            <div class="flex flex-col gap-1">
                <h1 class="flex items-center gap-2 text-xl font-semibold">
                    <Wallet class="h-5 w-5 text-muted-foreground" />
                    Financement des agences
                </h1>
                <p class="text-sm text-muted-foreground">
                    Complément réel à envoyer à chaque agence, une fois sa
                    trésorerie disponible déduite.
                </p>
            </div>

            <div class="grid gap-4 sm:grid-cols-3">
                <div class="rounded-xl border bg-card p-4">
                    <p class="text-sm text-muted-foreground">Total à régler</p>
                    <p class="mt-1 text-2xl font-bold tabular-nums">
                        {{ formatGNF(total_general.total_a_regler) }}
                    </p>
                </div>
                <div class="rounded-xl border bg-card p-4">
                    <p class="text-sm text-muted-foreground">
                        Disponible dans les agences
                    </p>
                    <p class="mt-1 text-2xl font-bold tabular-nums">
                        {{ formatGNF(total_general.disponible) }}
                    </p>
                </div>
                <div class="rounded-xl border bg-card p-4">
                    <p class="text-sm text-muted-foreground">
                        À financer par le siège
                    </p>
                    <p
                        class="mt-1 text-2xl font-bold text-orange-600 tabular-nums dark:text-orange-400"
                    >
                        {{ formatGNF(total_general.a_financer) }}
                    </p>
                    <p
                        v-if="total_general.fonds_en_transit > 0"
                        class="mt-0.5 text-xs text-muted-foreground"
                    >
                        dont
                        {{ formatGNF(total_general.fonds_en_transit) }} déjà en
                        transit
                    </p>
                </div>
            </div>

            <DataFilters
                url="/backoffice/comptabilite/tresorerie/financement"
                :values="filters"
                :fields="filterFields"
                :sites="sitesPourFiltre"
                :result-count="rows.length"
                hide-result-count
            >
                <template #inline>
                    <div class="flex shrink-0 flex-col gap-1">
                        <span
                            class="text-xs font-medium text-transparent select-none"
                            aria-hidden="true"
                            >Échéance</span
                        >
                        <div class="flex flex-wrap items-center gap-2">
                            <button
                                v-for="tab in echeanceTabs"
                                :key="tab.value"
                                type="button"
                                class="h-9 rounded-md border px-3 text-sm font-medium transition-colors"
                                :class="
                                    filters.echeance === tab.value
                                        ? 'border-primary bg-primary text-primary-foreground'
                                        : 'border-input bg-background text-muted-foreground hover:bg-muted'
                                "
                                @click="changerEcheance(tab.value)"
                            >
                                {{ tab.label }}
                            </button>
                        </div>
                    </div>
                </template>
            </DataFilters>

            <div class="overflow-x-auto rounded-xl border bg-card">
                <table class="w-full min-w-[960px] text-sm">
                    <thead>
                        <tr class="border-b bg-muted/40 text-left">
                            <th
                                class="sticky left-0 z-10 bg-muted/40 px-4 py-3 font-medium"
                            >
                                Agence
                            </th>
                            <th class="px-4 py-3 text-right font-medium">
                                Disponible
                            </th>
                            <th
                                v-for="col in colonnesVisibles"
                                :key="col"
                                class="px-4 py-3 text-right font-medium"
                            >
                                {{ labelsColonnes[col] }}
                            </th>
                            <th class="px-4 py-3 text-right font-medium">
                                Total à régler
                            </th>
                            <th class="px-4 py-3 text-right font-medium">
                                Fonds en transit
                            </th>
                            <th class="px-4 py-3 text-right font-medium">
                                Déjà financé
                            </th>
                            <th
                                class="px-4 py-3 text-right font-semibold text-foreground"
                            >
                                À envoyer
                            </th>
                            <th class="px-4 py-3 text-left font-medium">
                                Statut
                            </th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr
                            v-for="row in rows"
                            :key="row.site_id ?? 'sans-agence'"
                            class="hover:bg-muted/30"
                        >
                            <td
                                class="sticky left-0 z-10 bg-card px-4 py-3 font-medium"
                            >
                                <Link
                                    :href="detailHref(row)"
                                    class="hover:underline"
                                >
                                    {{ row.site_nom }}
                                </Link>
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums">
                                {{
                                    row.disponible === null
                                        ? '—'
                                        : formatGNF(row.disponible)
                                }}
                            </td>
                            <td
                                v-for="col in colonnesVisibles"
                                :key="col"
                                class="px-4 py-3 text-right tabular-nums"
                            >
                                {{ formatGNF(valeurColonne(row, col)) }}
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums">
                                {{ formatGNF(row.total_a_regler) }}
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums">
                                {{
                                    row.fonds_en_transit
                                        ? formatGNF(row.fonds_en_transit)
                                        : '—'
                                }}
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums">
                                {{
                                    row.deja_finance
                                        ? formatGNF(row.deja_finance)
                                        : '—'
                                }}
                            </td>
                            <td
                                class="px-4 py-3 text-right font-semibold tabular-nums"
                            >
                                {{
                                    row.a_financer === null
                                        ? '—'
                                        : formatGNF(row.a_financer)
                                }}
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap">
                                <StatusDot
                                    :status="row.statut"
                                    :label="statutLabels[row.statut]"
                                />
                            </td>
                            <td class="px-4 py-3 text-right whitespace-nowrap">
                                <Link
                                    v-if="row.statut === 'a_financer'"
                                    :href="nouveauFinancementHref(row)"
                                    class="text-xs font-medium text-primary hover:underline"
                                >
                                    Envoyer des fonds
                                </Link>
                            </td>
                        </tr>
                        <tr v-if="rows.length === 0">
                            <td
                                :colspan="6 + colonnesVisibles.length"
                                class="px-4 py-10 text-center text-muted-foreground"
                            >
                                Aucune agence pour cette période.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppLayout>
</template>
