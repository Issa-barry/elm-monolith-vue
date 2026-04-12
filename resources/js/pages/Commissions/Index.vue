<script setup lang="ts">
import StatusDot from '@/components/StatusDot.vue';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatPhoneDisplay } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import { ArrowLeft, ChevronRight, Search, Truck, User } from 'lucide-vue-next';
import Column from 'primevue/column';
import DataTable from 'primevue/datatable';
import IconField from 'primevue/iconfield';
import InputIcon from 'primevue/inputicon';
import InputText from 'primevue/inputtext';
import Select from 'primevue/select';
import { computed, ref } from 'vue';

// ── Types ─────────────────────────────────────────────────────────────────────

interface BeneficiaireRow {
    beneficiaire_id: number;
    type_beneficiaire: 'livreur' | 'proprietaire';
    beneficiaire_nom: string;
    telephone: string | null;
    total_brut_cumule: number;
    total_frais: number;
    total_net_cumule: number;
    total_verse: number;
    solde_restant: number;
    nb_commandes: number;
    date_derniere_commande: string | null;
    statut_global: 'en_attente' | 'partielle' | 'versee';
}

interface Totaux {
    nb_beneficiaires: number;
    total_brut: number;
    total_verse: number;
    solde_total: number;
    nb_en_attente: number;
    nb_partielle: number;
    nb_versee: number;
}

// ── Props ─────────────────────────────────────────────────────────────────────

const props = defineProps<{
    beneficiaires: BeneficiaireRow[];
    totaux: Totaux;
    periode: string;
    tab: 'livreurs' | 'proprietaires';
    filtre_statut: string;
    search: string;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/dashboard' },
    { title: 'Commissions', href: '/commissions' },
];

// ── Navigation (server-side) ──────────────────────────────────────────────────

function setTab(t: 'livreurs' | 'proprietaires') {
    const defaultPeriode = t === 'proprietaires' ? 'month' : 'week';
    router.get(
        '/commissions',
        { tab: t, periode: defaultPeriode },
        { preserveScroll: false, replace: true },
    );
}

function setPeriode(p: string) {
    router.get(
        '/commissions',
        { tab: props.tab, periode: p },
        { preserveScroll: true, replace: true },
    );
}

// ── Filtres locaux (client-side) ──────────────────────────────────────────────

const filtresStatut = [
    { value: 'all', label: 'Tous statuts' },
    { value: 'en_attente', label: 'En attente' },
    { value: 'partielle', label: 'Partielles' },
    { value: 'versee', label: 'Versées' },
];

const periodes = [
    { value: 'today', label: "Aujourd'hui" },
    { value: 'week', label: 'Cette semaine' },
    { value: 'month', label: 'Ce mois' },
    { value: 'all', label: 'Tout' },
];

const filtreStatut = ref(props.filtre_statut || 'all');
const search = ref(props.search ?? '');
const mobileSearch = ref('');

// ── Filtrage client ───────────────────────────────────────────────────────────

function filterList(list: BeneficiaireRow[], q: string): BeneficiaireRow[] {
    if (filtreStatut.value && filtreStatut.value !== 'all') {
        list = list.filter((b) => b.statut_global === filtreStatut.value);
    }
    const query = q.toLowerCase().trim();
    if (query) {
        list = list.filter(
            (b) =>
                b.beneficiaire_nom.toLowerCase().includes(query) ||
                (b.telephone && b.telephone.includes(query)),
        );
    }
    return list;
}

const listeFiltree = computed(() =>
    filterList([...props.beneficiaires], search.value),
);
const mobileFiltree = computed(() =>
    filterList([...props.beneficiaires], mobileSearch.value),
);

// ── KPI (depuis la liste filtrée) ────────────────────────────────────────────

const kpi = computed(() => {
    const list = listeFiltree.value;
    return {
        total_brut: list.reduce((s, b) => s + b.total_brut_cumule, 0),
        total_verse: list.reduce((s, b) => s + b.total_verse, 0),
        solde_total: list.reduce((s, b) => s + b.solde_restant, 0),
        nb_total: list.length,
        nb_en_attente: list.filter((b) => b.statut_global === 'en_attente')
            .length,
        nb_partielle: list.filter((b) => b.statut_global === 'partielle')
            .length,
    };
});

// ── Couleurs statut ───────────────────────────────────────────────────────────

const statutDotColor: Record<string, string> = {
    en_attente: 'bg-amber-500',
    partielle: 'bg-blue-500',
    versee: 'bg-emerald-500',
};

const statutLabel: Record<string, string> = {
    en_attente: 'En attente',
    partielle: 'Partielle',
    versee: 'Versée',
};

function formatGNF(val: number): string {
    return new Intl.NumberFormat('fr-FR').format(val) + ' GNF';
}

function detailUrl(b: BeneficiaireRow): string {
    return `/commissions/beneficiaires/${b.type_beneficiaire}/${b.beneficiaire_id}`;
}
</script>

<template>
    <Head title="Commissions" />

    <AppLayout :breadcrumbs="breadcrumbs" :hide-mobile-header="true">
        <!-- ══════════════════════ MOBILE ══════════════════════════════════════ -->
        <div class="flex flex-col sm:hidden">
            <!-- Sticky header -->
            <div class="sticky top-0 z-10 border-b bg-background">
                <div class="flex items-center justify-between px-4 py-3">
                    <Link
                        href="/dashboard"
                        class="flex h-8 w-8 items-center justify-center rounded-md text-muted-foreground"
                    >
                        <ArrowLeft class="h-5 w-5" />
                    </Link>
                    <span class="text-base font-semibold">Commissions</span>
                    <div class="w-8" />
                </div>

                <!-- Tabs mobile -->
                <div class="flex border-t">
                    <button
                        class="flex flex-1 items-center justify-center gap-1.5 py-2.5 text-xs font-semibold transition-colors"
                        :class="
                            tab === 'livreurs'
                                ? 'border-b-2 border-primary text-primary'
                                : 'border-b-2 border-transparent text-muted-foreground'
                        "
                        @click="setTab('livreurs')"
                    >
                        <Truck class="h-3.5 w-3.5" />
                        Livreurs
                    </button>
                    <button
                        class="flex flex-1 items-center justify-center gap-1.5 py-2.5 text-xs font-semibold transition-colors"
                        :class="
                            tab === 'proprietaires'
                                ? 'border-b-2 border-primary text-primary'
                                : 'border-b-2 border-transparent text-muted-foreground'
                        "
                        @click="setTab('proprietaires')"
                    >
                        <User class="h-3.5 w-3.5" />
                        Propriétaires
                    </button>
                </div>
            </div>

            <!-- KPI mobile — WIDGETS MASQUÉS TEMPORAIREMENT
            <div class="grid grid-cols-2 gap-3 p-4">
                <div class="rounded-xl border bg-card p-3 shadow-sm">
                    <p class="text-xs text-muted-foreground">Solde restant</p>
                    <p class="mt-1 text-base font-bold text-amber-600 tabular-nums dark:text-amber-400">
                        {{ formatGNF(kpi.solde_total) }}
                    </p>
                </div>
                <div class="rounded-xl border bg-card p-3 shadow-sm">
                    <p class="text-xs text-muted-foreground">Total versé</p>
                    <p class="mt-1 text-base font-bold text-emerald-600 tabular-nums dark:text-emerald-400">
                        {{ formatGNF(kpi.total_verse) }}
                    </p>
                </div>
            </div>
            -->

            <!-- Search mobile -->
            <div class="space-y-2 border-t px-4 py-3">
                <div class="relative">
                    <Search
                        class="pointer-events-none absolute top-1/2 left-2.5 h-4 w-4 -translate-y-1/2 text-muted-foreground"
                    />
                    <input
                        v-model="mobileSearch"
                        type="text"
                        placeholder="Nom, téléphone…"
                        class="h-9 w-full rounded-md border border-input bg-background pr-3 pl-8 text-sm placeholder:text-muted-foreground focus:ring-1 focus:ring-ring focus:outline-none"
                    />
                </div>
                <Select
                    :model-value="periode"
                    :options="periodes"
                    option-label="label"
                    option-value="value"
                    class="w-full"
                    @update:model-value="setPeriode($event)"
                />
            </div>

            <!-- Card list mobile -->
            <div class="divide-y">
                <Link
                    v-for="b in mobileFiltree"
                    :key="b.beneficiaire_id"
                    :href="detailUrl(b)"
                    class="flex items-start justify-between gap-3 px-4 py-3.5 transition-colors active:bg-muted/40"
                >
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-semibold">
                            {{ b.beneficiaire_nom }}
                        </p>
                        <p
                            v-if="b.telephone"
                            class="text-xs text-muted-foreground"
                        >
                            {{ formatPhoneDisplay(b.telephone) }}
                        </p>
                        <p class="mt-1 text-sm font-semibold tabular-nums">
                            Net : {{ formatGNF(b.total_net_cumule) }}
                        </p>
                        <p
                            class="mt-0.5 text-xs text-muted-foreground tabular-nums"
                        >
                            Brut : {{ formatGNF(b.total_brut_cumule) }}
                        </p>
                    </div>
                    <div class="flex shrink-0 flex-col items-end gap-2">
                        <StatusDot
                            :label="
                                statutLabel[b.statut_global] ?? b.statut_global
                            "
                            :dot-class="
                                statutDotColor[b.statut_global] ?? 'bg-zinc-400'
                            "
                            class="text-xs text-muted-foreground"
                        />
                        <span
                            class="text-xs text-muted-foreground tabular-nums"
                        >
                            {{ b.date_derniere_commande }}
                        </span>
                        <ChevronRight class="h-4 w-4 text-muted-foreground" />
                    </div>
                </Link>
            </div>

            <div
                v-if="mobileFiltree.length === 0"
                class="py-16 text-center text-sm text-muted-foreground"
            >
                Aucun bénéficiaire trouvé.
            </div>
        </div>

        <!-- ══════════════════════ DESKTOP ═════════════════════════════════════ -->
        <div class="hidden w-full space-y-6 p-6 sm:block">
            <!-- En-tête -->
            <div>
                <h1 class="text-2xl font-semibold tracking-tight">
                    Commissions — Grand Livre
                </h1>
                <p class="mt-1 text-sm text-muted-foreground">
                    Soldes cumulés par bénéficiaire
                </p>
            </div>

            <!-- KPI cards — WIDGETS MASQUÉS TEMPORAIREMENT
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div class="rounded-xl border bg-card p-5 shadow-sm">
                    <p class="text-sm text-muted-foreground">{{ tabLabel }}</p>
                    <p class="mt-2 text-2xl font-bold tabular-nums">{{ kpi.nb_total }}</p>
                    <p class="mt-0.5 text-xs text-muted-foreground">bénéficiaire{{ kpi.nb_total > 1 ? 's' : '' }}</p>
                </div>
                <div class="rounded-xl border bg-card p-5 shadow-sm">
                    <p class="text-sm text-muted-foreground">Total cumulé</p>
                    <p class="mt-2 text-2xl font-bold tabular-nums">{{ formatGNF(kpi.total_brut) }}</p>
                </div>
                <div class="rounded-xl border bg-card p-5 shadow-sm">
                    <p class="text-sm text-muted-foreground">Solde restant</p>
                    <p class="mt-2 text-2xl font-bold text-amber-600 tabular-nums dark:text-amber-400">
                        {{ formatGNF(kpi.solde_total) }}
                    </p>
                    <p class="mt-0.5 text-xs text-muted-foreground">
                        {{ kpi.nb_en_attente + kpi.nb_partielle }} non soldé{{ kpi.nb_en_attente + kpi.nb_partielle > 1 ? 's' : '' }}
                    </p>
                </div>
                <div class="rounded-xl border bg-card p-5 shadow-sm">
                    <p class="text-sm text-muted-foreground">Total versé</p>
                    <p class="mt-2 text-2xl font-bold text-emerald-600 tabular-nums dark:text-emerald-400">
                        {{ formatGNF(kpi.total_verse) }}
                    </p>
                </div>
            </div>
            -->

            <!-- Tabs -->
            <div class="flex justify-center">
                <div
                    class="inline-flex items-center gap-1 rounded-xl border bg-card p-1 shadow-sm"
                >
                    <button
                        class="flex items-center gap-2 rounded-lg px-4 py-2 text-sm font-medium transition-colors"
                        :class="
                            tab === 'livreurs'
                                ? 'bg-primary/10 text-primary'
                                : 'text-muted-foreground hover:bg-muted hover:text-foreground'
                        "
                        @click="setTab('livreurs')"
                    >
                        <Truck class="h-4 w-4" />
                        Livreurs
                        <span
                            class="rounded-full bg-muted px-1.5 py-0.5 text-[10px] font-medium tabular-nums"
                        >
                            {{ tab === 'livreurs' ? kpi.nb_total : '' }}
                        </span>
                    </button>
                    <button
                        class="flex items-center gap-2 rounded-lg px-4 py-2 text-sm font-medium transition-colors"
                        :class="
                            tab === 'proprietaires'
                                ? 'bg-primary/10 text-primary'
                                : 'text-muted-foreground hover:bg-muted hover:text-foreground'
                        "
                        @click="setTab('proprietaires')"
                    >
                        <User class="h-4 w-4" />
                        Propriétaires
                        <span
                            class="rounded-full bg-muted px-1.5 py-0.5 text-[10px] font-medium tabular-nums"
                        >
                            {{ tab === 'proprietaires' ? kpi.nb_total : '' }}
                        </span>
                    </button>
                </div>
            </div>

            <!-- Tableau Grand Livre -->
            <div class="overflow-hidden rounded-xl border bg-card">
                <DataTable
                    :value="listeFiltree"
                    :paginator="listeFiltree.length > 25"
                    :rows="25"
                    data-key="beneficiaire_id"
                    striped-rows
                    removable-sort
                    class="text-sm"
                    :pt="{
                        root: { class: 'w-full' },
                        header: { class: 'border-b bg-muted/30 px-4 py-3' },
                        tbody: { class: 'divide-y' },
                        table: {
                            style: 'table-layout: fixed; min-width: 760px',
                        },
                    }"
                >
                    <template #header>
                        <div class="flex items-center gap-3">
                            <IconField class="max-w-sm flex-1">
                                <InputIcon class="pointer-events-none">
                                    <Search
                                        class="h-4 w-4 text-muted-foreground"
                                    />
                                </InputIcon>
                                <InputText
                                    v-model="search"
                                    placeholder="Nom, téléphone…"
                                    class="w-full text-sm"
                                />
                            </IconField>
                            <Select
                                v-model="filtreStatut"
                                :options="filtresStatut"
                                option-label="label"
                                option-value="value"
                                class="w-40"
                            />
                            <Select
                                :model-value="periode"
                                :options="periodes"
                                option-label="label"
                                option-value="value"
                                class="w-44"
                                @update:model-value="setPeriode($event)"
                            />
                            <span class="text-xs text-muted-foreground">
                                {{ listeFiltree.length }} bénéficiaire{{
                                    listeFiltree.length !== 1 ? 's' : ''
                                }}
                            </span>
                        </div>
                    </template>

                    <!-- Bénéficiaire (34%) -->
                    <Column
                        field="beneficiaire_nom"
                        header="Bénéficiaire"
                        sortable
                        style="width: 34%"
                    >
                        <template #body="{ data }">
                            <p class="truncate font-semibold">
                                {{ data.beneficiaire_nom }}
                            </p>
                            <p
                                v-if="data.telephone"
                                class="mt-0.5 truncate text-xs text-muted-foreground"
                            >
                                {{ formatPhoneDisplay(data.telephone) }}
                            </p>
                        </template>
                    </Column>

                    <!-- Brut (15%) -->
                    <Column
                        field="total_brut_cumule"
                        header="Brut"
                        sortable
                        style="width: 15%"
                    >
                        <template #body="{ data }">
                            <span
                                class="font-semibold whitespace-nowrap tabular-nums"
                                >{{ formatGNF(data.total_brut_cumule) }}</span
                            >
                        </template>
                    </Column>

                    <!-- Frais (13%) -->
                    <Column
                        field="total_frais"
                        header="Frais"
                        sortable
                        style="width: 13%"
                    >
                        <template #body="{ data }">
                            <span
                                class="whitespace-nowrap tabular-nums"
                                :class="
                                    data.total_frais > 0
                                        ? 'text-destructive'
                                        : 'text-muted-foreground'
                                "
                            >
                                {{
                                    data.total_frais > 0
                                        ? '−\u202F' +
                                          formatGNF(data.total_frais)
                                        : '—'
                                }}
                            </span>
                        </template>
                    </Column>

                    <!-- Total net (15%) -->
                    <Column
                        field="total_net_cumule"
                        header="Total net"
                        sortable
                        style="width: 15%"
                    >
                        <template #body="{ data }">
                            <span
                                class="font-semibold whitespace-nowrap tabular-nums"
                                >{{ formatGNF(data.total_net_cumule) }}</span
                            >
                        </template>
                    </Column>

                    <!-- Statut (13%) -->
                    <Column
                        field="statut_global"
                        header="Statut"
                        sortable
                        style="width: 13%"
                    >
                        <template #body="{ data }">
                            <StatusDot
                                :label="
                                    statutLabel[data.statut_global] ??
                                    data.statut_global
                                "
                                :dot-class="
                                    statutDotColor[data.statut_global] ??
                                    'bg-zinc-400'
                                "
                                class="text-muted-foreground"
                            />
                        </template>
                    </Column>

                    <!-- Action (10%) -->
                    <Column header="" style="width: 10%">
                        <template #body="{ data }">
                            <div class="flex justify-end">
                                <Link :href="detailUrl(data)">
                                    <Button
                                        size="sm"
                                        variant="ghost"
                                        class="h-8 gap-1 text-xs"
                                    >
                                        Détails
                                        <ChevronRight class="h-3.5 w-3.5" />
                                    </Button>
                                </Link>
                            </div>
                        </template>
                    </Column>

                    <template #empty>
                        <div
                            class="py-16 text-center text-sm text-muted-foreground"
                        >
                            Aucun bénéficiaire trouvé pour cette période.
                        </div>
                    </template>
                </DataTable>
            </div>
        </div>
    </AppLayout>
</template>
