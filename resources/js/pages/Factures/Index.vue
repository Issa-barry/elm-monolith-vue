<script setup lang="ts">
import StatusDot from '@/components/StatusDot.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { usePermissions } from '@/composables/usePermissions';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    ArrowLeft,
    CreditCard,
    Search,
} from 'lucide-vue-next';
import Dialog from 'primevue/dialog';
import Dropdown from 'primevue/dropdown';
import InputNumber from 'primevue/inputnumber';
import InputText from 'primevue/inputtext';
import { computed, ref } from 'vue';

// ── Types ─────────────────────────────────────────────────────────────────────
interface FactureItem {
    id: number;
    reference: string;
    commande_id: number;
    commande_reference: string | null;
    vehicule_nom: string | null;
    client_nom: string | null;
    site_nom: string | null;
    montant_net: number;
    montant_encaisse: number;
    montant_restant: number;
    statut_facture: string;
    statut_label: string;
    is_annulee: boolean;
    is_payee: boolean;
    created_at: string;
}

interface Totaux {
    total_a_encaisser: number;
    nb_impayees: number;
    montant_impayees: number;
    nb_partielles: number;
    montant_partielles: number;
    nb_payees: number;
    montant_payees: number;
}

interface ModePaiementOption {
    value: string;
    label: string;
}

// ── Props ─────────────────────────────────────────────────────────────────────
const props = defineProps<{
    factures: FactureItem[];
    totaux: Totaux;
    modes_paiement: ModePaiementOption[];
    periode: string;
    statut: string;
}>();

const { can } = usePermissions();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/dashboard' },
    { title: 'Factures', href: '/factures' },
];

// ── Filtre par période ───────────────────────────────────────────────────────
const periodes = [
    { value: 'today', label: "Aujourd'hui" },
    { value: 'week', label: 'Cette semaine' },
    { value: 'month', label: 'Ce mois' },
    { value: 'all', label: 'Tout' },
];

function setPeriode(p: string) {
    const params: Record<string, string> = { periode: p };
    if (props.statut !== 'tous') params.statut = props.statut;
    router.get('/factures', params, { preserveScroll: true, replace: true });
}

// ── Filtre par statut (server-driven) ────────────────────────────────────────
const filtres = [
    { value: 'tous', label: 'Toutes' },
    { value: 'impayee', label: 'Impayées' },
    { value: 'partiel', label: 'Partielles' },
    { value: 'payee', label: 'Payées' },
    { value: 'annulee', label: 'Annulées' },
];

function setStatut(s: string) {
    const params: Record<string, string> = { periode: props.periode };
    if (s !== 'tous') params.statut = s;
    router.get('/factures', params, { preserveScroll: true, replace: true });
}

// ── Recherche locale ──────────────────────────────────────────────────────────
const search = ref('');

// Applique uniquement la recherche (statut déjà filtré côté serveur)
const facturesFiltrees = computed(() => {
    const q = search.value.toLowerCase().trim();
    if (!q) return props.factures;
    return props.factures.filter(
        (f) =>
            f.reference.toLowerCase().includes(q) ||
            (f.vehicule_nom && f.vehicule_nom.toLowerCase().includes(q)) ||
            (f.client_nom && f.client_nom.toLowerCase().includes(q)) ||
            (f.site_nom && f.site_nom.toLowerCase().includes(q)),
    );
});

// Totaux recalculés depuis le dataset filtré (inclut la recherche locale)
const totauxFiltres = computed(() => {
    const list = facturesFiltrees.value;
    const impayees = list.filter((f) => f.statut_facture === 'impayee');
    const partielles = list.filter((f) => f.statut_facture === 'partiel');
    const payees = list.filter((f) => f.statut_facture === 'payee');
    return {
        total_a_encaisser: list
            .filter((f) => !['payee', 'annulee'].includes(f.statut_facture))
            .reduce((sum, f) => sum + f.montant_restant, 0),
        nb_impayees: impayees.length,
        montant_impayees: impayees.reduce((s, f) => s + f.montant_restant, 0),
        nb_partielles: partielles.length,
        montant_partielles: partielles.reduce(
            (s, f) => s + f.montant_restant,
            0,
        ),
        nb_payees: payees.length,
        montant_payees: payees.reduce((s, f) => s + f.montant_net, 0),
    };
});

// ── Couleurs statut ───────────────────────────────────────────────────────────
const statutColor: Record<string, string> = {
    impayee: 'bg-amber-500',
    partiel: 'bg-blue-500',
    payee: 'bg-emerald-500',
    annulee: 'bg-zinc-400 dark:bg-zinc-500',
};

// ── Formatage ─────────────────────────────────────────────────────────────────
function formatGNF(val: number): string {
    return new Intl.NumberFormat('fr-FR').format(val) + ' GNF';
}

function formatCompact(val: number): string {
    if (val >= 1_000_000) {
        const n = val / 1_000_000;
        return (Number.isInteger(n) ? n : n.toFixed(1)) + 'M GNF';
    }
    if (val >= 1_000) {
        const n = val / 1_000;
        return (Number.isInteger(n) ? n : n.toFixed(1)) + 'K GNF';
    }
    return val + ' GNF';
}

// ── Filtre mobile ─────────────────────────────────────────────────────────────
const mobileSearch = ref('');

const mobileFiltered = computed(() => {
    const q = mobileSearch.value.toLowerCase().trim();
    if (!q) return props.factures;
    return props.factures.filter(
        (f) =>
            f.reference.toLowerCase().includes(q) ||
            (f.vehicule_nom && f.vehicule_nom.toLowerCase().includes(q)) ||
            (f.client_nom && f.client_nom.toLowerCase().includes(q)) ||
            (f.site_nom && f.site_nom.toLowerCase().includes(q)),
    );
});

// ── Dialog encaissement ───────────────────────────────────────────────────────
const dialogVisible = ref(false);
const factureActive = ref<FactureItem | null>(null);

const encaissementForm = useForm({
    montant: 0 as number | null,
    date_encaissement: new Date().toISOString().slice(0, 10),
    mode_paiement: 'especes',
    note: null as string | null,
});

function openDialog(facture: FactureItem) {
    factureActive.value = facture;
    encaissementForm.montant = facture.montant_restant;
    encaissementForm.date_encaissement = new Date().toISOString().slice(0, 10);
    encaissementForm.mode_paiement = 'especes';
    encaissementForm.note = null;
    encaissementForm.clearErrors();
    dialogVisible.value = true;
}

function submitEncaissement() {
    if (!factureActive.value) return;
    encaissementForm.post(`/factures/${factureActive.value.id}/encaissements`, {
        onSuccess: () => {
            dialogVisible.value = false;
        },
    });
}

// ── Progression ───────────────────────────────────────────────────────────────
function _progressPercent(f: FactureItem): number {
    if (f.montant_net <= 0) return 0;
    return Math.min(
        100,
        Math.round((f.montant_encaisse / f.montant_net) * 100),
    );
}
</script>

<template>
    <Head title="Factures de vente" />

    <AppLayout :breadcrumbs="breadcrumbs" :hide-mobile-header="true">
        <!-- ── MOBILE VIEW ─────────────────────────────────────────────────── -->
        <div class="flex flex-col sm:hidden">
            <!-- Sticky header -->
            <div
                class="sticky top-0 z-10 flex items-center justify-between border-b bg-background px-4 py-3"
            >
                <Link
                    href="/dashboard"
                    class="flex h-8 w-8 items-center justify-center rounded-md text-muted-foreground hover:text-foreground"
                >
                    <ArrowLeft class="h-5 w-5" />
                </Link>
                <span class="text-base font-semibold">Factures</span>
                <div class="w-8" />
            </div>

            <!-- KPI cards -->
            <div class="grid grid-cols-2 gap-3 p-4">
                <div class="rounded-xl border bg-card p-4 shadow-sm">
                    <p class="text-xs text-muted-foreground">
                        Restant à encaisser
                    </p>
                    <p
                        class="mt-1 text-lg font-bold text-foreground tabular-nums"
                    >
                        {{ formatCompact(totauxFiltres.total_a_encaisser) }}
                    </p>
                </div>
                <div class="rounded-xl border bg-card p-4 shadow-sm">
                    <p class="text-xs text-muted-foreground">Impayées</p>
                    <p
                        class="mt-1 text-lg font-bold text-foreground tabular-nums"
                    >
                        {{ formatCompact(totauxFiltres.montant_impayees) }}
                    </p>
                    <p class="text-xs text-muted-foreground">
                        {{ totauxFiltres.nb_impayees }} facture{{
                            totauxFiltres.nb_impayees > 1 ? 's' : ''
                        }}
                    </p>
                </div>
                <div class="rounded-xl border bg-card p-4 shadow-sm">
                    <p class="text-xs text-muted-foreground">Partielles</p>
                    <p
                        class="mt-1 text-lg font-bold text-foreground tabular-nums"
                    >
                        {{ formatCompact(totauxFiltres.montant_partielles) }}
                    </p>
                    <p class="text-xs text-muted-foreground">
                        {{ totauxFiltres.nb_partielles }} facture{{
                            totauxFiltres.nb_partielles > 1 ? 's' : ''
                        }}
                    </p>
                </div>
                <div class="rounded-xl border bg-card p-4 shadow-sm">
                    <p class="text-xs text-muted-foreground">Soldées</p>
                    <p
                        class="mt-1 text-lg font-bold text-emerald-600 tabular-nums dark:text-emerald-400"
                    >
                        {{ formatCompact(totauxFiltres.montant_payees) }}
                    </p>
                    <p class="text-xs text-muted-foreground">
                        {{ totauxFiltres.nb_payees }} facture{{
                            totauxFiltres.nb_payees > 1 ? 's' : ''
                        }}
                    </p>
                </div>
            </div>

            <!-- Search -->
            <div class="border-t border-b px-4 py-2">
                <div class="relative">
                    <Search
                        class="pointer-events-none absolute top-1/2 left-2.5 h-4 w-4 -translate-y-1/2 text-muted-foreground"
                    />
                    <input
                        v-model="mobileSearch"
                        type="text"
                        placeholder="Référence, véhicule, client…"
                        class="h-9 w-full rounded-md border border-input bg-background pr-3 pl-8 text-sm placeholder:text-muted-foreground focus:ring-1 focus:ring-ring focus:outline-none"
                    />
                </div>
            </div>

            <!-- Card list -->
            <div class="divide-y">
                <div
                    v-for="f in mobileFiltered"
                    :key="f.id"
                    class="flex items-start justify-between gap-3 px-4 py-3"
                >
                    <div class="min-w-0 flex-1">
                        <p
                            class="font-mono text-sm font-semibold tracking-wide"
                        >
                            {{ f.reference }}
                        </p>
                        <p class="mt-0.5 text-xs text-muted-foreground">
                            {{ f.vehicule_nom ?? f.client_nom ?? '—' }}
                        </p>
                        <p class="mt-1 text-sm font-medium tabular-nums">
                            {{ formatGNF(f.montant_net) }}
                        </p>
                        <p
                            v-if="f.montant_restant > 0"
                            class="text-xs font-semibold text-amber-600 tabular-nums dark:text-amber-400"
                        >
                            Restant : {{ formatGNF(f.montant_restant) }}
                        </p>
                    </div>
                    <div class="flex shrink-0 flex-col items-end gap-2">
                        <StatusDot
                            :label="f.statut_label"
                            :dot-class="
                                statutColor[f.statut_facture] ??
                                'bg-zinc-400 dark:bg-zinc-500'
                            "
                            class="text-xs text-muted-foreground"
                        />
                        <span
                            class="text-xs text-muted-foreground tabular-nums"
                            >{{ f.created_at }}</span
                        >
                        <Button
                            v-if="
                                !f.is_annulee &&
                                !f.is_payee &&
                                can('ventes.update')
                            "
                            size="sm"
                            variant="outline"
                            class="h-7 border-emerald-300 text-xs text-emerald-700 hover:bg-emerald-50 dark:border-emerald-700 dark:text-emerald-400 dark:hover:bg-emerald-950"
                            @click="openDialog(f)"
                        >
                            <CreditCard class="mr-1 h-3.5 w-3.5" />
                            Encaisser
                        </Button>
                    </div>
                </div>
            </div>

            <!-- Empty state -->
            <div
                v-if="mobileFiltered.length === 0"
                class="py-16 text-center text-sm text-muted-foreground"
            >
                Aucune facture trouvée.
            </div>
        </div>

        <!-- ── DESKTOP VIEW ────────────────────────────────────────────────── -->
        <div class="hidden w-full space-y-6 p-4 sm:block sm:p-6">
            <!-- En-tête -->
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-semibold tracking-tight">
                        Factures de vente
                    </h1>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Suivi et encaissement des factures.
                    </p>
                </div>
            </div>

            <!-- Cartes de synthèse -->
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div class="rounded-xl border bg-card p-5 shadow-sm">
                    <p class="text-sm text-muted-foreground">
                        Restant à encaisser
                    </p>
                    <p
                        class="mt-2 text-2xl font-bold text-foreground tabular-nums"
                    >
                        {{ formatCompact(totauxFiltres.total_a_encaisser) }}
                    </p>
                </div>

                <div class="rounded-xl border bg-card p-5 shadow-sm">
                    <p class="text-sm text-muted-foreground">Impayées</p>
                    <p
                        class="mt-2 text-2xl font-bold text-foreground tabular-nums"
                    >
                        {{ formatCompact(totauxFiltres.montant_impayees) }}
                    </p>
                    <p class="mt-0.5 text-xs text-muted-foreground">
                        {{ totauxFiltres.nb_impayees }} facture{{
                            totauxFiltres.nb_impayees > 1 ? 's' : ''
                        }}
                    </p>
                </div>

                <div class="rounded-xl border bg-card p-5 shadow-sm">
                    <p class="text-sm text-muted-foreground">
                        Partiellement payées
                    </p>
                    <p
                        class="mt-2 text-2xl font-bold text-foreground tabular-nums"
                    >
                        {{ formatCompact(totauxFiltres.montant_partielles) }}
                    </p>
                    <p class="mt-0.5 text-xs text-muted-foreground">
                        {{ totauxFiltres.nb_partielles }} facture{{
                            totauxFiltres.nb_partielles > 1 ? 's' : ''
                        }}
                    </p>
                </div>

                <div class="rounded-xl border bg-card p-5 shadow-sm">
                    <p class="text-sm text-muted-foreground">Soldées</p>
                    <p
                        class="mt-2 text-2xl font-bold text-emerald-600 tabular-nums dark:text-emerald-400"
                    >
                        {{ formatCompact(totauxFiltres.montant_payees) }}
                    </p>
                    <p class="mt-0.5 text-xs text-muted-foreground">
                        {{ totauxFiltres.nb_payees }} facture{{
                            totauxFiltres.nb_payees > 1 ? 's' : ''
                        }}
                    </p>
                </div>
            </div>

            <!-- Filtres statut + période -->
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="flex flex-wrap items-center gap-2">
                    <!-- Recherche -->
                    <div class="relative">
                        <svg
                            class="pointer-events-none absolute top-1/2 left-2.5 h-4 w-4 -translate-y-1/2 text-muted-foreground"
                            xmlns="http://www.w3.org/2000/svg"
                            fill="none"
                            viewBox="0 0 24 24"
                            stroke-width="2"
                            stroke="currentColor"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                d="m21 21-4.35-4.35M17 11A6 6 0 1 1 5 11a6 6 0 0 1 12 0Z"
                            />
                        </svg>
                        <input
                            v-model="search"
                            type="text"
                            placeholder="Référence, véhicule, client…"
                            class="h-9 w-64 rounded-md border border-input bg-background pr-3 pl-8 text-sm shadow-sm placeholder:text-muted-foreground focus:ring-1 focus:ring-ring focus:outline-none"
                        />
                    </div>

                    <!-- Statut dropdown -->
                    <Dropdown
                        :model-value="statut"
                        :options="filtres"
                        option-label="label"
                        option-value="value"
                        @update:model-value="setStatut($event)"
                        class="w-36"
                    />

                    <!-- Période dropdown -->
                    <Dropdown
                        :model-value="periode"
                        :options="periodes"
                        option-label="label"
                        option-value="value"
                        @update:model-value="setPeriode($event)"
                        class="w-40"
                    />
                </div>
            </div>

            <!-- Tableau -->
            <div class="rounded-xl border bg-card">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b bg-muted/40">
                                <th
                                    class="px-4 py-3 text-left font-medium text-muted-foreground"
                                >
                                    Référence
                                </th>
                                <th
                                    class="px-4 py-3 text-left font-medium text-muted-foreground"
                                >
                                    Véhicule / Client
                                </th>
                                <th
                                    class="px-4 py-3 text-left font-medium text-muted-foreground"
                                >
                                    Site
                                </th>
                                <th
                                    class="px-4 py-3 text-right font-medium text-muted-foreground"
                                >
                                    Montant
                                </th>
                                <th
                                    class="px-4 py-3 text-right font-medium text-muted-foreground"
                                >
                                    Encaissé
                                </th>
                                <th
                                    class="px-4 py-3 text-right font-medium text-muted-foreground"
                                >
                                    Restant
                                </th>
                                <th
                                    class="px-4 py-3 text-center font-medium text-muted-foreground"
                                >
                                    Statut
                                </th>
                                <th
                                    class="px-4 py-3 text-left font-medium text-muted-foreground"
                                >
                                    Date
                                </th>
                                <th
                                    class="px-4 py-3"
                                    v-if="can('ventes.update')"
                                ></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr
                                v-for="f in facturesFiltrees"
                                :key="f.id"
                                class="transition-colors hover:bg-muted/10"
                            >
                                <!-- Référence -->
                                <td class="px-4 py-3">
                                    <span
                                        class="font-mono text-xs font-semibold"
                                        >{{ f.reference }}</span
                                    >
                                </td>

                                <!-- Véhicule / Client -->
                                <td class="px-4 py-3">
                                    <div
                                        v-if="f.vehicule_nom"
                                        class="font-medium"
                                    >
                                        {{ f.vehicule_nom }}
                                    </div>
                                    <div
                                        v-if="f.client_nom"
                                        class="text-muted-foreground"
                                        :class="{ 'text-xs': f.vehicule_nom }"
                                    >
                                        {{ f.client_nom }}
                                    </div>
                                    <span
                                        v-if="!f.vehicule_nom && !f.client_nom"
                                        class="text-muted-foreground"
                                        >—</span
                                    >
                                </td>

                                <!-- Site -->
                                <td class="px-4 py-3 text-muted-foreground">
                                    {{ f.site_nom ?? '—' }}
                                </td>

                                <!-- Montant -->
                                <td
                                    class="px-4 py-3 text-right font-medium tabular-nums"
                                >
                                    {{ formatGNF(f.montant_net) }}
                                </td>

                                <!-- Encaissé -->
                                <td
                                    class="px-4 py-3 text-right text-emerald-600 tabular-nums dark:text-emerald-400"
                                >
                                    {{ formatGNF(f.montant_encaisse) }}
                                </td>

                                <!-- Restant -->
                                <td
                                    class="px-4 py-3 text-right tabular-nums"
                                    :class="
                                        f.montant_restant > 0
                                            ? 'font-semibold text-amber-600 dark:text-amber-400'
                                            : 'text-muted-foreground'
                                    "
                                >
                                    {{
                                        f.montant_restant > 0
                                            ? formatGNF(f.montant_restant)
                                            : '—'
                                    }}
                                </td>

                                <!-- Statut -->
                                <td class="px-4 py-3 text-center">
                                    <StatusDot
                                        :label="f.statut_label"
                                        :dot-class="
                                            statutColor[f.statut_facture] ??
                                            'bg-zinc-400 dark:bg-zinc-500'
                                        "
                                        class="text-muted-foreground"
                                    />
                                </td>

                                <!-- Date -->
                                <td
                                    class="px-4 py-3 text-xs text-muted-foreground tabular-nums"
                                >
                                    {{ f.created_at }}
                                </td>

                                <!-- Action -->
                                <td
                                    v-if="can('ventes.update')"
                                    class="px-4 py-3 text-right"
                                >
                                    <Button
                                        v-if="!f.is_annulee && !f.is_payee"
                                        size="sm"
                                        variant="outline"
                                        class="h-7 border-emerald-300 text-xs text-emerald-700 hover:bg-emerald-50 dark:border-emerald-700 dark:text-emerald-400 dark:hover:bg-emerald-950"
                                        @click="openDialog(f)"
                                    >
                                        <CreditCard
                                            class="mr-1.5 h-3.5 w-3.5"
                                        />
                                        Encaisser
                                    </Button>
                                    <span
                                        v-else-if="f.is_payee"
                                        class="text-xs font-medium text-emerald-600 dark:text-emerald-400"
                                    >
                                        Soldée ✓
                                    </span>
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <div
                        v-if="facturesFiltrees.length === 0"
                        class="py-16 text-center text-sm text-muted-foreground"
                    >
                        Aucune facture trouvée.
                    </div>
                </div>
            </div>
        </div>

        <!-- Dialog encaissement ─────────────────────────────────────────────── -->
        <Dialog
            v-model:visible="dialogVisible"
            modal
            header="Enregistrer un encaissement"
            :style="{ width: '520px' }"
        >
            <div v-if="factureActive" class="space-y-5">
                <!-- Résumé facture -->
                <div class="space-y-2 rounded-lg bg-muted/40 p-4">
                    <div class="flex items-center justify-between">
                        <span class="text-xs text-muted-foreground"
                            >Facture</span
                        >
                        <span class="font-mono text-sm font-semibold">{{
                            factureActive.reference
                        }}</span>
                    </div>
                    <div
                        v-if="
                            factureActive.vehicule_nom ||
                            factureActive.client_nom
                        "
                        class="flex items-center justify-between"
                    >
                        <span class="text-xs text-muted-foreground">{{
                            factureActive.vehicule_nom ? 'Véhicule' : 'Client'
                        }}</span>
                        <span class="text-sm font-medium">{{
                            factureActive.vehicule_nom ??
                            factureActive.client_nom
                        }}</span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-xs text-muted-foreground"
                            >Montant total</span
                        >
                        <span class="text-sm font-medium tabular-nums">{{
                            formatGNF(factureActive.montant_net)
                        }}</span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-xs text-muted-foreground"
                            >Déjà encaissé</span
                        >
                        <span
                            class="text-sm font-medium text-emerald-600 tabular-nums dark:text-emerald-400"
                        >
                            {{ formatGNF(factureActive.montant_encaisse) }}
                        </span>
                    </div>
                    <div
                        class="flex items-center justify-between border-t pt-2"
                    >
                        <span
                            class="text-xs font-semibold text-muted-foreground"
                            >Restant dû</span
                        >
                        <span
                            class="text-base font-bold text-amber-600 tabular-nums dark:text-amber-400"
                        >
                            {{ formatGNF(factureActive.montant_restant) }}
                        </span>
                    </div>
                </div>

                <!-- Formulaire -->
                <div class="grid gap-4 sm:grid-cols-2">
                    <!-- Montant -->
                    <div class="sm:col-span-2">
                        <Label class="mb-1.5 block text-sm"
                            >Montant
                            <span class="text-destructive">*</span></Label
                        >
                        <InputNumber
                            :model-value="encaissementForm.montant"
                            @update:model-value="
                                encaissementForm.montant = $event
                            "
                            :min="0.01"
                            :max="factureActive.montant_restant"
                            :use-grouping="true"
                            locale="fr-FR"
                            suffix=" GNF"
                            class="w-full"
                            input-class="w-full text-right text-lg font-bold"
                            :class="{
                                'p-invalid': encaissementForm.errors.montant,
                            }"
                        />
                        <p
                            v-if="encaissementForm.errors.montant"
                            class="mt-1 text-xs text-destructive"
                        >
                            {{ encaissementForm.errors.montant }}
                        </p>
                    </div>

                    <!-- Mode paiement -->
                    <div>
                        <Label class="mb-1.5 block text-sm"
                            >Mode de paiement
                            <span class="text-destructive">*</span></Label
                        >
                        <Dropdown
                            v-model="encaissementForm.mode_paiement"
                            :options="modes_paiement"
                            option-label="label"
                            option-value="value"
                            class="w-full"
                            :class="{
                                'p-invalid':
                                    encaissementForm.errors.mode_paiement,
                            }"
                        />
                        <p
                            v-if="encaissementForm.errors.mode_paiement"
                            class="mt-1 text-xs text-destructive"
                        >
                            {{ encaissementForm.errors.mode_paiement }}
                        </p>
                    </div>

                    <!-- Date -->
                    <div>
                        <Label class="mb-1.5 block text-sm"
                            >Date <span class="text-destructive">*</span></Label
                        >
                        <InputText
                            v-model="encaissementForm.date_encaissement"
                            type="date"
                            class="w-full"
                            :class="{
                                'p-invalid':
                                    encaissementForm.errors.date_encaissement,
                            }"
                        />
                        <p
                            v-if="encaissementForm.errors.date_encaissement"
                            class="mt-1 text-xs text-destructive"
                        >
                            {{ encaissementForm.errors.date_encaissement }}
                        </p>
                    </div>

                    <!-- Note -->
                    <div class="sm:col-span-2">
                        <Label class="mb-1.5 block text-sm">Note</Label>
                        <InputText
                            v-model="encaissementForm.note as string"
                            class="w-full"
                            placeholder="Optionnel…"
                        />
                    </div>
                </div>
            </div>

            <template #footer>
                <div class="flex justify-end gap-2">
                    <Button variant="outline" @click="dialogVisible = false"
                        >Annuler</Button
                    >
                    <Button
                        :disabled="
                            encaissementForm.processing ||
                            !encaissementForm.montant
                        "
                        @click="submitEncaissement"
                    >
                        <CreditCard class="mr-2 h-4 w-4" />
                        {{
                            encaissementForm.processing
                                ? 'Enregistrement…'
                                : "Valider l'encaissement"
                        }}
                    </Button>
                </div>
            </template>
        </Dialog>
    </AppLayout>
</template>
