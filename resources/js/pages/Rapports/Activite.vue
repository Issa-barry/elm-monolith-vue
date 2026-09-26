<script setup lang="ts">
import DataFilters, {
    type FilterField,
} from '@/components/filters/DataFilters.vue';
import StatusDot from '@/components/StatusDot.vue';
import CaisseFiche from '@/components/tresorerie/CaisseFiche.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { useUrlTab } from '@/composables/useUrlTab';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatGNF } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import type { AnomalieMobileMoney, RapportActivite } from '@/types/rapports';
import { Head, usePage } from '@inertiajs/vue3';
import {
    AlertTriangle,
    ChartColumn,
    FileSpreadsheet,
    FileText,
    Info,
    UserRound,
} from 'lucide-vue-next';
import { computed } from 'vue';

// Même page pour « Ma situation » (agent imposé par le serveur) et le rapport d'activité (agences
// et agents selon les droits) — cf. RapportActivitePresenter. Chaque onglet est un bloc calculé
// indépendamment : jamais de « reste » déduit par différence entre deux blocs.
const props = defineProps<{
    mode: 'ma_situation' | 'rapport';
    url: string;
    export_url: string;
    filters: {
        periode: string;
        date_from: string | null;
        date_to: string | null;
        site_ids: string[];
        agent_id: string | null;
    };
    periode: {
        cle: string;
        date_debut: string;
        date_fin: string;
        libelle: string;
        options: { value: string; label: string }[];
    };
    sites: { id: string; nom: string }[];
    agents: { value: string; label: string }[];
    agent: { id: string; nom: string } | null;
    limite_lignes: number;
    rapport: RapportActivite;
}>();

const page = usePage();
const maSituation = computed(() => props.mode === 'ma_situation');
const titre = computed(() =>
    maSituation.value ? 'Ma situation' : "Rapport d'activité",
);

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
    { title: 'Tableau de bord', href: '/backoffice/dashboard' },
    { title: titre.value, href: props.url },
]);

const ONGLETS = [
    'ventes',
    'encaissements',
    'creances',
    'mobile_money',
    'caisse',
] as const;
type Onglet = (typeof ONGLETS)[number];
const { onglet, choisir } = useUrlTab<Onglet>(ONGLETS, 'ventes');

const anomaliesMobileMoney = computed(
    () =>
        props.rapport.mobile_money.resume.reference_absente +
        props.rapport.mobile_money.resume.reference_dupliquee,
);

const onglets = computed(() => [
    {
        cle: 'ventes' as Onglet,
        libelle: 'Ventes',
        compte: props.rapport.ventes.resume.nombre,
    },
    {
        cle: 'encaissements' as Onglet,
        libelle: 'Encaissements',
        compte: props.rapport.encaissements.resume.nombre,
    },
    {
        cle: 'creances' as Onglet,
        libelle: 'Créances',
        compte: props.rapport.creances.resume.nombre,
    },
    {
        cle: 'mobile_money' as Onglet,
        libelle: 'Mobile Money',
        compte: props.rapport.mobile_money.resume.nombre,
    },
    { cle: 'caisse' as Onglet, libelle: 'Caisse', compte: null },
]);

const filterFields = computed<FilterField[]>(() => [
    ...(maSituation.value
        ? []
        : [
              {
                  key: 'agent_id',
                  label: 'Agent',
                  type: 'select' as const,
                  searchable: true,
                  inline: true,
                  wide: true,
                  placeholder: 'Tous les agents',
                  options: props.agents,
              },
          ]),
    {
        key: 'periode',
        label: 'Période',
        type: 'period',
        inline: true,
        options: props.periode.options,
        defaultValue: 'aujourd_hui',
        startKey: 'date_from',
        endKey: 'date_to',
    },
]);

// Exports : mêmes paramètres que l'écran (le serveur réapplique le périmètre), sans l'onglet.
function exportHref(format: 'xlsx' | 'pdf'): string {
    const params = new URLSearchParams(
        new URL(page.url, 'http://localhost').search,
    );
    params.delete('tab');
    params.set('format', format);

    return `${props.export_url}?${params.toString()}`;
}

function dateFr(iso: string | null | undefined): string {
    return iso ? iso.slice(0, 10).split('-').reverse().join('/') : '—';
}

function heureFr(dateHeure: string | null): string {
    return dateHeure ? `${dateFr(dateHeure)} ${dateHeure.slice(11, 16)}` : '—';
}

const LIBELLES_ANOMALIE: Record<AnomalieMobileMoney, string> = {
    reference_absente: 'Référence absente',
    reference_dupliquee: 'Référence déjà utilisée',
    anterieure_obligation: "Sans référence (avant l'obligation)",
};

function tronque(section: { lignes: unknown[]; total_lignes: number }) {
    return section.total_lignes > section.lignes.length;
}
</script>

<template>
    <Head :title="titre" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="w-full space-y-6 p-4 sm:p-6">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="flex flex-col gap-1">
                    <h1 class="flex items-center gap-2 text-xl font-semibold">
                        <UserRound
                            v-if="maSituation"
                            class="h-5 w-5 text-muted-foreground"
                        />
                        <ChartColumn
                            v-else
                            class="h-5 w-5 text-muted-foreground"
                        />
                        {{ titre }}
                    </h1>
                    <p class="text-sm text-muted-foreground">
                        <span v-if="agent">{{ agent.nom }} — </span>
                        {{ periode.libelle }}
                    </p>
                </div>
                <div class="flex gap-2">
                    <a
                        :href="exportHref('xlsx')"
                        data-testid="rapport-export-excel"
                        class="inline-flex h-9 items-center gap-1.5 rounded-md border bg-background px-3 text-sm font-medium hover:bg-muted"
                    >
                        <FileSpreadsheet class="h-4 w-4" /> Excel
                    </a>
                    <a
                        :href="exportHref('pdf')"
                        data-testid="rapport-export-pdf"
                        class="inline-flex h-9 items-center gap-1.5 rounded-md border bg-background px-3 text-sm font-medium hover:bg-muted"
                    >
                        <FileText class="h-4 w-4" /> PDF
                    </a>
                </div>
            </div>

            <DataFilters
                :url="url"
                :values="filters"
                :fields="filterFields"
                :sites="sites"
                :hide-agence-selector="maSituation"
                :result-count="0"
                hide-result-count
            />

            <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
                <div class="rounded-xl border bg-card p-4">
                    <p class="text-xs text-muted-foreground sm:text-sm">
                        {{ maSituation ? 'Mes ventes' : 'Ventes' }}
                    </p>
                    <p
                        class="mt-1 text-lg font-bold tabular-nums sm:text-2xl"
                        data-testid="kpi-ventes"
                    >
                        {{ formatGNF(rapport.ventes.resume.facture) }}
                    </p>
                    <p class="text-xs text-muted-foreground">
                        {{ rapport.ventes.resume.nombre }} vente(s) sur la
                        période
                    </p>
                </div>
                <div class="rounded-xl border bg-card p-4">
                    <p class="text-xs text-muted-foreground sm:text-sm">
                        {{ maSituation ? 'Mes encaissements' : 'Encaissé' }}
                    </p>
                    <p
                        class="mt-1 text-lg font-bold tabular-nums sm:text-2xl"
                        data-testid="kpi-encaissements"
                    >
                        {{ formatGNF(rapport.encaissements.resume.montant) }}
                    </p>
                    <p class="text-xs text-muted-foreground">
                        {{ rapport.encaissements.resume.nombre }}
                        encaissement(s) sur la période
                    </p>
                </div>
                <div class="rounded-xl border bg-card p-4">
                    <p class="text-xs text-muted-foreground sm:text-sm">
                        Créances en cours
                    </p>
                    <p
                        class="mt-1 text-lg font-bold tabular-nums sm:text-2xl"
                        data-testid="kpi-creances"
                    >
                        {{ formatGNF(rapport.creances.resume.reste) }}
                    </p>
                    <p class="text-xs text-muted-foreground">
                        {{ rapport.creances.resume.nombre }} facture(s), toutes
                        dates
                    </p>
                </div>
                <div class="rounded-xl border bg-card p-4">
                    <p class="text-xs text-muted-foreground sm:text-sm">
                        {{ maSituation ? 'Ma caisse' : 'Caisses dédiées' }}
                    </p>
                    <template v-if="rapport.caisse.aucune_caisse">
                        <p
                            class="mt-1 text-sm font-medium text-muted-foreground"
                            data-testid="kpi-caisse"
                        >
                            Aucune caisse dédiée
                        </p>
                    </template>
                    <template v-else>
                        <p
                            class="mt-1 text-lg font-bold tabular-nums sm:text-2xl"
                            data-testid="kpi-caisse"
                        >
                            {{ formatGNF(rapport.caisse.resume.solde_actuel) }}
                        </p>
                        <p class="text-xs text-muted-foreground">
                            À remettre (solde théorique actuel)
                        </p>
                    </template>
                </div>
            </div>

            <p class="flex items-start gap-1.5 text-xs text-muted-foreground">
                <Info class="mt-0.5 h-3.5 w-3.5 shrink-0" />
                Ventes : factures créées dans la période. Encaissements :
                paiements reçus dans la période, quelle que soit la date de la
                vente. Créances : état actuel, toutes dates confondues.
            </p>

            <!-- Onglets -->
            <div class="-mx-4 overflow-x-auto px-4 sm:mx-0 sm:px-0">
                <div class="flex min-w-max gap-1 border-b" role="tablist">
                    <button
                        v-for="o in onglets"
                        :key="o.cle"
                        type="button"
                        role="tab"
                        :aria-selected="onglet === o.cle"
                        :data-testid="`rapport-tab-${o.cle}`"
                        class="-mb-px inline-flex items-center gap-1.5 border-b-2 px-3 py-2 text-sm font-medium whitespace-nowrap transition-colors"
                        :class="
                            onglet === o.cle
                                ? 'border-primary text-foreground'
                                : 'border-transparent text-muted-foreground hover:text-foreground'
                        "
                        @click="choisir(o.cle)"
                    >
                        {{ o.libelle }}
                        <span
                            v-if="o.compte !== null"
                            class="text-xs text-muted-foreground tabular-nums"
                            >{{ o.compte }}</span
                        >
                        <AlertTriangle
                            v-if="
                                o.cle === 'mobile_money' &&
                                anomaliesMobileMoney > 0
                            "
                            class="h-3.5 w-3.5 text-amber-500"
                        />
                    </button>
                </div>
            </div>

            <!-- ── Ventes ─────────────────────────────────────────────────── -->
            <section v-if="onglet === 'ventes'" class="space-y-3">
                <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                    <div class="rounded-lg border p-3">
                        <p class="text-xs text-muted-foreground">Facturé</p>
                        <p class="font-semibold tabular-nums">
                            {{ formatGNF(rapport.ventes.resume.facture) }}
                        </p>
                    </div>
                    <div class="rounded-lg border p-3">
                        <p class="text-xs text-muted-foreground">
                            Encaissé sur ces ventes
                        </p>
                        <p class="font-semibold tabular-nums">
                            {{ formatGNF(rapport.ventes.resume.encaisse) }}
                        </p>
                    </div>
                    <div class="rounded-lg border p-3">
                        <p class="text-xs text-muted-foreground">
                            Reste à payer sur ces ventes
                        </p>
                        <p class="font-semibold tabular-nums">
                            {{ formatGNF(rapport.ventes.resume.reste) }}
                        </p>
                    </div>
                    <div class="rounded-lg border p-3">
                        <p class="text-xs text-muted-foreground">
                            Annulées / retournées (hors CA)
                        </p>
                        <p class="font-semibold tabular-nums">
                            {{ rapport.ventes.resume.annulees_nombre }} ·
                            {{
                                formatGNF(
                                    rapport.ventes.resume.annulees_montant,
                                )
                            }}
                        </p>
                    </div>
                </div>
                <p class="text-xs text-muted-foreground">
                    Encaissé et reste : état actuel de ces factures, y compris
                    les paiements reçus après la période.
                </p>
                <div class="overflow-x-auto rounded-xl border bg-card">
                    <table class="w-full min-w-[720px] text-sm">
                        <thead>
                            <tr class="border-b bg-muted/40 text-left">
                                <th class="px-3 py-2 font-medium">Facture</th>
                                <th class="px-3 py-2 font-medium">Date</th>
                                <th class="px-3 py-2 font-medium">Client</th>
                                <th
                                    v-if="!maSituation"
                                    class="px-3 py-2 font-medium"
                                >
                                    Agent
                                </th>
                                <th class="px-3 py-2 font-medium">Agence</th>
                                <th class="px-3 py-2 text-right font-medium">
                                    Montant
                                </th>
                                <th class="px-3 py-2 text-right font-medium">
                                    Encaissé
                                </th>
                                <th class="px-3 py-2 text-right font-medium">
                                    Reste
                                </th>
                                <th class="px-3 py-2 font-medium">Statut</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr
                                v-for="l in rapport.ventes.lignes"
                                :key="l.id"
                                class="hover:bg-muted/30"
                            >
                                <td class="px-3 py-2 font-mono text-xs">
                                    {{ l.reference }}
                                </td>
                                <td class="px-3 py-2 whitespace-nowrap">
                                    {{ dateFr(l.date) }}
                                </td>
                                <td class="px-3 py-2">{{ l.client ?? '—' }}</td>
                                <td v-if="!maSituation" class="px-3 py-2">
                                    {{ l.agent ?? '—' }}
                                </td>
                                <td class="px-3 py-2">
                                    {{ l.site_nom ?? '—' }}
                                </td>
                                <td class="px-3 py-2 text-right tabular-nums">
                                    {{ formatGNF(l.montant) }}
                                </td>
                                <td class="px-3 py-2 text-right tabular-nums">
                                    {{ formatGNF(l.encaisse) }}
                                </td>
                                <td class="px-3 py-2 text-right tabular-nums">
                                    {{ formatGNF(l.reste) }}
                                </td>
                                <td class="px-3 py-2">
                                    <StatusDot
                                        :status="l.statut"
                                        :label="l.statut_label"
                                    />
                                </td>
                            </tr>
                            <tr v-if="rapport.ventes.lignes.length === 0">
                                <td
                                    colspan="9"
                                    class="px-3 py-8 text-center text-muted-foreground"
                                >
                                    Aucune vente sur la période.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p
                    v-if="tronque(rapport.ventes)"
                    class="text-xs text-muted-foreground"
                >
                    {{ rapport.ventes.lignes.length }} lignes affichées sur
                    {{ rapport.ventes.total_lignes }} — exportez pour la liste
                    complète (les totaux portent sur toutes les lignes).
                </p>
            </section>

            <!-- ── Encaissements ──────────────────────────────────────────── -->
            <section v-if="onglet === 'encaissements'" class="space-y-3">
                <div
                    v-if="rapport.encaissements.par_moyen.length > 0"
                    class="grid grid-cols-2 gap-3 sm:grid-cols-4"
                >
                    <div
                        v-for="m in rapport.encaissements.par_moyen"
                        :key="m.cle"
                        class="rounded-lg border p-3"
                        :data-testid="`encaissement-moyen-${m.cle}`"
                    >
                        <p class="text-xs text-muted-foreground">
                            {{ m.libelle }} ({{ m.nombre }})
                        </p>
                        <p class="font-semibold tabular-nums">
                            {{ formatGNF(m.montant) }}
                        </p>
                    </div>
                </div>
                <div class="overflow-x-auto rounded-xl border bg-card">
                    <table class="w-full min-w-[760px] text-sm">
                        <thead>
                            <tr class="border-b bg-muted/40 text-left">
                                <th class="px-3 py-2 font-medium">Date</th>
                                <th class="px-3 py-2 font-medium">Saisi le</th>
                                <th class="px-3 py-2 font-medium">Facture</th>
                                <th class="px-3 py-2 font-medium">Client</th>
                                <th
                                    v-if="!maSituation"
                                    class="px-3 py-2 font-medium"
                                >
                                    Agent
                                </th>
                                <th class="px-3 py-2 font-medium">Moyen</th>
                                <th class="px-3 py-2 font-medium">Référence</th>
                                <th class="px-3 py-2 text-right font-medium">
                                    Montant
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr
                                v-for="l in rapport.encaissements.lignes"
                                :key="l.id"
                                class="hover:bg-muted/30"
                            >
                                <td class="px-3 py-2 whitespace-nowrap">
                                    {{ dateFr(l.date_encaissement) }}
                                </td>
                                <td
                                    class="px-3 py-2 whitespace-nowrap"
                                    :class="
                                        l.saisie_differee
                                            ? 'text-amber-700 dark:text-amber-400'
                                            : 'text-muted-foreground'
                                    "
                                    :title="
                                        l.saisie_differee
                                            ? 'Saisi un autre jour que la date d\'encaissement'
                                            : undefined
                                    "
                                >
                                    {{ heureFr(l.saisi_le) }}
                                </td>
                                <td class="px-3 py-2 font-mono text-xs">
                                    {{ l.facture_reference }}
                                </td>
                                <td class="px-3 py-2">{{ l.client ?? '—' }}</td>
                                <td v-if="!maSituation" class="px-3 py-2">
                                    {{ l.agent ?? '—' }}
                                </td>
                                <td class="px-3 py-2">{{ l.moyen_libelle }}</td>
                                <td class="px-3 py-2 font-mono text-xs">
                                    {{ l.reference_paiement ?? '—' }}
                                </td>
                                <td class="px-3 py-2 text-right tabular-nums">
                                    {{ formatGNF(l.montant) }}
                                </td>
                            </tr>
                            <tr
                                v-if="rapport.encaissements.lignes.length === 0"
                            >
                                <td
                                    colspan="8"
                                    class="px-3 py-8 text-center text-muted-foreground"
                                >
                                    Aucun encaissement sur la période.
                                </td>
                            </tr>
                        </tbody>
                        <tfoot v-if="rapport.encaissements.lignes.length > 0">
                            <tr class="border-t bg-muted/20 font-semibold">
                                <td
                                    :colspan="maSituation ? 6 : 7"
                                    class="px-3 py-2"
                                >
                                    Total de la période
                                </td>
                                <td class="px-3 py-2 text-right tabular-nums">
                                    {{
                                        formatGNF(
                                            rapport.encaissements.resume
                                                .montant,
                                        )
                                    }}
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <p class="text-xs text-muted-foreground">
                    « Saisi le » est l'heure d'enregistrement dans
                    l'application, pas l'heure du paiement. En orange : saisi un
                    autre jour que la date d'encaissement.
                </p>
                <p
                    v-if="tronque(rapport.encaissements)"
                    class="text-xs text-muted-foreground"
                >
                    {{ rapport.encaissements.lignes.length }} lignes affichées
                    sur {{ rapport.encaissements.total_lignes }} — exportez pour
                    la liste complète.
                </p>
            </section>

            <!-- ── Créances ───────────────────────────────────────────────── -->
            <section v-if="onglet === 'creances'" class="space-y-3">
                <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                    <div class="rounded-lg border p-3">
                        <p class="text-xs text-muted-foreground">Impayées</p>
                        <p class="font-semibold tabular-nums">
                            {{ rapport.creances.resume.impayees }}
                        </p>
                    </div>
                    <div class="rounded-lg border p-3">
                        <p class="text-xs text-muted-foreground">
                            Partiellement payées
                        </p>
                        <p class="font-semibold tabular-nums">
                            {{ rapport.creances.resume.partielles }}
                        </p>
                    </div>
                    <div class="rounded-lg border p-3">
                        <p class="text-xs text-muted-foreground">Reste dû</p>
                        <p class="font-semibold tabular-nums">
                            {{ formatGNF(rapport.creances.resume.reste) }}
                        </p>
                    </div>
                    <div class="rounded-lg border p-3">
                        <p class="text-xs text-muted-foreground">
                            Plus ancienne
                        </p>
                        <p class="font-semibold tabular-nums">
                            {{ dateFr(rapport.creances.resume.plus_ancienne) }}
                        </p>
                    </div>
                </div>
                <p class="text-xs text-muted-foreground">
                    État actuel des factures restant dues, toutes dates
                    confondues : la période choisie ne s'applique pas aux
                    créances.
                </p>
                <div class="overflow-x-auto rounded-xl border bg-card">
                    <table class="w-full min-w-[760px] text-sm">
                        <thead>
                            <tr class="border-b bg-muted/40 text-left">
                                <th class="px-3 py-2 font-medium">Facture</th>
                                <th class="px-3 py-2 font-medium">Date</th>
                                <th class="px-3 py-2 text-right font-medium">
                                    Ancienneté
                                </th>
                                <th class="px-3 py-2 font-medium">Client</th>
                                <th
                                    v-if="!maSituation"
                                    class="px-3 py-2 font-medium"
                                >
                                    Agent
                                </th>
                                <th class="px-3 py-2 text-right font-medium">
                                    Montant
                                </th>
                                <th class="px-3 py-2 text-right font-medium">
                                    Encaissé
                                </th>
                                <th class="px-3 py-2 text-right font-medium">
                                    Reste
                                </th>
                                <th class="px-3 py-2 font-medium">Statut</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr
                                v-for="l in rapport.creances.lignes"
                                :key="l.id"
                                class="hover:bg-muted/30"
                            >
                                <td class="px-3 py-2 font-mono text-xs">
                                    {{ l.reference }}
                                </td>
                                <td class="px-3 py-2 whitespace-nowrap">
                                    {{ dateFr(l.date) }}
                                </td>
                                <td
                                    class="px-3 py-2 text-right whitespace-nowrap tabular-nums"
                                >
                                    {{ l.anciennete_jours }} j
                                </td>
                                <td class="px-3 py-2">{{ l.client ?? '—' }}</td>
                                <td v-if="!maSituation" class="px-3 py-2">
                                    {{ l.agent ?? '—' }}
                                </td>
                                <td class="px-3 py-2 text-right tabular-nums">
                                    {{ formatGNF(l.montant) }}
                                </td>
                                <td class="px-3 py-2 text-right tabular-nums">
                                    {{ formatGNF(l.encaisse) }}
                                </td>
                                <td
                                    class="px-3 py-2 text-right font-medium tabular-nums"
                                >
                                    {{ formatGNF(l.reste) }}
                                </td>
                                <td class="px-3 py-2">
                                    <StatusDot
                                        :status="l.statut"
                                        :label="l.statut_label"
                                    />
                                </td>
                            </tr>
                            <tr v-if="rapport.creances.lignes.length === 0">
                                <td
                                    colspan="9"
                                    class="px-3 py-8 text-center text-muted-foreground"
                                >
                                    Aucune créance en cours.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p
                    v-if="tronque(rapport.creances)"
                    class="text-xs text-muted-foreground"
                >
                    {{ rapport.creances.lignes.length }} lignes affichées (les
                    plus anciennes) sur {{ rapport.creances.total_lignes }} —
                    exportez pour la liste complète.
                </p>
            </section>

            <!-- ── Mobile Money ───────────────────────────────────────────── -->
            <section v-if="onglet === 'mobile_money'" class="space-y-3">
                <div
                    v-if="rapport.mobile_money.par_operateur.length > 0"
                    class="grid grid-cols-2 gap-3 sm:grid-cols-4"
                >
                    <div
                        v-for="o in rapport.mobile_money.par_operateur"
                        :key="o.operateur ?? 'aucun'"
                        class="rounded-lg border p-3"
                    >
                        <p class="text-xs text-muted-foreground">
                            {{ o.libelle }} ({{ o.nombre }})
                        </p>
                        <p class="font-semibold tabular-nums">
                            {{ formatGNF(o.montant) }}
                        </p>
                    </div>
                </div>

                <Alert
                    v-if="anomaliesMobileMoney > 0"
                    data-testid="mobile-money-anomalies"
                >
                    <AlertTriangle class="text-amber-500" />
                    <AlertTitle>
                        {{ anomaliesMobileMoney }} référence(s) à vérifier
                    </AlertTitle>
                    <AlertDescription>
                        {{ rapport.mobile_money.resume.reference_absente }}
                        absente(s),
                        {{ rapport.mobile_money.resume.reference_dupliquee }}
                        déjà utilisée(s) pour le même opérateur dans
                        l'organisation. Rapprochez-les du relevé de l'opérateur.
                    </AlertDescription>
                </Alert>

                <div class="overflow-x-auto rounded-xl border bg-card">
                    <table class="w-full min-w-[760px] text-sm">
                        <thead>
                            <tr class="border-b bg-muted/40 text-left">
                                <th class="px-3 py-2 font-medium">Date</th>
                                <th class="px-3 py-2 font-medium">Opérateur</th>
                                <th class="px-3 py-2 font-medium">Référence</th>
                                <th class="px-3 py-2 text-right font-medium">
                                    Montant
                                </th>
                                <th class="px-3 py-2 font-medium">Facture</th>
                                <th class="px-3 py-2 font-medium">Client</th>
                                <th
                                    v-if="!maSituation"
                                    class="px-3 py-2 font-medium"
                                >
                                    Agent
                                </th>
                                <th class="px-3 py-2 font-medium">Contrôle</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr
                                v-for="l in rapport.mobile_money.lignes"
                                :key="l.id"
                                class="hover:bg-muted/30"
                                :data-testid="`mobile-money-ligne-${l.id}`"
                            >
                                <td class="px-3 py-2 whitespace-nowrap">
                                    {{ dateFr(l.date_encaissement) }}
                                </td>
                                <td class="px-3 py-2">{{ l.moyen_libelle }}</td>
                                <td class="px-3 py-2 font-mono text-xs">
                                    {{ l.reference_paiement ?? '—' }}
                                </td>
                                <td class="px-3 py-2 text-right tabular-nums">
                                    {{ formatGNF(l.montant) }}
                                </td>
                                <td class="px-3 py-2 font-mono text-xs">
                                    {{ l.facture_reference }}
                                </td>
                                <td class="px-3 py-2">{{ l.client ?? '—' }}</td>
                                <td v-if="!maSituation" class="px-3 py-2">
                                    {{ l.agent ?? '—' }}
                                </td>
                                <td class="px-3 py-2 text-xs">
                                    <span
                                        v-if="l.anomalie === null"
                                        class="text-muted-foreground"
                                        >OK</span
                                    >
                                    <span
                                        v-else-if="
                                            l.anomalie ===
                                            'anterieure_obligation'
                                        "
                                        class="text-muted-foreground"
                                        >{{
                                            LIBELLES_ANOMALIE[l.anomalie]
                                        }}</span
                                    >
                                    <span
                                        v-else
                                        class="inline-flex items-center gap-1 font-medium text-amber-700 dark:text-amber-400"
                                    >
                                        <AlertTriangle class="h-3.5 w-3.5" />
                                        {{ LIBELLES_ANOMALIE[l.anomalie] }}
                                    </span>
                                    <div
                                        v-if="
                                            l.autres_utilisations.length > 0 ||
                                            l.autres_hors_perimetre > 0
                                        "
                                        class="mt-0.5 text-muted-foreground"
                                    >
                                        Aussi sur
                                        <span
                                            v-for="(
                                                a, i
                                            ) in l.autres_utilisations"
                                            :key="a.id"
                                            class="font-mono"
                                            >{{ i > 0 ? ', ' : ''
                                            }}{{ a.facture_reference }} ({{
                                                dateFr(a.date_encaissement)
                                            }})</span
                                        >
                                        <span v-if="l.autres_hors_perimetre > 0"
                                            >{{
                                                l.autres_utilisations.length > 0
                                                    ? ' + '
                                                    : ''
                                            }}{{ l.autres_hors_perimetre }} hors
                                            de votre périmètre</span
                                        >
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="rapport.mobile_money.lignes.length === 0">
                                <td
                                    colspan="8"
                                    class="px-3 py-8 text-center text-muted-foreground"
                                >
                                    Aucun encaissement Mobile Money sur la
                                    période.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p
                    v-if="tronque(rapport.mobile_money)"
                    class="text-xs text-muted-foreground"
                >
                    {{ rapport.mobile_money.lignes.length }} lignes affichées
                    sur {{ rapport.mobile_money.total_lignes }} — exportez pour
                    la liste complète.
                </p>
            </section>

            <!-- ── Caisse ─────────────────────────────────────────────────── -->
            <section v-if="onglet === 'caisse'" class="space-y-3">
                <Alert
                    v-if="rapport.caisse.aucune_caisse"
                    data-testid="caisse-aucune"
                >
                    <Info class="text-blue-500" />
                    <AlertTitle>Aucune caisse dédiée</AlertTitle>
                    <AlertDescription>
                        {{
                            maSituation
                                ? "Vous n'avez pas de caisse dédiée : vos encaissements en espèces ne peuvent pas être suivis ici."
                                : 'Aucune caisse dédiée à un agent dans ce périmètre.'
                        }}
                    </AlertDescription>
                </Alert>

                <template v-else>
                    <p class="text-xs text-muted-foreground">
                        Tableau de caisse tiré du grand livre : solde de début +
                        mouvements = solde de fin. Le solde actuel est le
                        montant théorique à remettre — aucun comptage physique
                        n'est enregistré.
                    </p>

                    <!-- Vue agence : une ligne par caisse -->
                    <div
                        v-if="!rapport.caisse.detail"
                        class="overflow-x-auto rounded-xl border bg-card"
                    >
                        <table class="w-full min-w-[860px] text-sm">
                            <thead>
                                <tr class="border-b bg-muted/40 text-left">
                                    <th class="px-3 py-2 font-medium">Agent</th>
                                    <th class="px-3 py-2 font-medium">
                                        Agence
                                    </th>
                                    <th
                                        class="px-3 py-2 text-right font-medium"
                                    >
                                        Solde début
                                    </th>
                                    <th
                                        class="px-3 py-2 text-right font-medium"
                                    >
                                        Entrées
                                    </th>
                                    <th
                                        class="px-3 py-2 text-right font-medium"
                                    >
                                        Sorties
                                    </th>
                                    <th
                                        class="px-3 py-2 text-right font-medium"
                                    >
                                        Solde fin
                                    </th>
                                    <th
                                        class="px-3 py-2 text-right font-medium"
                                    >
                                        Solde actuel
                                    </th>
                                    <th class="px-3 py-2 font-medium">
                                        Dernier versement
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="divide-y">
                                <tr
                                    v-for="f in rapport.caisse.fiches"
                                    :key="f.caisse.id"
                                    class="hover:bg-muted/30"
                                    data-testid="caisse-ligne"
                                >
                                    <td class="px-3 py-2 font-medium">
                                        {{ f.caisse.agent_nom ?? '—' }}
                                    </td>
                                    <td class="px-3 py-2">
                                        {{ f.caisse.site_nom ?? '—' }}
                                    </td>
                                    <td
                                        class="px-3 py-2 text-right tabular-nums"
                                    >
                                        {{ formatGNF(f.solde_debut) }}
                                    </td>
                                    <td
                                        class="px-3 py-2 text-right tabular-nums"
                                    >
                                        {{ formatGNF(f.total_entrees) }}
                                    </td>
                                    <td
                                        class="px-3 py-2 text-right tabular-nums"
                                    >
                                        {{ formatGNF(f.total_sorties) }}
                                    </td>
                                    <td
                                        class="px-3 py-2 text-right tabular-nums"
                                    >
                                        {{ formatGNF(f.solde_fin) }}
                                    </td>
                                    <td
                                        class="px-3 py-2 text-right font-semibold tabular-nums"
                                    >
                                        {{ formatGNF(f.solde_actuel) }}
                                        <div
                                            v-if="f.en_cours.montant > 0"
                                            class="text-xs font-medium text-blue-600 dark:text-blue-400"
                                        >
                                            En cours :
                                            {{ formatGNF(f.en_cours.montant) }}
                                        </div>
                                    </td>
                                    <td class="px-3 py-2 text-xs">
                                        <template v-if="f.dernier_versement">
                                            {{
                                                dateFr(
                                                    f.dernier_versement
                                                        .date_envoi,
                                                )
                                            }}
                                            <span class="text-muted-foreground">
                                                ({{
                                                    f.dernier_versement
                                                        .anciennete_jours
                                                }}
                                                j)</span
                                            >
                                        </template>
                                        <span
                                            v-else
                                            class="text-muted-foreground"
                                            >Aucun</span
                                        >
                                    </td>
                                </tr>
                            </tbody>
                            <tfoot>
                                <tr class="border-t bg-muted/20 font-semibold">
                                    <td colspan="2" class="px-3 py-2">Total</td>
                                    <td
                                        class="px-3 py-2 text-right tabular-nums"
                                    >
                                        {{
                                            formatGNF(
                                                rapport.caisse.resume
                                                    .solde_debut,
                                            )
                                        }}
                                    </td>
                                    <td
                                        class="px-3 py-2 text-right tabular-nums"
                                    >
                                        {{
                                            formatGNF(
                                                rapport.caisse.resume.entrees,
                                            )
                                        }}
                                    </td>
                                    <td
                                        class="px-3 py-2 text-right tabular-nums"
                                    >
                                        {{
                                            formatGNF(
                                                rapport.caisse.resume.sorties,
                                            )
                                        }}
                                    </td>
                                    <td
                                        class="px-3 py-2 text-right tabular-nums"
                                    >
                                        {{
                                            formatGNF(
                                                rapport.caisse.resume.solde_fin,
                                            )
                                        }}
                                    </td>
                                    <td
                                        class="px-3 py-2 text-right tabular-nums"
                                    >
                                        {{
                                            formatGNF(
                                                rapport.caisse.resume
                                                    .solde_actuel,
                                            )
                                        }}
                                    </td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                    <p
                        v-if="!rapport.caisse.detail"
                        class="text-xs text-muted-foreground"
                    >
                        Choisissez un agent pour voir le détail des écritures de
                        sa caisse.
                    </p>

                    <!-- Agent ciblé : fiche complète de chaque caisse -->
                    <template v-else>
                        <CaisseFiche
                            v-for="f in rapport.caisse.fiches"
                            :key="f.caisse.id"
                            :fiche="f"
                            :debut="periode.date_debut"
                            :fin="periode.date_fin"
                            :afficher-agent="!maSituation"
                        />
                    </template>
                </template>
            </section>
        </div>
    </AppLayout>
</template>
