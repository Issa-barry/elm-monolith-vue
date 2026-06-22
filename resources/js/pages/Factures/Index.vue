<script setup lang="ts">
import DataFilters, {
    type FilterField,
} from '@/components/filters/DataFilters.vue';
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
import { usePermissions } from '@/composables/usePermissions';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import {
    ArrowLeft,
    CreditCard,
    History,
    MoreVertical,
    Search,
    Truck,
    X,
} from 'lucide-vue-next';
import Column from 'primevue/column';
import DataTable from 'primevue/datatable';
import Dialog from 'primevue/dialog';
import { useToast } from 'primevue/usetoast';
import { computed, ref } from 'vue';

// ── Types ─────────────────────────────────────────────────────────────────────
interface EncaissementItem {
    id: number;
    montant: number;
    date_encaissement: string | null;
    enregistre_le: string | null;
    mode_paiement: string;
    note: string | null;
    created_by: string | null;
}

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
    is_encaissable: boolean;
    created_at: string;
    encaissements: EncaissementItem[];
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
interface SiteOption {
    value: string;
    label: string;
}

interface LivreurInfo {
    id: string;
    nom_complet: string;
    telephone: string | null;
}

const props = defineProps<{
    factures: FactureItem[];
    totaux: Totaux;
    modes_paiement: ModePaiementOption[];
    periode: string;
    statut: string;
    site_ids?: string[];
    sites: SiteOption[];
    livreur_id?: string | null;
    livreur?: LivreurInfo | null;
    vehicule?: string | null;
    chauffeur?: string | null;
    convoyeur?: string | null;
    proprietaire?: string | null;
    client?: string | null;
    reference?: string | null;
}>();

const { can } = usePermissions();
const toast = useToast();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/dashboard' },
    { title: 'Factures', href: '/factures' },
];

const periodes = [
    { value: 'today', label: "Aujourd'hui" },
    { value: 'week', label: 'Cette semaine' },
    { value: 'month', label: 'Ce mois' },
    { value: 'all', label: 'Tout' },
];

const filtres = [
    { value: 'tous', label: 'Toutes' },
    { value: 'impayee', label: 'Impayées' },
    { value: 'partiel', label: 'Partielles' },
    { value: 'payee', label: 'Payées' },
    { value: 'annulee', label: 'Annulées' },
];

const filterBaseParams = computed(() => {
    const p: Record<string, string> = {};
    if (props.livreur_id) p.livreur_id = props.livreur_id;
    return p;
});

const filterValues = computed(() => ({
    periode: props.periode,
    statut: props.statut,
    site_ids: props.site_ids ?? [],
    vehicule: props.vehicule ?? '',
    chauffeur: props.chauffeur ?? '',
    convoyeur: props.convoyeur ?? '',
    proprietaire: props.proprietaire ?? '',
    client: props.client ?? '',
    reference: props.reference ?? '',
}));

const filterFields = computed<FilterField[]>(() => [
    { key: 'periode', label: 'Période', type: 'select', options: periodes },
    { key: 'statut', label: 'Statut', type: 'select', options: filtres },
    {
        key: 'vehicule',
        label: 'Véhicule',
        type: 'text',
        placeholder: 'Nom ou immatriculation…',
    },
    {
        key: 'chauffeur',
        label: 'Chauffeur',
        type: 'text',
        placeholder: 'Nom ou téléphone…',
    },
    {
        key: 'convoyeur',
        label: 'Convoyeur',
        type: 'text',
        placeholder: 'Nom ou téléphone…',
    },
    {
        key: 'proprietaire',
        label: 'Propriétaire',
        type: 'text',
        placeholder: 'Nom ou téléphone…',
    },
    {
        key: 'client',
        label: 'Client',
        type: 'text',
        placeholder: 'Nom ou téléphone…',
    },
    {
        key: 'reference',
        label: 'Référence',
        type: 'text',
        placeholder: 'FAC-…',
    },
]);

// ── Recherche locale (client-side, immédiate) ─────────────────────────────────
const search = ref('');

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
    const payees = list.filter((f) => f.statut_facture === 'payee');
    return {
        total: list
            .filter((f) => f.statut_facture !== 'annulee')
            .reduce((s, f) => s + f.montant_net, 0),
        nb_total: list.filter((f) => f.statut_facture !== 'annulee').length,
        total_a_encaisser: list
            .filter((f) => !['payee', 'annulee'].includes(f.statut_facture))
            .reduce((sum, f) => sum + f.montant_restant, 0),
        nb_impayees: impayees.length,
        montant_impayees: impayees.reduce((s, f) => s + f.montant_restant, 0),
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
const encaissProcessing = ref(false);
const encaissErrors = ref<Record<string, string>>({});

// ── Dialog historique ─────────────────────────────────────────────────────────
const historyVisible = ref(false);
const factureHistory = ref<FactureItem | null>(null);

function openHistory(facture: FactureItem) {
    factureHistory.value = facture;
    historyVisible.value = true;
}

function openDialog(facture: FactureItem) {
    factureActive.value = facture;
    encaissProcessing.value = false;
    encaissErrors.value = {};
    dialogVisible.value = true;
}

function handleEncaissSubmit(payload: {
    montant: number;
    mode_paiement: string;
}) {
    if (!factureActive.value) return;
    encaissProcessing.value = true;
    encaissErrors.value = {};
    router.post(`/factures/${factureActive.value.id}/encaissements`, payload, {
        preserveScroll: true,
        onSuccess: () => {
            dialogVisible.value = false;
            toast.add({
                severity: 'success',
                summary: 'Validé',
                detail: 'Encaissement enregistré avec succès.',
                life: 3000,
            });
        },
        onError: (e) => {
            encaissErrors.value = e as Record<string, string>;
        },
        onFinish: () => {
            encaissProcessing.value = false;
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

            <!-- Bandeau filtre livreur (mobile) -->
            <div
                v-if="livreur"
                class="flex items-center gap-2 border-b border-blue-200 bg-blue-50 px-4 py-2 text-xs text-blue-800 dark:border-blue-800 dark:bg-blue-950 dark:text-blue-200"
            >
                <Truck class="h-3.5 w-3.5 shrink-0" />
                <span class="flex-1 truncate">
                    Livreur : <strong>{{ livreur.nom_complet }}</strong>
                </span>
                <a
                    href="/factures"
                    class="flex items-center gap-0.5 opacity-70"
                >
                    <X class="h-3.5 w-3.5" />
                </a>
            </div>

            <!-- KPI cards -->
            <div class="grid grid-cols-2 gap-3 p-4">
                <div class="rounded-xl border bg-card p-4 shadow-sm">
                    <p class="text-xs text-muted-foreground">Total</p>
                    <p
                        class="mt-1 text-lg font-bold text-foreground tabular-nums"
                    >
                        {{ formatGNF(totauxFiltres.total) }}
                    </p>
                    <p class="text-xs text-muted-foreground">
                        {{ totauxFiltres.nb_total }} facture{{
                            totauxFiltres.nb_total > 1 ? 's' : ''
                        }}
                    </p>
                </div>
                <div class="rounded-xl border bg-card p-4 shadow-sm">
                    <p class="text-xs text-muted-foreground">
                        Restant à encaisser
                    </p>
                    <p
                        class="mt-1 text-lg font-bold text-foreground tabular-nums"
                    >
                        {{ formatGNF(totauxFiltres.total_a_encaisser) }}
                    </p>
                </div>
                <div class="rounded-xl border bg-card p-4 shadow-sm">
                    <p class="text-xs text-muted-foreground">Impayées</p>
                    <p
                        class="mt-1 text-lg font-bold text-foreground tabular-nums"
                    >
                        {{ formatGNF(totauxFiltres.montant_impayees) }}
                    </p>
                    <p class="text-xs text-muted-foreground">
                        {{ totauxFiltres.nb_impayees }} facture{{
                            totauxFiltres.nb_impayees > 1 ? 's' : ''
                        }}
                    </p>
                </div>
                <div class="rounded-xl border bg-card p-4 shadow-sm">
                    <p class="text-xs text-muted-foreground">Soldées</p>
                    <p
                        class="mt-1 text-lg font-bold text-foreground tabular-nums"
                    >
                        {{ formatGNF(totauxFiltres.montant_payees) }}
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
                        <span
                            v-if="
                                !f.is_annulee &&
                                !f.is_payee &&
                                can('ventes.update')
                            "
                            :title="
                                !f.is_encaissable
                                    ? 'Encaissement possible uniquement après validation du chargement.'
                                    : ''
                            "
                        >
                            <Button
                                size="sm"
                                variant="outline"
                                class="h-7 border-emerald-300 text-xs text-emerald-700 hover:bg-emerald-50 dark:border-emerald-700 dark:text-emerald-400 dark:hover:bg-emerald-950"
                                :disabled="!f.is_encaissable"
                                @click="openDialog(f)"
                            >
                                <CreditCard class="mr-1 h-3.5 w-3.5" />
                                Encaisser
                            </Button>
                        </span>
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
        <div class="hidden flex-col gap-6 p-6 sm:flex">
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

            <!-- Bandeau filtre livreur -->
            <div
                v-if="livreur"
                class="flex items-center gap-3 rounded-lg border border-blue-200 bg-blue-50 px-4 py-2.5 text-sm text-blue-800 dark:border-blue-800 dark:bg-blue-950 dark:text-blue-200"
            >
                <Truck class="h-4 w-4 shrink-0" />
                <span class="flex-1">
                    Factures filtrées pour le livreur
                    <strong>{{ livreur.nom_complet }}</strong>
                    <span
                        v-if="livreur.telephone"
                        class="ml-1 font-mono text-xs opacity-70"
                    >
                        {{ livreur.telephone }}
                    </span>
                </span>
                <a
                    href="/factures"
                    class="ml-auto flex items-center gap-1 text-xs opacity-70 hover:opacity-100"
                >
                    <X class="h-3.5 w-3.5" />
                    Effacer le filtre
                </a>
            </div>

            <!-- Cartes de synthèse -->
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div class="rounded-xl border bg-card p-5 shadow-sm">
                    <p class="text-sm text-muted-foreground">Total</p>
                    <p
                        class="mt-2 text-2xl font-bold text-foreground tabular-nums"
                    >
                        {{ formatGNF(totauxFiltres.total) }}
                    </p>
                    <p class="mt-0.5 text-xs text-muted-foreground">
                        {{ totauxFiltres.nb_total }} facture{{
                            totauxFiltres.nb_total > 1 ? 's' : ''
                        }}
                    </p>
                </div>

                <div class="rounded-xl border bg-card p-5 shadow-sm">
                    <p class="text-sm text-muted-foreground">
                        Restant à encaisser
                    </p>
                    <p
                        class="mt-2 text-2xl font-bold text-foreground tabular-nums"
                    >
                        {{ formatGNF(totauxFiltres.total_a_encaisser) }}
                    </p>
                </div>

                <div class="rounded-xl border bg-card p-5 shadow-sm">
                    <p class="text-sm text-muted-foreground">Impayées</p>
                    <p
                        class="mt-2 text-2xl font-bold text-foreground tabular-nums"
                    >
                        {{ formatGNF(totauxFiltres.montant_impayees) }}
                    </p>
                    <p class="mt-0.5 text-xs text-muted-foreground">
                        {{ totauxFiltres.nb_impayees }} facture{{
                            totauxFiltres.nb_impayees > 1 ? 's' : ''
                        }}
                    </p>
                </div>

                <div class="rounded-xl border bg-card p-5 shadow-sm">
                    <p class="text-sm text-muted-foreground">Soldées</p>
                    <p
                        class="mt-2 text-2xl font-bold text-foreground tabular-nums"
                    >
                        {{ formatGNF(totauxFiltres.montant_payees) }}
                    </p>
                    <p class="mt-0.5 text-xs text-muted-foreground">
                        {{ totauxFiltres.nb_payees }} facture{{
                            totauxFiltres.nb_payees > 1 ? 's' : ''
                        }}
                    </p>
                </div>
            </div>

            <!-- Filtres -->
            <DataFilters
                url="/factures"
                :base-params="filterBaseParams"
                :values="filterValues"
                :result-count="facturesFiltrees.length"
                :fields="filterFields"
                search-placeholder="Référence, véhicule, client…"
                v-model:search="search"
            />

            <!-- Tableau -->
            <div class="overflow-hidden rounded-xl border bg-card">
                <DataTable
                    :value="facturesFiltrees"
                    :paginator="facturesFiltrees.length > 20"
                    :rows="20"
                    data-key="id"
                    striped-rows
                    removable-sort
                    class="text-sm"
                    :pt="{
                        root: { class: 'w-full' },
                        tbody: { class: 'divide-y' },
                    }"
                >
                    <!-- Référence -->
                    <Column
                        field="reference"
                        header="Référence"
                        sortable
                        style="min-width: 180px"
                    >
                        <template #body="{ data }">
                            <span
                                class="inline-block rounded bg-muted px-1.5 py-0.5 font-mono text-[11px] text-muted-foreground"
                                >{{ data.reference }}</span
                            >
                        </template>
                    </Column>

                    <!-- Véhicule / Client -->
                    <Column header="Véhicule / Client" style="min-width: 160px">
                        <template #body="{ data }">
                            <div v-if="data.vehicule_nom" class="font-medium">
                                {{ data.vehicule_nom }}
                            </div>
                            <div
                                v-if="data.client_nom"
                                class="text-muted-foreground"
                                :class="{ 'text-xs': data.vehicule_nom }"
                            >
                                {{ data.client_nom }}
                            </div>
                            <span
                                v-if="!data.vehicule_nom && !data.client_nom"
                                class="text-muted-foreground"
                                >—</span
                            >
                        </template>
                    </Column>

                    <!-- Site -->
                    <Column
                        field="site_nom"
                        header="Site"
                        sortable
                        style="min-width: 120px"
                    >
                        <template #body="{ data }">
                            <span class="text-muted-foreground">{{
                                data.site_nom ?? '—'
                            }}</span>
                        </template>
                    </Column>

                    <!-- Montant -->
                    <Column
                        field="montant_net"
                        header="Montant"
                        sortable
                        style="width: 140px"
                    >
                        <template #body="{ data }">
                            <span class="tabular-nums">{{
                                formatGNF(data.montant_net)
                            }}</span>
                        </template>
                    </Column>

                    <!-- Encaissé -->
                    <Column
                        field="montant_encaisse"
                        header="Encaissé"
                        sortable
                        style="width: 140px"
                    >
                        <template #body="{ data }">
                            <span class="text-muted-foreground tabular-nums">{{
                                formatGNF(data.montant_encaisse)
                            }}</span>
                        </template>
                    </Column>

                    <!-- Restant -->
                    <Column
                        field="montant_restant"
                        header="Restant"
                        sortable
                        style="width: 140px"
                    >
                        <template #body="{ data }">
                            <span class="text-muted-foreground tabular-nums">
                                {{
                                    data.montant_restant > 0
                                        ? formatGNF(data.montant_restant)
                                        : '—'
                                }}
                            </span>
                        </template>
                    </Column>

                    <!-- Statut -->
                    <Column
                        field="statut_label"
                        header="Statut"
                        sortable
                        style="width: 120px"
                    >
                        <template #body="{ data }">
                            <StatusDot
                                :label="data.statut_label"
                                :dot-class="
                                    statutColor[data.statut_facture] ??
                                    'bg-zinc-400 dark:bg-zinc-500'
                                "
                                class="text-muted-foreground"
                            />
                        </template>
                    </Column>

                    <!-- Date -->
                    <Column
                        field="created_at"
                        header="Date"
                        sortable
                        style="width: 110px"
                    >
                        <template #body="{ data }">
                            <span
                                class="text-xs text-muted-foreground tabular-nums"
                                >{{ data.created_at }}</span
                            >
                        </template>
                    </Column>

                    <!-- Actions -->
                    <Column header="" style="width: 56px">
                        <template #body="{ data }">
                            <div class="flex justify-end">
                                <DropdownMenu>
                                    <DropdownMenuTrigger as-child>
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            class="h-8 w-8"
                                        >
                                            <MoreVertical class="h-4 w-4" />
                                        </Button>
                                    </DropdownMenuTrigger>
                                    <DropdownMenuContent
                                        align="end"
                                        class="w-48"
                                    >
                                        <DropdownMenuItem
                                            class="cursor-pointer"
                                            @click="openHistory(data)"
                                        >
                                            <History class="h-4 w-4" />
                                            Historique
                                        </DropdownMenuItem>
                                        <template
                                            v-if="
                                                !data.is_annulee &&
                                                !data.is_payee &&
                                                can('ventes.update')
                                            "
                                        >
                                            <DropdownMenuSeparator />
                                            <DropdownMenuItem
                                                class="cursor-pointer"
                                                :disabled="!data.is_encaissable"
                                                :title="
                                                    !data.is_encaissable
                                                        ? 'Encaissement possible uniquement après validation du chargement.'
                                                        : ''
                                                "
                                                @click="
                                                    data.is_encaissable &&
                                                    openDialog(data)
                                                "
                                            >
                                                <CreditCard class="h-4 w-4" />
                                                Encaisser
                                            </DropdownMenuItem>
                                        </template>
                                    </DropdownMenuContent>
                                </DropdownMenu>
                            </div>
                        </template>
                    </Column>

                    <template #empty>
                        <div
                            class="py-16 text-center text-sm text-muted-foreground"
                        >
                            Aucune facture trouvée.
                        </div>
                    </template>
                </DataTable>
            </div>
        </div>

        <!-- Dialog historique ───────────────────────────────────────────────── -->
        <Dialog
            v-model:visible="historyVisible"
            modal
            :header="
                factureHistory
                    ? `Historique — ${factureHistory.reference}`
                    : 'Historique'
            "
            :style="{ width: '560px' }"
        >
            <div v-if="factureHistory">
                <div
                    v-if="factureHistory.encaissements.length === 0"
                    class="py-8 text-center text-sm text-muted-foreground"
                >
                    Aucun encaissement enregistré.
                </div>
                <table v-else class="w-full text-sm">
                    <thead>
                        <tr class="border-b bg-muted/30">
                            <th
                                class="px-3 py-2 text-left font-medium text-muted-foreground"
                            >
                                Date versement
                            </th>
                            <th
                                class="px-3 py-2 text-left font-medium text-muted-foreground"
                            >
                                Heure
                            </th>
                            <th
                                class="px-3 py-2 text-left font-medium text-muted-foreground"
                            >
                                Mode
                            </th>
                            <th
                                class="px-3 py-2 text-right font-medium text-muted-foreground"
                            >
                                Montant
                            </th>
                            <th
                                class="px-3 py-2 text-left font-medium text-muted-foreground"
                            >
                                Par
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr
                            v-for="e in factureHistory.encaissements"
                            :key="e.id"
                            class="hover:bg-muted/10"
                        >
                            <td
                                class="px-3 py-2 text-muted-foreground tabular-nums"
                            >
                                {{ e.date_encaissement ?? '—' }}
                            </td>
                            <td
                                class="px-3 py-2 text-muted-foreground tabular-nums"
                            >
                                {{
                                    e.enregistre_le
                                        ? e.enregistre_le.split(' ')[1]
                                        : '—'
                                }}
                            </td>
                            <td class="px-3 py-2 text-muted-foreground">
                                {{ e.mode_paiement }}
                            </td>
                            <td
                                class="px-3 py-2 text-right font-medium tabular-nums"
                            >
                                {{ formatGNF(e.montant) }}
                            </td>
                            <td class="px-3 py-2 text-xs text-muted-foreground">
                                {{ e.created_by ?? '—' }}
                            </td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <tr class="border-t">
                            <td
                                colspan="3"
                                class="px-3 py-2 text-sm font-semibold"
                            >
                                Total encaissé
                            </td>
                            <td
                                class="px-3 py-2 text-right font-semibold tabular-nums"
                            >
                                {{
                                    formatGNF(
                                        factureHistory.encaissements.reduce(
                                            (s, e) => s + e.montant,
                                            0,
                                        ),
                                    )
                                }}
                            </td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </Dialog>

        <!-- Dialog encaissement ─────────────────────────────────────────────── -->
        <PaymentDialogCompact
            v-model:visible="dialogVisible"
            :title="
                factureActive
                    ? `Encaisser — ${factureActive.reference}`
                    : 'Encaisser'
            "
            :solde="factureActive?.montant_restant ?? 0"
            :processing="encaissProcessing"
            :errors="encaissErrors"
            :modes-paiement="modes_paiement"
            @submit="handleEncaissSubmit"
        />
    </AppLayout>
</template>
