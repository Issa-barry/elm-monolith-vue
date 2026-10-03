<script setup lang="ts">
import StatusDot from '@/components/StatusDot.vue';
import DataFilters, {
    type FilterField,
} from '@/components/filters/DataFilters.vue';
import { usePermissions } from '@/composables/usePermissions';
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
    /** Obligations échues encore impayées (mois précédents, 1re quinzaine vue en fin de mois). */
    arrieres: number;
    /** Ce que l'agence garde : total à régler + impayés échus (ADR 0016). */
    a_conserver: number;
    disponible: number | null;
    /** Argent d'autres agences présent dans ses supports : jamais pour ses obligations. */
    fonds_autres_agences: number | null;
    disponible_propre: number | null;
    fonds_en_transit: number | null;
    deja_finance: number | null;
    a_financer: number | null;
    remise_obligatoire: number | null;
    excedent_a_remettre: number | null;
    total_a_remettre: number | null;
    est_tresorerie_principale: boolean;
    statut:
        | 'couvert'
        | 'a_financer'
        | 'fonds_en_transit'
        | 'a_remettre'
        | 'tresorerie_principale'
        | 'donnees_incompletes';
}

type Totaux = {
    total_a_regler: number;
    arrieres: number;
    a_conserver: number;
    disponible: number;
    fonds_autres_agences: number;
    fonds_en_transit: number;
    deja_finance: number;
    a_financer: number;
    total_a_remettre: number;
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

const { can } = usePermissions();

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
    a_remettre: 'À remettre',
    tresorerie_principale: 'Trésorerie principale',
    donnees_incompletes: 'Données incomplètes',
};

function montantOuTiret(valeur: number | null): string {
    return valeur === null ? '—' : formatGNF(valeur);
}

// Détail de la remise en infobulle : la part obligatoire (argent d'autres agences) et l'excédent propre.
function detailRemise(row: Row): string {
    return `Argent d'autres agences : ${formatGNF(row.remise_obligatoire ?? 0)} · Excédent propre : ${formatGNF(row.excedent_a_remettre ?? 0)}`;
}

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
        <div class="w-full min-w-0 space-y-6 p-4 sm:p-6">
            <div class="flex flex-col gap-1">
                <h1 class="flex items-center gap-2 text-xl font-semibold">
                    <Wallet class="h-5 w-5 text-muted-foreground" />
                    Financement des agences
                </h1>
                <p class="text-sm text-muted-foreground">
                    Ce que chaque agence conserve pour ses obligations, ce
                    qu'elle remet à la trésorerie principale et ce qu'elle doit
                    en recevoir.
                </p>
            </div>

            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <div class="rounded-xl border bg-card p-4">
                    <p class="text-sm text-muted-foreground">À conserver</p>
                    <p class="mt-1 text-2xl font-bold tabular-nums">
                        {{ formatGNF(total_general.a_conserver) }}
                    </p>
                    <p
                        v-if="total_general.arrieres > 0"
                        class="mt-0.5 text-xs text-muted-foreground"
                    >
                        dont {{ formatGNF(total_general.arrieres) }} d'impayés
                        échus
                    </p>
                </div>
                <div class="rounded-xl border bg-card p-4">
                    <p class="text-sm text-muted-foreground">
                        Disponible dans les agences
                    </p>
                    <p class="mt-1 text-2xl font-bold tabular-nums">
                        {{ formatGNF(total_general.disponible) }}
                    </p>
                    <p
                        v-if="total_general.fonds_autres_agences > 0"
                        class="mt-0.5 text-xs text-muted-foreground"
                    >
                        dont
                        {{ formatGNF(total_general.fonds_autres_agences) }}
                        appartenant à d'autres agences
                    </p>
                </div>
                <div class="rounded-xl border bg-card p-4">
                    <p class="text-sm text-muted-foreground">
                        À remettre à la trésorerie principale
                    </p>
                    <p
                        class="mt-1 text-2xl font-bold text-amber-600 tabular-nums dark:text-amber-400"
                        data-testid="financement-total-a-remettre"
                    >
                        {{ formatGNF(total_general.total_a_remettre) }}
                    </p>
                </div>
                <div class="rounded-xl border bg-card p-4">
                    <p class="text-sm text-muted-foreground">
                        À financer par la trésorerie principale
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

            <div
                role="region"
                aria-label="Financement par agence — tableau à défilement horizontal"
                tabindex="0"
                class="w-full max-w-full min-w-0 overflow-x-auto overscroll-x-contain rounded-xl border bg-card focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
            >
                <table class="w-max min-w-full text-sm whitespace-nowrap">
                    <thead>
                        <tr class="border-b bg-muted/40 text-left">
                            <th
                                class="sticky left-0 z-10 min-w-40 bg-muted px-4 py-3 font-medium"
                            >
                                Agence
                            </th>
                            <th class="px-4 py-3 text-right font-medium">
                                Disponible
                            </th>
                            <th
                                class="px-4 py-3 text-right font-medium"
                                title="Argent encaissé pour d'autres agences : remis en totalité, jamais utilisé pour les obligations de l'agence."
                            >
                                Autres agences
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
                            <th
                                class="px-4 py-3 text-right font-medium"
                                title="Obligations échues encore impayées : mois précédents et, en fin de mois, la 1re quinzaine."
                            >
                                Impayés échus
                            </th>
                            <th class="px-4 py-3 text-right font-medium">
                                À conserver
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
                                À financer
                            </th>
                            <th
                                class="px-4 py-3 text-right font-semibold text-foreground"
                            >
                                À remettre
                            </th>
                            <th class="px-4 py-3 text-left font-medium">
                                Statut
                            </th>
                            <th class="px-4 py-3">
                                <span class="sr-only">Actions</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr
                            v-for="row in rows"
                            :key="row.site_id ?? 'sans-agence'"
                            class="hover:bg-muted/30"
                        >
                            <td
                                class="sticky left-0 z-10 max-w-56 min-w-40 bg-card px-4 py-3 font-medium whitespace-normal"
                            >
                                <Link
                                    :href="detailHref(row)"
                                    class="hover:underline"
                                >
                                    {{ row.site_nom }}
                                </Link>
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums">
                                {{ montantOuTiret(row.disponible) }}
                            </td>
                            <td
                                class="px-4 py-3 text-right tabular-nums"
                                data-testid="financement-autres-agences"
                            >
                                {{
                                    row.fonds_autres_agences
                                        ? formatGNF(row.fonds_autres_agences)
                                        : '—'
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
                                    row.arrieres ? formatGNF(row.arrieres) : '—'
                                }}
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums">
                                {{ formatGNF(row.a_conserver) }}
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
                                {{ montantOuTiret(row.a_financer) }}
                            </td>
                            <td
                                class="px-4 py-3 text-right font-semibold tabular-nums"
                                data-testid="financement-a-remettre"
                                :title="
                                    row.total_a_remettre
                                        ? detailRemise(row)
                                        : undefined
                                "
                            >
                                {{ montantOuTiret(row.total_a_remettre) }}
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap">
                                <StatusDot
                                    :status="row.statut"
                                    :label="statutLabels[row.statut]"
                                />
                            </td>
                            <td class="px-4 py-3 text-right whitespace-nowrap">
                                <Link
                                    v-if="
                                        row.statut === 'a_financer' &&
                                        can('tresorerie.create')
                                    "
                                    :href="nouveauFinancementHref(row)"
                                    class="text-xs font-medium text-primary hover:underline"
                                >
                                    Envoyer des fonds
                                </Link>
                            </td>
                        </tr>
                        <tr v-if="rows.length === 0">
                            <td
                                :colspan="12 + colonnesVisibles.length"
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
