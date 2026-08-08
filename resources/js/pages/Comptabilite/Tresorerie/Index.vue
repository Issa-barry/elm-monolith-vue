<script setup lang="ts">
import DataFilters, {
    type FilterField,
} from '@/components/filters/DataFilters.vue';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { formatGNF } from '@/lib/utils';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import { CheckCircle2, CircleAlert, Info, Wallet } from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface Row {
    site_id: string | null;
    site_nom: string;
    livreurs_p1: number;
    livreurs_p1_du: number;
    livreurs_p2: number;
    livreurs_p2_du: number;
    proprietaires: number;
    proprietaires_du: number;
    salaires: number;
    salaires_du: number;
    total: number;
    total_du: number;
}

type Totaux = Omit<Row, 'site_id' | 'site_nom'>;

const props = defineProps<{
    rows: Row[];
    total_general: Totaux;
    filters: { annee: string; mois: string };
    is_admin: boolean;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/backoffice/dashboard' },
    { title: 'Comptabilité', href: '/backoffice/comptabilite' },
    { title: 'Besoin de trésorerie', href: '#' },
];

// ── Filtre Année / Mois — recharge serveur (comme le reste de l'app) ──────────

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

const moisLabel = computed(
    () => moisNoms[Number(props.filters.mois) - 1] ?? props.filters.mois,
);

const dernierJourDuMois = computed(
    () =>
        new Date(Number(props.filters.annee), Number(props.filters.mois), 0).getDate(),
);

// ── Onglet actif : "décaissement" réel, pas un simple filtre de données ───────
// Les 3 vues piochent dans les mêmes `rows` déjà chargées (P1/P2/propriétaires/
// salaires sont déjà séparés par BesoinTresorerieService) — changer d'onglet ne
// recharge donc jamais la page, contrairement au filtre Année/Mois ci-dessus.

type Echeance = 'p1' | 'p2' | 'mensuel';

const quinzaineDuJour: 'p1' | 'p2' = new Date().getDate() <= 15 ? 'p1' : 'p2';
const activeTab = ref<Echeance>(quinzaineDuJour);

const tabs: { value: Echeance; label: string }[] = [
    { value: 'p1', label: 'P1 — Envoi mi-mois' },
    { value: 'p2', label: 'P2 — Envoi fin de mois' },
    { value: 'mensuel', label: 'Vue mensuelle' },
];

const tabDescription = computed(() => {
    if (activeTab.value === 'p1') {
        return `Envoi du 15 ${moisLabel.value} — commissions livreurs de la première quinzaine (1 → 15) uniquement.`;
    }
    if (activeTab.value === 'p2') {
        return `Envoi de fin ${moisLabel.value} — commissions livreurs de la 2ᵉ quinzaine (16 → ${dernierJourDuMois.value}) + commissions propriétaires + salaires du mois.`;
    }
    return `Vue consolidée du mois — pour analyse uniquement, ne correspond à aucun envoi unique.`;
});

// ── Statut P1 : la carte doit dire si c'est déjà envoyé, pas juste "0" ────────
// livreurs_p1 = reste à payer, livreurs_p1_du = montant théorique total. Si du
// > 0 et reste = 0, la quinzaine a déjà été réglée (probablement via l'envoi
// du 15) — jamais réinjecté dans le total "à envoyer" de P2.

type StatutP1 = 'aucun_besoin' | 'paye' | 'a_envoyer' | 'partiel';

function statutP1(du: number, restant: number): StatutP1 {
    if (du <= 0) return 'aucun_besoin';
    if (restant <= 0) return 'paye';
    if (restant >= du) return 'a_envoyer';
    return 'partiel';
}

const statutP1Global = computed(() =>
    statutP1(props.total_general.livreurs_p1_du, props.total_general.livreurs_p1),
);

const carteP1 = computed(() => {
    const statut = statutP1Global.value;
    if (statut === 'aucun_besoin') {
        return { label: 'P1 — Aucun besoin', montant: 0, badge: null as null | { text: string; class: string } };
    }
    if (statut === 'paye') {
        return {
            label: 'P1 — Déjà envoyé',
            montant: props.total_general.livreurs_p1_du,
            badge: { text: 'Payé', class: 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300' },
        };
    }
    if (statut === 'partiel') {
        return {
            label: 'P1 — Reste à envoyer',
            montant: props.total_general.livreurs_p1,
            badge: { text: 'Partiel', class: 'bg-amber-50 text-amber-700 dark:bg-amber-950 dark:text-amber-300' },
        };
    }
    return {
        label: 'P1 — À envoyer',
        montant: props.total_general.livreurs_p1,
        badge: null,
    };
});

const totalP2 = computed(
    () =>
        props.total_general.livreurs_p2 +
        props.total_general.proprietaires +
        props.total_general.salaires,
);
const totalEngageMois = computed(() => props.total_general.total_du);

// Rappel affiché sur l'onglet P2 si P1 n'est pas soldé — jamais mélangé au
// total "à envoyer" de P2. Passé le 20, on le présente comme une anomalie à
// vérifier plutôt qu'un simple rappel neutre (P1 aurait dû être réglé).
const p1NonSolde = computed(
    () => statutP1Global.value === 'partiel' || statutP1Global.value === 'a_envoyer',
);
const p1EnAnomalie = computed(
    () => p1NonSolde.value && new Date().getDate() > 20,
);

// ── Totaux par ligne selon l'échéance affichée ────────────────────────────────
// (row.total est la somme des 4 postes du MOIS entier : jamais le bon total à
// utiliser pour un onglet P1 ou P2 pris isolément.)

function totalPourEcheance(row: Row, echeance: Echeance): number {
    if (echeance === 'p1') return row.livreurs_p1;
    if (echeance === 'p2') return row.livreurs_p2 + row.proprietaires + row.salaires;
    return row.total;
}

// ── Agences sans besoin — masquées par défaut sur l'onglet actif ─────────────

const afficherAgencesVides = ref(false);

const rowsAffichees = computed(() => {
    if (afficherAgencesVides.value) return props.rows;
    return props.rows.filter(
        (row) => totalPourEcheance(row, activeTab.value) > 0,
    );
});

function detailHref(row: Row): string {
    const site = row.site_id ?? 'sans-agence';
    return `/backoffice/comptabilite/tresorerie/${site}?annee=${props.filters.annee}&mois=${props.filters.mois}&echeance=${activeTab.value}`;
}

function goToDetail(row: Row) {
    router.visit(detailHref(row));
}
</script>

<template>
    <Head title="Besoin de trésorerie" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="w-full space-y-6 p-4 sm:p-6">
            <div class="flex flex-col gap-1">
                <h1 class="flex items-center gap-2 text-xl font-semibold">
                    <Wallet class="h-5 w-5 text-muted-foreground" />
                    Besoin de trésorerie
                </h1>
                <p class="text-sm text-muted-foreground">
                    Prévision des fonds à envoyer aux agences — ne reflète pas
                    encore un transfert de fonds réel, voir
                    <Link
                        href="/backoffice/comptabilite/journal"
                        class="underline underline-offset-2"
                        >le journal financier</Link
                    >
                    pour les mouvements déjà enregistrés.
                </p>
            </div>

            <DataFilters
                url="/backoffice/comptabilite/tresorerie"
                :values="filters"
                :fields="filterFields"
                :result-count="rows.length"
                hide-agence-selector
            />

            <!-- Cartes KPI -->
            <div class="grid gap-4 sm:grid-cols-3">
                <div class="rounded-xl border bg-card p-4">
                    <div class="flex items-center justify-between gap-2">
                        <p class="text-xs text-muted-foreground">
                            {{ carteP1.label }}
                        </p>
                        <span
                            v-if="carteP1.badge"
                            class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-medium"
                            :class="carteP1.badge.class"
                        >
                            {{ carteP1.badge.text }}
                        </span>
                    </div>
                    <p class="mt-1 text-xl font-bold tabular-nums">
                        {{ formatGNF(carteP1.montant) }}
                    </p>
                    <p class="mt-0.5 text-[11px] text-muted-foreground">
                        1 → 15 {{ moisLabel }}
                    </p>
                </div>
                <div class="rounded-xl border bg-card p-4">
                    <p class="text-xs text-muted-foreground">P2 — À envoyer</p>
                    <p class="mt-1 text-xl font-bold tabular-nums">
                        {{ formatGNF(totalP2) }}
                    </p>
                    <p class="mt-0.5 text-[11px] text-muted-foreground">
                        16 → {{ dernierJourDuMois }} {{ moisLabel }}
                    </p>
                </div>
                <div class="rounded-xl border bg-card p-4">
                    <p class="text-xs text-muted-foreground">
                        Total engagé ce mois
                    </p>
                    <p class="mt-1 text-xl font-bold tabular-nums">
                        {{ formatGNF(totalEngageMois) }}
                    </p>
                    <p class="mt-0.5 text-[11px] text-muted-foreground">
                        Informatif uniquement — jamais à décaisser en une fois
                    </p>
                </div>
            </div>

            <!-- Onglets décaissement -->
            <div class="flex flex-col gap-3">
                <div class="flex flex-wrap items-center gap-2">
                    <button
                        v-for="tab in tabs"
                        :key="tab.value"
                        type="button"
                        class="rounded-full border px-4 py-1.5 text-sm font-medium transition-colors"
                        :class="
                            activeTab === tab.value
                                ? 'border-primary bg-primary text-primary-foreground'
                                : 'border-input bg-background text-muted-foreground hover:bg-muted'
                        "
                        @click="activeTab = tab.value"
                    >
                        {{ tab.label }}
                    </button>
                </div>
                <p class="flex items-start gap-1.5 text-xs text-muted-foreground">
                    <Info class="mt-0.5 h-3.5 w-3.5 shrink-0" />
                    {{ tabDescription }}
                </p>
            </div>

            <!-- Rappel P1 non soldé — visible seulement sur l'onglet P2, jamais compté dans son total -->
            <div
                v-if="activeTab === 'p2' && p1NonSolde"
                class="flex items-start gap-2 rounded-lg border px-4 py-3 text-sm"
                :class="
                    p1EnAnomalie
                        ? 'border-red-200 bg-red-50 text-red-800 dark:border-red-800 dark:bg-red-950/30 dark:text-red-300'
                        : 'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-800 dark:bg-amber-950/30 dark:text-amber-300'
                "
            >
                <CircleAlert class="mt-0.5 h-4 w-4 shrink-0" />
                <span>
                    {{
                        p1EnAnomalie
                            ? 'Reste P1 en anomalie : '
                            : 'Reste des commissions P1 pas encore envoyées : '
                    }}
                    <strong class="tabular-nums">{{
                        formatGNF(total_general.livreurs_p1)
                    }}</strong>
                    — non inclus dans le total P2 ci-dessous.
                    <Link
                        href="#"
                        class="underline underline-offset-2"
                        @click.prevent="activeTab = 'p1'"
                        >Voir l'onglet P1</Link
                    >
                </span>
            </div>

            <div class="flex items-center gap-2">
                <Checkbox
                    id="afficher-agences-vides"
                    :model-value="afficherAgencesVides"
                    @update:model-value="
                        afficherAgencesVides = $event === true
                    "
                />
                <Label
                    for="afficher-agences-vides"
                    class="cursor-pointer text-sm font-normal text-muted-foreground"
                >
                    Afficher les agences sans besoin
                </Label>
            </div>

            <!-- Tableau P1 : une seule colonne de montant + statut -->
            <div
                v-if="activeTab === 'p1'"
                class="overflow-x-auto rounded-xl border bg-card"
            >
                <table class="w-full min-w-[560px] text-sm">
                    <thead>
                        <tr class="border-b bg-muted/40 text-left">
                            <th class="px-4 py-3 font-medium">Agence</th>
                            <th class="px-4 py-3 text-right font-medium">
                                Commissions livreurs
                            </th>
                            <th class="px-4 py-3 text-left font-medium">
                                Statut
                            </th>
                            <th
                                class="px-4 py-3 text-right font-semibold text-foreground"
                            >
                                Total à envoyer
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr
                            v-for="row in rowsAffichees"
                            :key="row.site_id ?? 'sans-agence'"
                            class="cursor-pointer hover:bg-muted/30"
                            @click="goToDetail(row)"
                        >
                            <td class="px-4 py-3 font-medium">
                                <Link
                                    :href="detailHref(row)"
                                    class="hover:underline"
                                    @click.stop
                                >
                                    {{ row.site_nom }}
                                </Link>
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums">
                                {{ formatGNF(row.livreurs_p1_du) }}
                            </td>
                            <td class="px-4 py-3">
                                <span
                                    v-if="statutP1(row.livreurs_p1_du, row.livreurs_p1) === 'paye'"
                                    class="inline-flex items-center gap-1 text-xs font-medium text-emerald-700 dark:text-emerald-400"
                                >
                                    <CheckCircle2 class="h-3.5 w-3.5" />
                                    Payé
                                </span>
                                <span
                                    v-else-if="statutP1(row.livreurs_p1_du, row.livreurs_p1) === 'partiel'"
                                    class="text-xs font-medium text-amber-700 dark:text-amber-400"
                                >
                                    Partiel
                                </span>
                                <span
                                    v-else
                                    class="text-xs text-muted-foreground"
                                >
                                    À envoyer
                                </span>
                            </td>
                            <td
                                class="px-4 py-3 text-right font-semibold tabular-nums"
                            >
                                {{ formatGNF(row.livreurs_p1) }}
                            </td>
                        </tr>
                        <tr v-if="rowsAffichees.length === 0">
                            <td
                                colspan="4"
                                class="px-4 py-10 text-center text-muted-foreground"
                            >
                                Aucun besoin pour ce décaissement.
                            </td>
                        </tr>
                    </tbody>
                    <tfoot v-if="rowsAffichees.length > 0">
                        <tr class="border-t bg-muted/30 font-semibold">
                            <td class="px-4 py-3">Total</td>
                            <td class="px-4 py-3 text-right tabular-nums">
                                {{ formatGNF(total_general.livreurs_p1_du) }}
                            </td>
                            <td class="px-4 py-3"></td>
                            <td class="px-4 py-3 text-right tabular-nums">
                                {{ formatGNF(total_general.livreurs_p1) }}
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <!-- Tableau P2 : livreurs P2 + propriétaires + salaires (jamais P1) -->
            <div
                v-else-if="activeTab === 'p2'"
                class="overflow-x-auto rounded-xl border bg-card"
            >
                <table class="w-full min-w-[720px] text-sm">
                    <thead>
                        <tr class="border-b bg-muted/40 text-left">
                            <th class="px-4 py-3 font-medium">Agence</th>
                            <th class="px-4 py-3 text-right font-medium">
                                Livreurs P2
                            </th>
                            <th class="px-4 py-3 text-right font-medium">
                                Propriétaires
                            </th>
                            <th class="px-4 py-3 text-right font-medium">
                                Salaires
                            </th>
                            <th
                                class="px-4 py-3 text-right font-semibold text-foreground"
                            >
                                Total à envoyer
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr
                            v-for="row in rowsAffichees"
                            :key="row.site_id ?? 'sans-agence'"
                            class="cursor-pointer hover:bg-muted/30"
                            @click="goToDetail(row)"
                        >
                            <td class="px-4 py-3 font-medium">
                                <Link
                                    :href="detailHref(row)"
                                    class="hover:underline"
                                    @click.stop
                                >
                                    {{ row.site_nom }}
                                </Link>
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums">
                                {{ formatGNF(row.livreurs_p2) }}
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums">
                                {{ formatGNF(row.proprietaires) }}
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums">
                                {{ formatGNF(row.salaires) }}
                            </td>
                            <td
                                class="px-4 py-3 text-right font-semibold tabular-nums"
                            >
                                {{ formatGNF(totalPourEcheance(row, 'p2')) }}
                            </td>
                        </tr>
                        <tr v-if="rowsAffichees.length === 0">
                            <td
                                colspan="5"
                                class="px-4 py-10 text-center text-muted-foreground"
                            >
                                Aucun besoin pour ce décaissement.
                            </td>
                        </tr>
                    </tbody>
                    <tfoot v-if="rowsAffichees.length > 0">
                        <tr class="border-t bg-muted/30 font-semibold">
                            <td class="px-4 py-3">Total</td>
                            <td class="px-4 py-3 text-right tabular-nums">
                                {{ formatGNF(total_general.livreurs_p2) }}
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums">
                                {{ formatGNF(total_general.proprietaires) }}
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums">
                                {{ formatGNF(total_general.salaires) }}
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums">
                                {{ formatGNF(totalP2) }}
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <!-- Vue mensuelle : les 4 postes côte à côte, pour analyse -->
            <div v-else class="space-y-3">
                <p
                    class="flex items-start gap-1.5 rounded-lg border border-muted bg-muted/30 px-4 py-2.5 text-xs text-muted-foreground"
                >
                    <Info class="mt-0.5 h-3.5 w-3.5 shrink-0" />
                    Vue d'analyse : le total ci-dessous additionne les 4
                    postes du mois (y compris un reste P1 non encore envoyé,
                    le cas échéant). Ce n'est jamais le montant d'un seul
                    décaissement — utilisez les onglets P1/P2 pour préparer un
                    envoi.
                </p>
                <div class="overflow-x-auto rounded-xl border bg-card">
                    <table class="w-full min-w-[860px] text-sm">
                        <thead>
                            <tr class="border-b bg-muted/40 text-left">
                                <th class="px-4 py-3 font-medium">Agence</th>
                                <th class="px-4 py-3 text-right font-medium">
                                    Livreurs P1
                                </th>
                                <th class="px-4 py-3 text-right font-medium">
                                    Livreurs P2
                                </th>
                                <th class="px-4 py-3 text-right font-medium">
                                    Propriétaires
                                </th>
                                <th class="px-4 py-3 text-right font-medium">
                                    Salaires
                                </th>
                                <th
                                    class="px-4 py-3 text-right font-semibold text-foreground"
                                >
                                    Total du mois
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr
                                v-for="row in rowsAffichees"
                                :key="row.site_id ?? 'sans-agence'"
                                class="cursor-pointer hover:bg-muted/30"
                                @click="goToDetail(row)"
                            >
                                <td class="px-4 py-3 font-medium">
                                    <Link
                                        :href="detailHref(row)"
                                        class="hover:underline"
                                        @click.stop
                                    >
                                        {{ row.site_nom }}
                                    </Link>
                                </td>
                                <td class="px-4 py-3 text-right tabular-nums">
                                    {{ formatGNF(row.livreurs_p1) }}
                                </td>
                                <td class="px-4 py-3 text-right tabular-nums">
                                    {{ formatGNF(row.livreurs_p2) }}
                                </td>
                                <td class="px-4 py-3 text-right tabular-nums">
                                    {{ formatGNF(row.proprietaires) }}
                                </td>
                                <td class="px-4 py-3 text-right tabular-nums">
                                    {{ formatGNF(row.salaires) }}
                                </td>
                                <td
                                    class="px-4 py-3 text-right font-semibold tabular-nums"
                                >
                                    {{ formatGNF(row.total) }}
                                </td>
                            </tr>
                            <tr v-if="rowsAffichees.length === 0">
                                <td
                                    colspan="6"
                                    class="px-4 py-10 text-center text-muted-foreground"
                                >
                                    Aucun besoin de trésorerie pour cette
                                    période.
                                </td>
                            </tr>
                        </tbody>
                        <tfoot v-if="rowsAffichees.length > 0">
                            <tr class="border-t bg-muted/30 font-semibold">
                                <td class="px-4 py-3">Total général</td>
                                <td class="px-4 py-3 text-right tabular-nums">
                                    {{ formatGNF(total_general.livreurs_p1) }}
                                </td>
                                <td class="px-4 py-3 text-right tabular-nums">
                                    {{ formatGNF(total_general.livreurs_p2) }}
                                </td>
                                <td class="px-4 py-3 text-right tabular-nums">
                                    {{ formatGNF(total_general.proprietaires) }}
                                </td>
                                <td class="px-4 py-3 text-right tabular-nums">
                                    {{ formatGNF(total_general.salaires) }}
                                </td>
                                <td class="px-4 py-3 text-right tabular-nums">
                                    {{ formatGNF(total_general.total) }}
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
