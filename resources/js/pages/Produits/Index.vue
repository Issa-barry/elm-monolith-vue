<script setup lang="ts">
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
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import {
    AlertTriangle,
    Archive,
    ArrowDown,
    ArrowUp,
    Download,
    Eye,
    History,
    MoreVertical,
    Package,
    Pencil,
    Plus,
    Sliders,
    Trash2,
    X,
} from 'lucide-vue-next';
import Column from 'primevue/column';
import DataTable from 'primevue/datatable';
import Dropdown from 'primevue/dropdown';
import IconField from 'primevue/iconfield';
import InputIcon from 'primevue/inputicon';
import InputText from 'primevue/inputtext';
import { useConfirm } from 'primevue/useconfirm';
import { useToast } from 'primevue/usetoast';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import AjusterStockModal from './partials/AjusterStockModal.vue';
import HistoriqueModal from './partials/HistoriqueModal.vue';
import ProduitsMobile from './partials/ProduitsMobile.vue';

const lightboxUrl = ref<string | null>(null);
const lightboxAlt = ref('');

function openLightbox(url: string, alt: string) {
    lightboxUrl.value = url;
    lightboxAlt.value = alt;
}
function closeLightbox() {
    lightboxUrl.value = null;
}
function onKeydown(e: KeyboardEvent) {
    if (e.key === 'Escape') closeLightbox();
}
onMounted(() => document.addEventListener('keydown', onKeydown));
onBeforeUnmount(() => document.removeEventListener('keydown', onKeydown));

interface SiteStock {
    site_id: string;
    site_code: string | null;
    site_nom: string | null;
    qte_stock: number;
    seuil_alerte_stock: number | null;
    is_alerte: boolean;
    updated_at: string | null;
}

interface Produit {
    id: string;
    nom: string;
    code_interne: string | null;
    code_fournisseur: string | null;
    type: string | null;
    type_label: string | null;
    image_url: string | null;
    is_alerte: boolean;
    statut: string | null;
    statut_label: string | null;
    prix_usine: number | null;
    prix_vente: number | null;
    prix_achat: number | null;
    cout: number | null;
    qte_stock: number | null;
    seuil_alerte_stock: number | null;
    description: string | null;
    in_stock: boolean;
    is_low_stock: boolean;
    has_stock: boolean;
    is_used: boolean;
    last_mouvement_type: 'entree' | 'sortie' | null;
    last_mouvement_quantite: number | null;
    stocks_par_site: SiteStock[];
}

interface Site {
    id: string;
    nom: string;
    code: string;
}

interface FilterOption {
    label: string;
    value: string;
}

interface Filters {
    search?: string;
    type?: string;
    statut?: string;
    site_id?: string;
}

const props = defineProps<{
    produits: Produit[];
    sites: Site[];
    types: FilterOption[];
    statuts: FilterOption[];
    filters: Filters;
}>();

const { can, hasRole } = usePermissions();
const confirm = useConfirm();
const toast = useToast();
const page = usePage();

const isAdmin = computed(
    () => hasRole('super_admin') || hasRole('admin_entreprise'),
);
const userDefaultSiteId = computed(
    () => (page.props.auth?.default_site?.id as string) ?? null,
);

// ── Filtres serveur ───────────────────────────────────────────────────────────

const searchInput = ref(props.filters.search ?? '');
const selectedType = ref(props.filters.type ?? '');
const selectedStatut = ref(props.filters.statut ?? '');
const selectedSite = ref(props.filters.site_id ?? '');

let searchTimer: ReturnType<typeof setTimeout> | null = null;

function applyFilters(overrides: Partial<Filters> = {}) {
    const params: Record<string, string | undefined> = {
        search: searchInput.value || undefined,
        type: selectedType.value || undefined,
        statut: selectedStatut.value || undefined,
        site_id: selectedSite.value || undefined,
        ...overrides,
    };
    router.get('/produits', params as Record<string, string>, {
        preserveState: true,
        replace: true,
    });
}

watch(searchInput, () => {
    if (searchTimer) clearTimeout(searchTimer);
    searchTimer = setTimeout(() => applyFilters(), 400);
});

watch(selectedType, () => applyFilters());
watch(selectedStatut, () => applyFilters());
watch(selectedSite, () => applyFilters());

const hasActiveFilters = computed(
    () =>
        !!searchInput.value ||
        !!selectedType.value ||
        !!selectedStatut.value ||
        !!selectedSite.value ||
        showOnlyRuptures.value ||
        showOnlyFaibles.value,
);

function clearFilters() {
    searchInput.value = '';
    selectedType.value = '';
    selectedStatut.value = '';
    selectedSite.value = '';
    showOnlyRuptures.value = false;
    showOnlyFaibles.value = false;
    applyFilters({
        search: undefined,
        type: undefined,
        statut: undefined,
        site_id: undefined,
    });
}

const siteOptions = computed(() => [
    { label: 'Toutes les agences', value: '' },
    ...props.sites.map((s) => ({
        label: s.nom + (s.code ? ` (${s.code})` : ''),
        value: s.id,
    })),
]);

const currentSiteLabel = computed(() => {
    if (!selectedSite.value) return 'Toutes agences';
    const site = props.sites.find((s) => s.id === selectedSite.value);
    return site ? site.nom : 'Toutes agences';
});

const typeOptions = computed(() => [
    { label: 'Tous les types', value: '' },
    ...props.types,
]);

const statutOptions = computed(() => [
    { label: 'Tous les statuts', value: '' },
    ...props.statuts,
]);

// ── Filtres client (rupture / stock faible) ───────────────────────────────────

const showOnlyRuptures = ref(false);
const showOnlyFaibles = ref(false);

function toggleRuptures() {
    showOnlyRuptures.value = !showOnlyRuptures.value;
    if (showOnlyRuptures.value) showOnlyFaibles.value = false;
}
function toggleFaibles() {
    showOnlyFaibles.value = !showOnlyFaibles.value;
    if (showOnlyFaibles.value) showOnlyRuptures.value = false;
}

const ruptures = computed(() =>
    props.produits.filter(
        (p) => p.has_stock && p.qte_stock !== null && p.qte_stock <= 0,
    ),
);
const faibles = computed(() =>
    props.produits.filter((p) => p.has_stock && p.is_low_stock),
);

const filteredProduits = computed(() => {
    if (showOnlyRuptures.value)
        return props.produits.filter(
            (p) => p.has_stock && (p.qte_stock ?? 0) <= 0,
        );
    if (showOnlyFaibles.value)
        return props.produits.filter((p) => p.has_stock && p.is_low_stock);
    return props.produits;
});

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/dashboard' },
    { title: 'Produits', href: '/produits' },
];

// ── Export Excel ──────────────────────────────────────────────────────────────

function escapeHtml(value: string): string {
    return value
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#39;');
}

function safeExcelText(value: string): string {
    const cleaned = value.replace(/\r?\n/g, ' ').trim();
    return /^[=+\-@]/.test(cleaned) ? `'${cleaned}` : cleaned;
}

function toExcelValue(value: unknown): string | number {
    if (value === null || value === undefined) return '';
    if (typeof value === 'boolean') return value ? 1 : 0;
    if (typeof value === 'number') return Number.isFinite(value) ? value : '';
    return safeExcelText(String(value));
}

function toExcelCell(value: unknown): string {
    const normalized = toExcelValue(value);
    return typeof normalized === 'number'
        ? `<td>${normalized}</td>`
        : `<td>${escapeHtml(normalized)}</td>`;
}

function formatExportDate(d: Date): string {
    const pad = (v: number) => String(v).padStart(2, '0');
    return `${d.getFullYear()}${pad(d.getMonth() + 1)}${pad(d.getDate())}-${pad(d.getHours())}${pad(d.getMinutes())}`;
}

function exportExcel(): void {
    const produits = filteredProduits.value;
    if (produits.length === 0) {
        toast.add({
            severity: 'warn',
            summary: 'Export impossible',
            detail: 'Aucun résultat à exporter.',
            life: 3000,
        });
        return;
    }

    const columns = [
        { label: 'Code interne', value: (p: Produit) => p.code_interne },
        { label: 'Nom', value: (p: Produit) => p.nom },
        {
            label: 'Code fournisseur',
            value: (p: Produit) => p.code_fournisseur,
        },
        { label: 'Type', value: (p: Produit) => p.type_label },
        { label: 'Statut', value: (p: Produit) => p.statut_label },
        { label: 'Prix vente (GNF)', value: (p: Produit) => p.prix_vente },
        { label: "Prix d'achat (GNF)", value: (p: Produit) => p.prix_achat },
        { label: 'Prix usine (GNF)', value: (p: Produit) => p.prix_usine },
        { label: 'Coût (GNF)', value: (p: Produit) => p.cout },
        {
            label: 'Code site',
            value: (_p: Produit, s?: SiteStock) => s?.site_code ?? '',
        },
        {
            label: 'Nom site',
            value: (_p: Produit, s?: SiteStock) => s?.site_nom ?? '',
        },
        {
            label: 'Stock site',
            value: (_p: Produit, s?: SiteStock) => s?.qte_stock ?? '',
        },
        {
            label: 'Seuil alerte site',
            value: (_p: Produit, s?: SiteStock) => s?.seuil_alerte_stock ?? '',
        },
        { label: 'Description', value: (p: Produit) => p.description },
    ];

    // Une ligne par produit × site (ou une ligne si aucun stock par site)
    const rows: Array<{ produit: Produit; siteStock?: SiteStock }> = [];
    for (const p of produits) {
        if (p.stocks_par_site.length > 0) {
            for (const s of p.stocks_par_site) {
                rows.push({ produit: p, siteStock: s });
            }
        } else {
            rows.push({ produit: p });
        }
    }

    const header = columns
        .map((c) => `<th>${escapeHtml(c.label)}</th>`)
        .join('');

    const body = rows
        .map(({ produit, siteStock }) => {
            const cells = columns.map((c) =>
                toExcelCell(c.value(produit, siteStock)),
            );
            return `<tr>${cells.join('')}</tr>`;
        })
        .join('');

    const html = `<!DOCTYPE html>
<html lang="fr">
<head><meta charset="UTF-8" /></head>
<body>
    <table border="1">
        <thead><tr>${header}</tr></thead>
        <tbody>${body}</tbody>
    </table>
</body>
</html>`;

    const blob = new Blob([`﻿${html}`], {
        type: 'application/vnd.ms-excel;charset=utf-8;',
    });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = `produits-${formatExportDate(new Date())}.xls`;
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    URL.revokeObjectURL(url);

    toast.add({
        severity: 'success',
        summary: 'Export lancé',
        detail: `${rows.length} ligne${rows.length > 1 ? 's' : ''} exportée${rows.length > 1 ? 's' : ''}.`,
        life: 2500,
    });
}

// ── Modal ajustement stock ────────────────────────────────────────────────────

const stockAjustementProduit = ref<Produit | null>(null);
const showStockModal = ref(false);

function openStockModal(produit: Produit) {
    stockAjustementProduit.value = produit;
    showStockModal.value = true;
}

// ── Modal historique ──────────────────────────────────────────────────────────

interface StockMouvement {
    id: string;
    type: 'entree' | 'sortie';
    quantite: number;
    stock_avant: number | null;
    stock_apres: number | null;
    notes: string | null;
    site_nom: string | null;
    site_code: string | null;
    createur_nom: string | null;
    created_at: string;
    is_initial?: boolean;
}

interface AuditEntry {
    id: string;
    event_code: string;
    event_label: string;
    actor_name: string;
    old_values: Record<string, unknown> | null;
    new_values: Record<string, unknown> | null;
    created_at: string;
}

const historiqueProduitNom = ref('');
const ajustements = ref<StockMouvement[]>([]);
const modifications = ref<AuditEntry[]>([]);
const showHistoriqueModal = ref(false);
const historiqueLoading = ref(false);

async function openHistoriqueModal(produit: Produit) {
    historiqueProduitNom.value = produit.nom;
    ajustements.value = [];
    modifications.value = [];
    showHistoriqueModal.value = true;
    historiqueLoading.value = true;
    try {
        const res = await fetch(`/produits/${produit.id}/historique`, {
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        });
        const data = await res.json();
        ajustements.value = data.ajustements ?? [];
        modifications.value = data.modifications ?? [];
    } finally {
        historiqueLoading.value = false;
    }
}

// ── Actions produit ───────────────────────────────────────────────────────────

function confirmDelete(produit: Produit) {
    confirm.require({
        message: `Supprimer "${produit.nom}" ? Cette action est irréversible.`,
        header: 'Confirmer la suppression',
        icon: 'pi pi-exclamation-triangle',
        rejectLabel: 'Annuler',
        acceptLabel: 'Supprimer',
        acceptClass: 'p-button-danger',
        accept: () => {
            router.delete(`/produits/${produit.id}`, {
                onSuccess: () => {
                    toast.add({
                        severity: 'success',
                        summary: 'Supprimé',
                        detail: `${produit.nom} a été supprimé.`,
                        life: 3000,
                    });
                },
            });
        },
    });
}

function confirmArchive(produit: Produit) {
    confirm.require({
        message: `Archiver "${produit.nom}" ? Le produit ne sera plus actif mais ses données seront conservées.`,
        header: "Confirmer l'archivage",
        icon: 'pi pi-inbox',
        rejectLabel: 'Annuler',
        acceptLabel: 'Archiver',
        acceptClass: 'p-button-warning',
        accept: () => {
            router.patch(
                `/produits/${produit.id}/archiver`,
                {},
                {
                    onSuccess: () => {
                        toast.add({
                            severity: 'success',
                            summary: 'Archivé',
                            detail: `${produit.nom} a été archivé.`,
                            life: 3000,
                        });
                    },
                },
            );
        },
    });
}
</script>

<template>
    <Head title="Produits" />

    <AppLayout :breadcrumbs="breadcrumbs" :hide-mobile-header="true">
        <!-- ─── VUE DESKTOP ─── -->
        <div class="hidden flex-col gap-6 p-6 sm:flex">
            <!-- Alertes stock (cliquables pour filtrer) -->
            <div
                v-if="ruptures.length > 0 || faibles.length > 0"
                class="flex flex-col gap-2"
            >
                <button
                    v-if="ruptures.length > 0"
                    type="button"
                    class="flex w-full items-start gap-3 rounded-lg border px-4 py-3 text-left text-sm transition-colors"
                    :class="
                        showOnlyRuptures
                            ? 'border-destructive bg-destructive/10 text-destructive'
                            : 'border-destructive/30 bg-destructive/5 text-destructive hover:bg-destructive/10'
                    "
                    @click="toggleRuptures"
                >
                    <AlertTriangle class="mt-0.5 h-4 w-4 shrink-0" />
                    <div>
                        <span class="font-semibold">Rupture de stock</span>
                        <span class="text-destructive/80"> — </span>
                        <span class="text-destructive/80">
                            {{ ruptures.map((p) => p.nom).join(', ') }}
                        </span>
                    </div>
                    <span class="ml-auto shrink-0 text-xs opacity-70">{{
                        showOnlyRuptures ? 'Afficher tout' : 'Filtrer'
                    }}</span>
                </button>
                <button
                    v-if="faibles.length > 0"
                    type="button"
                    class="flex w-full items-start gap-3 rounded-lg border px-4 py-3 text-left text-sm transition-colors"
                    :class="
                        showOnlyFaibles
                            ? 'border-amber-500 bg-amber-100 text-amber-700 dark:bg-amber-950/30'
                            : 'border-amber-400/30 bg-amber-50 text-amber-700 hover:bg-amber-100 dark:bg-amber-950/20 dark:text-amber-400 dark:hover:bg-amber-950/30'
                    "
                    @click="toggleFaibles"
                >
                    <AlertTriangle class="mt-0.5 h-4 w-4 shrink-0" />
                    <div>
                        <span class="font-semibold">Stock faible</span>
                        <span class="opacity-80"> — </span>
                        <span class="opacity-80">{{
                            faibles.map((p) => p.nom).join(', ')
                        }}</span>
                    </div>
                    <span class="ml-auto shrink-0 text-xs opacity-70">{{
                        showOnlyFaibles ? 'Afficher tout' : 'Filtrer'
                    }}</span>
                </button>
            </div>

            <!-- Header -->
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-semibold tracking-tight">
                        Produits
                    </h1>
                    <p class="mt-1 text-sm text-muted-foreground">
                        {{ filteredProduits.length }} produit{{
                            filteredProduits.length !== 1 ? 's' : ''
                        }}
                        dans le catalogue
                    </p>
                </div>
                <div class="flex items-center gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        @click="exportExcel"
                    >
                        <Download class="mr-2 h-4 w-4" />
                        Exporter Excel
                    </Button>
                    <Link v-if="can('produits.create')" href="/produits/create">
                        <Button>
                            <Plus class="mr-2 h-4 w-4" />
                            Nouveau produit
                        </Button>
                    </Link>
                </div>
            </div>

            <!-- Barre de filtres -->
            <div class="flex flex-wrap items-center gap-2">
                <IconField class="max-w-xs flex-1">
                    <InputIcon class="pointer-events-none">
                        <svg
                            class="h-4 w-4 text-muted-foreground"
                            fill="none"
                            stroke="currentColor"
                            viewBox="0 0 24 24"
                        >
                            <circle cx="11" cy="11" r="8" />
                            <path d="m21 21-4.35-4.35" />
                        </svg>
                    </InputIcon>
                    <InputText
                        v-model="searchInput"
                        placeholder="Rechercher…"
                        class="w-full text-sm"
                    />
                </IconField>

                <Dropdown
                    v-model="selectedSite"
                    :options="siteOptions"
                    option-label="label"
                    option-value="value"
                    placeholder="Agence"
                    class="min-w-[160px] text-sm"
                />

                <Dropdown
                    v-model="selectedType"
                    :options="typeOptions"
                    option-label="label"
                    option-value="value"
                    placeholder="Type"
                    class="min-w-[140px] text-sm"
                />

                <Dropdown
                    v-model="selectedStatut"
                    :options="statutOptions"
                    option-label="label"
                    option-value="value"
                    placeholder="Statut"
                    class="min-w-[140px] text-sm"
                />

                <Button
                    v-if="hasActiveFilters"
                    type="button"
                    variant="ghost"
                    size="sm"
                    class="h-9 text-muted-foreground"
                    @click="clearFilters"
                >
                    <X class="mr-1.5 h-3.5 w-3.5" />
                    Effacer
                </Button>
            </div>

            <!-- Table -->
            <div class="overflow-hidden rounded-xl border bg-card">
                <DataTable
                    :value="filteredProduits"
                    :paginator="filteredProduits.length > 20"
                    :rows="20"
                    data-key="id"
                    striped-rows
                    removable-sort
                    class="text-sm"
                    table-class="w-full"
                    :pt="{
                        root: { class: 'w-full' },
                        tbody: { class: 'divide-y' },
                    }"
                >
                    <!-- Image -->
                    <Column header="Image" style="width: 72px">
                        <template #body="{ data }">
                            <div
                                class="h-10 w-10 overflow-hidden rounded-lg border bg-muted"
                                :class="data.image_url ? 'cursor-zoom-in' : ''"
                                @click="
                                    data.image_url &&
                                    openLightbox(data.image_url, data.nom)
                                "
                            >
                                <img
                                    v-if="data.image_url"
                                    :src="data.image_url"
                                    :alt="data.nom"
                                    class="h-full w-full object-cover"
                                />
                                <div
                                    v-else
                                    class="flex h-full w-full items-center justify-center"
                                >
                                    <Package
                                        class="h-5 w-5 text-muted-foreground/40"
                                    />
                                </div>
                            </div>
                        </template>
                    </Column>

                    <!-- Code -->
                    <Column
                        field="code_interne"
                        header="Code"
                        sortable
                        style="width: 160px"
                    >
                        <template #body="{ data }">
                            <span
                                class="font-mono text-xs font-semibold whitespace-nowrap text-muted-foreground"
                            >
                                {{ data.code_interne || '—' }}
                            </span>
                        </template>
                    </Column>

                    <!-- Produit -->
                    <Column
                        field="nom"
                        header="Produit"
                        sortable
                        style="min-width: 200px"
                    >
                        <template #body="{ data }">
                            <Link
                                :href="`/produits/${data.id}`"
                                class="flex items-center gap-1.5 underline-offset-2 hover:underline"
                            >
                                <span class="font-medium">{{ data.nom }}</span>
                                <AlertTriangle
                                    v-if="data.is_alerte"
                                    class="h-3.5 w-3.5 shrink-0 text-amber-500"
                                />
                            </Link>
                        </template>
                    </Column>

                    <!-- Type -->
                    <Column
                        field="type"
                        header="Type"
                        sortable
                        style="width: 130px"
                    >
                        <template #body="{ data }">
                            <span
                                v-if="data.type_label"
                                class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium"
                                :class="{
                                    'bg-blue-100 text-blue-700 dark:bg-blue-950/30 dark:text-blue-400':
                                        data.type === 'materiel',
                                    'bg-violet-100 text-violet-700 dark:bg-violet-950/30 dark:text-violet-400':
                                        data.type === 'fabricable',
                                    'bg-orange-100 text-orange-700 dark:bg-orange-950/30 dark:text-orange-400':
                                        data.type === 'achat_vente',
                                    'bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400':
                                        data.type === 'service',
                                }"
                            >
                                {{ data.type_label }}
                            </span>
                            <span v-else class="text-xs text-muted-foreground"
                                >—</span
                            >
                        </template>
                    </Column>

                    <!-- Agence -->
                    <Column header="Agence" style="width: 150px">
                        <template #body>
                            <span class="text-sm text-muted-foreground">
                                {{ currentSiteLabel }}
                            </span>
                        </template>
                    </Column>

                    <!-- Prix de vente -->
                    <Column
                        field="prix_vente"
                        header="Prix vente"
                        sortable
                        style="width: 140px"
                    >
                        <template #body="{ data }">
                            <span class="tabular-nums">
                                {{
                                    data.prix_vente != null
                                        ? new Intl.NumberFormat('fr-FR').format(
                                              data.prix_vente,
                                          ) + ' GNF'
                                        : '—'
                                }}
                            </span>
                        </template>
                    </Column>

                    <!-- Stock -->
                    <Column
                        field="qte_stock"
                        header="Stock"
                        sortable
                        style="width: 120px"
                    >
                        <template #body="{ data }">
                            <template v-if="!data.has_stock">
                                <span class="text-xs text-muted-foreground"
                                    >—</span
                                >
                            </template>
                            <template v-else>
                                <div class="flex flex-col gap-0.5">
                                    <div class="flex items-center gap-1.5">
                                        <span
                                            class="text-sm font-medium tabular-nums"
                                            :class="
                                                data.is_low_stock
                                                    ? 'text-amber-600'
                                                    : 'text-foreground'
                                            "
                                        >
                                            {{
                                                new Intl.NumberFormat(
                                                    'fr-FR',
                                                ).format(data.qte_stock ?? 0)
                                            }}
                                        </span>
                                        <AlertTriangle
                                            v-if="data.is_low_stock"
                                            class="h-3.5 w-3.5 text-amber-500"
                                        />
                                    </div>
                                    <span
                                        v-if="
                                            data.last_mouvement_type ===
                                            'entree'
                                        "
                                        class="inline-flex items-center gap-0.5 text-xs font-medium text-emerald-600 dark:text-emerald-400"
                                    >
                                        <ArrowUp class="h-3 w-3" />
                                        +{{
                                            new Intl.NumberFormat(
                                                'fr-FR',
                                            ).format(
                                                data.last_mouvement_quantite ??
                                                    0,
                                            )
                                        }}
                                    </span>
                                    <span
                                        v-else-if="
                                            data.last_mouvement_type ===
                                            'sortie'
                                        "
                                        class="inline-flex items-center gap-0.5 text-xs font-medium text-red-600 dark:text-red-400"
                                    >
                                        <ArrowDown class="h-3 w-3" />
                                        -{{
                                            new Intl.NumberFormat(
                                                'fr-FR',
                                            ).format(
                                                data.last_mouvement_quantite ??
                                                    0,
                                            )
                                        }}
                                    </span>
                                </div>
                            </template>
                        </template>
                    </Column>

                    <!-- Statut -->
                    <Column
                        field="statut"
                        header="Statut"
                        sortable
                        style="width: 120px"
                    >
                        <template #body="{ data }">
                            <StatusDot
                                :label="data.statut_label"
                                :dot-class="
                                    data.statut === 'actif'
                                        ? 'bg-emerald-500'
                                        : data.statut === 'inactif'
                                          ? 'bg-zinc-400 dark:bg-zinc-500'
                                          : 'bg-orange-400'
                                "
                                class="text-muted-foreground"
                            />
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
                                        <DropdownMenuItem as-child>
                                            <Link
                                                :href="`/produits/${data.id}`"
                                                class="flex w-full items-center gap-2"
                                            >
                                                <Eye class="h-4 w-4" />
                                                Voir le détail
                                            </Link>
                                        </DropdownMenuItem>
                                        <DropdownMenuItem
                                            class="cursor-pointer"
                                            @click="openHistoriqueModal(data)"
                                        >
                                            <History class="h-4 w-4" />
                                            Historique
                                        </DropdownMenuItem>
                                        <DropdownMenuItem
                                            v-if="
                                                can('produits.update') &&
                                                data.has_stock
                                            "
                                            class="cursor-pointer"
                                            @click="openStockModal(data)"
                                        >
                                            <Sliders class="h-4 w-4" />
                                            Ajuster le stock
                                        </DropdownMenuItem>
                                        <DropdownMenuSeparator
                                            v-if="
                                                can('produits.update') ||
                                                can('produits.delete')
                                            "
                                        />
                                        <DropdownMenuItem
                                            v-if="can('produits.update')"
                                            as-child
                                        >
                                            <Link
                                                :href="`/produits/${data.id}/edit`"
                                                class="flex w-full items-center gap-2"
                                            >
                                                <Pencil class="h-4 w-4" />
                                                Modifier
                                            </Link>
                                        </DropdownMenuItem>
                                        <DropdownMenuSeparator
                                            v-if="
                                                can('produits.update') &&
                                                (can('produits.delete') ||
                                                    can('produits.update'))
                                            "
                                        />
                                        <DropdownMenuItem
                                            v-if="
                                                can('produits.delete') &&
                                                !data.is_used
                                            "
                                            class="cursor-pointer text-destructive focus:text-destructive"
                                            @click="confirmDelete(data)"
                                        >
                                            <Trash2 class="h-4 w-4" />
                                            Supprimer
                                        </DropdownMenuItem>
                                        <DropdownMenuItem
                                            v-if="
                                                can('produits.update') &&
                                                data.is_used &&
                                                data.statut !== 'archive'
                                            "
                                            class="cursor-pointer text-amber-600 focus:text-amber-600 dark:text-amber-400"
                                            @click="confirmArchive(data)"
                                        >
                                            <Archive class="h-4 w-4" />
                                            Archiver
                                        </DropdownMenuItem>
                                    </DropdownMenuContent>
                                </DropdownMenu>
                            </div>
                        </template>
                    </Column>

                    <!-- Empty state -->
                    <template #empty>
                        <div
                            class="flex flex-col items-center gap-3 py-16 text-muted-foreground"
                        >
                            <Package class="h-12 w-12 opacity-30" />
                            <p class="text-sm">Aucun produit trouvé.</p>
                            <Link
                                v-if="can('produits.create')"
                                href="/produits/create"
                            >
                                <Button variant="outline" size="sm">
                                    <Plus class="mr-2 h-4 w-4" />
                                    Créer le premier produit
                                </Button>
                            </Link>
                        </div>
                    </template>
                </DataTable>
            </div>
        </div>

        <!-- ─── VUE MOBILE ─── -->
        <div class="sm:hidden">
            <div
                v-if="ruptures.length > 0 || faibles.length > 0"
                class="flex flex-col gap-2 px-4 pt-4"
            >
                <div
                    v-if="ruptures.length > 0"
                    class="flex items-start gap-2 rounded-lg border border-destructive/30 bg-destructive/5 px-3 py-2.5 text-xs text-destructive"
                >
                    <AlertTriangle class="mt-0.5 h-3.5 w-3.5 shrink-0" />
                    <div>
                        <span class="font-semibold">Rupture</span> —
                        {{ ruptures.map((p) => p.nom).join(', ') }}
                    </div>
                </div>
                <div
                    v-if="faibles.length > 0"
                    class="flex items-start gap-2 rounded-lg border border-amber-400/30 bg-amber-50 px-3 py-2.5 text-xs text-amber-700 dark:bg-amber-950/20 dark:text-amber-400"
                >
                    <AlertTriangle class="mt-0.5 h-3.5 w-3.5 shrink-0" />
                    <div>
                        <span class="font-semibold">Stock faible</span> —
                        {{ faibles.map((p) => p.nom).join(', ') }}
                    </div>
                </div>
            </div>
            <ProduitsMobile
                :produits="props.produits"
                :on-delete="confirmDelete"
                :on-archive="confirmArchive"
            />
        </div>

        <!-- Modal ajustement stock -->
        <AjusterStockModal
            v-if="stockAjustementProduit"
            v-model:visible="showStockModal"
            :produit="stockAjustementProduit"
            :sites="sites"
            :is-admin="isAdmin"
            :user-default-site-id="userDefaultSiteId"
        />

        <!-- Modal historique -->
        <HistoriqueModal
            v-model:visible="showHistoriqueModal"
            :ajustements="ajustements"
            :modifications="modifications"
            :loading="historiqueLoading"
            :title="`Historique — ${historiqueProduitNom}`"
        />

        <!-- Lightbox -->
        <Teleport to="body">
            <div
                v-if="lightboxUrl"
                class="fixed inset-0 z-50 flex items-center justify-center bg-black/80 p-4"
                @click.self="closeLightbox"
            >
                <div class="relative max-h-full max-w-3xl">
                    <button
                        type="button"
                        class="absolute -top-3 -right-3 flex h-8 w-8 items-center justify-center rounded-full bg-white/10 text-white transition hover:bg-white/20"
                        @click="closeLightbox"
                    >
                        <X class="h-5 w-5" />
                    </button>
                    <img
                        :src="lightboxUrl"
                        :alt="lightboxAlt"
                        class="max-h-[80vh] max-w-full rounded-xl object-contain shadow-2xl"
                    />
                    <p class="mt-2 text-center text-sm text-white/70">
                        {{ lightboxAlt }}
                    </p>
                </div>
            </div>
        </Teleport>
    </AppLayout>
</template>
