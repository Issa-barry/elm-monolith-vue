<script setup lang="ts">
import DataFilters, {
    type FilterField,
} from '@/components/filters/DataFilters.vue';
import ListPageActions from '@/components/ListPageActions.vue';
import StatusDot from '@/components/StatusDot.vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useClickableTableRow } from '@/composables/useClickableTableRow';
import { usePermissions } from '@/composables/usePermissions';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import {
    AlertTriangle,
    Archive,
    ArrowDown,
    ArrowUp,
    ChevronDown,
    Download,
    Eye,
    History,
    MoreVertical,
    Package,
    Pencil,
    Plus,
    Sliders,
    Trash2,
    Upload,
    X,
} from 'lucide-vue-next';
import Column from 'primevue/column';
import DataTable from 'primevue/datatable';
import { useConfirm } from 'primevue/useconfirm';
import { useToast } from 'primevue/usetoast';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
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
    statut: 'disponible' | 'stock_faible' | 'rupture';
    updated_at: string | null;
}

interface Produit {
    id: string;
    nom: string;
    sku: string | null;
    code_barres: string | null;
    produit_type_id: string | null;
    type_nom: string | null;
    image_url: string | null;
    statut: string | null;
    statut_label: string | null;
    prix_usine: number | null;
    prix_vente: number | null;
    prix_achat: number | null;
    cout: number | null;
    qte_stock: number | null;
    alerte_stock_active: boolean;
    description: string | null;
    in_stock: boolean;
    is_low_stock: boolean;
    is_out_of_stock: boolean;
    has_stock: boolean;
    is_used: boolean;
    has_variantes: boolean;
    last_mouvement_type: 'entree' | 'sortie' | null;
    last_mouvement_quantite: number | null;
    stocks_par_site: SiteStock[];
}

interface ProduitTableRow extends Produit {
    table_row_key: string;
    stock_site_label: string | null;
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
    produit_type_id?: string;
    statut?: string;
    site_ids?: string[];
}

const props = defineProps<{
    produits: Produit[];
    sites: Site[];
    types: FilterOption[];
    statuts: FilterOption[];
    filters: Filters;
    can_ajuster_stock: boolean;
}>();

const { can } = usePermissions();
const confirm = useConfirm();
const toast = useToast();

const { onRowClick, bodyRowPt } = useClickableTableRow<Produit>(
    (produit) => `/backoffice/produits/${produit.id}`,
);

// ── Filtres serveur ───────────────────────────────────────────────────────────

const searchInput = ref(props.filters.search ?? '');

const hasActiveFilters = computed(
    () => showOnlyRuptures.value || showOnlyFaibles.value,
);

function clearFilters() {
    searchInput.value = '';
    showOnlyRuptures.value = false;
    showOnlyFaibles.value = false;
    router.get(
        '/backoffice/produits',
        {},
        { preserveState: true, replace: true },
    );
}

const currentSiteLabel = computed(() => {
    const ids = props.filters.site_ids ?? [];
    if (ids.length === 0) return 'Toutes agences';
    if (ids.length === 1) {
        return (
            props.sites.find((s) => s.id === ids[0])?.nom ?? 'Toutes agences'
        );
    }
    // 2-3 sites : noms concaténés ; 4+ : "N agences"
    if (ids.length <= 3) {
        const names = ids
            .map((id) => props.sites.find((s) => s.id === id)?.nom)
            .filter(Boolean);
        return names.join(', ');
    }
    return `${ids.length} agences`;
});

const filterFields = computed<FilterField[]>(() => [
    {
        key: 'statut',
        type: 'select',
        label: 'Statut',
        options: [
            { value: '', label: 'Tous les statuts' },
            ...props.statuts.map((s) => ({ value: s.value, label: s.label })),
        ],
    },
    {
        key: 'search',
        type: 'text',
        label: 'Rechercher',
        inline: true,
        placeholder: 'Rechercher...',
    },
    {
        key: 'produit_type_id',
        type: 'select',
        label: 'Type',
        options: [
            { value: '', label: 'Tous les types' },
            ...props.types.map((t) => ({ value: t.value, label: t.label })),
        ],
    },
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
    props.produits.filter((p) => p.has_stock && p.is_out_of_stock),
);
const faibles = computed(() =>
    props.produits.filter((p) => p.has_stock && p.is_low_stock),
);

const filteredProduits = computed(() => {
    if (showOnlyRuptures.value)
        return props.produits.filter((p) => p.has_stock && p.is_out_of_stock);
    if (showOnlyFaibles.value)
        return props.produits.filter((p) => p.has_stock && p.is_low_stock);
    return props.produits;
});

function siteLabel(siteId: string, stock?: SiteStock): string {
    return (
        stock?.site_nom ??
        stock?.site_code ??
        props.sites.find((site) => site.id === siteId)?.nom ??
        'Agence non renseignée'
    );
}

function stockAlertRows(statut: 'rupture' | 'stock_faible'): ProduitTableRow[] {
    const selectedSiteIds = props.filters.site_ids ?? [];
    const selectedSiteSet = new Set(selectedSiteIds);

    return filteredProduits.value.flatMap((produit) => {
        const stocksDansLePerimetre = produit.stocks_par_site.filter(
            (stock) =>
                selectedSiteSet.size === 0 ||
                selectedSiteSet.has(String(stock.site_id)),
        );
        const stocksConcernes = stocksDansLePerimetre.filter((stock) =>
            statut === 'rupture'
                ? stock.qte_stock <= 0
                : stock.statut === 'stock_faible',
        );

        if (stocksConcernes.length > 0) {
            const stocksParAgence = stocksConcernes.reduce((groupes, stock) => {
                const siteId = String(stock.site_id);
                const stocks = groupes.get(siteId) ?? [];
                stocks.push(stock);
                groupes.set(siteId, stocks);
                return groupes;
            }, new Map<string, SiteStock[]>());

            return Array.from(stocksParAgence, ([siteId, stocks]) => {
                const mouvementEstScopeSurCetteAgence =
                    selectedSiteIds.length === 1 &&
                    String(selectedSiteIds[0]) === siteId;

                return {
                    ...produit,
                    table_row_key: `${produit.id}:${siteId}:${statut}`,
                    stock_site_label: siteLabel(siteId, stocks[0]),
                    qte_stock: stocks.reduce(
                        (total, stock) => total + stock.qte_stock,
                        0,
                    ),
                    is_out_of_stock: statut === 'rupture',
                    is_low_stock: statut === 'stock_faible',
                    last_mouvement_type: mouvementEstScopeSurCetteAgence
                        ? produit.last_mouvement_type
                        : null,
                    last_mouvement_quantite: mouvementEstScopeSurCetteAgence
                        ? produit.last_mouvement_quantite
                        : null,
                };
            });
        }

        // Produit legacy sans ventilation de stock : si un filtre agence est actif,
        // chaque agence sélectionnée porte le stock nul retourné par le backend.
        if (stocksDansLePerimetre.length === 0 && selectedSiteIds.length > 0) {
            return selectedSiteIds.map((siteId) => ({
                ...produit,
                table_row_key: `${produit.id}:${siteId}:${statut}:fallback`,
                stock_site_label: siteLabel(String(siteId)),
                last_mouvement_type:
                    selectedSiteIds.length === 1
                        ? produit.last_mouvement_type
                        : null,
                last_mouvement_quantite:
                    selectedSiteIds.length === 1
                        ? produit.last_mouvement_quantite
                        : null,
            }));
        }

        return [
            {
                ...produit,
                table_row_key: `${produit.id}:sans-agence:${statut}`,
                stock_site_label: 'Stock non ventilé',
            },
        ];
    });
}

const tableProduits = computed<ProduitTableRow[]>(() => {
    if (showOnlyRuptures.value) return stockAlertRows('rupture');
    if (showOnlyFaibles.value) return stockAlertRows('stock_faible');

    return filteredProduits.value.map((produit) => ({
        ...produit,
        table_row_key: produit.id,
        stock_site_label: null,
    }));
});

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/backoffice/dashboard' },
    { title: 'Produits', href: '/backoffice/produits' },
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
        { label: 'Référence', value: (p: Produit) => p.sku },
        { label: 'Nom', value: (p: Produit) => p.nom },
        {
            label: 'Code-barres',
            value: (p: Produit) => p.code_barres,
        },
        { label: 'Type', value: (p: Produit) => p.type_nom },
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
            label: 'État du site',
            value: (_p: Produit, s?: SiteStock) => s?.statut ?? '',
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

// ── Modal historique ──────────────────────────────────────────────────────────

interface StockMouvement {
    id: string;
    type: 'entree' | 'sortie';
    quantite: number;
    stock_avant: number | null;
    stock_apres: number | null;
    notes: string | null;
    motif_type: string;
    motif_label: string;
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
        const res = await fetch(
            `/backoffice/produits/${produit.id}/historique`,
            {
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            },
        );
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
            router.delete(`/backoffice/produits/${produit.id}`, {
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
                `/backoffice/produits/${produit.id}/archiver`,
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
                class="flex flex-col gap-1.5"
            >
                <button
                    v-if="ruptures.length > 0"
                    type="button"
                    class="group flex w-full items-center gap-2.5 rounded-lg border px-3 py-2 text-left transition-all focus-visible:ring-2 focus-visible:ring-destructive/30 focus-visible:outline-none"
                    :class="
                        showOnlyRuptures
                            ? 'border-destructive/50 bg-destructive/10 text-destructive shadow-sm'
                            : 'border-destructive/25 bg-destructive/5 text-destructive hover:border-destructive/40 hover:bg-destructive/10'
                    "
                    @click="toggleRuptures"
                >
                    <span
                        class="flex h-8 w-8 shrink-0 items-center justify-center rounded-md bg-destructive/10"
                    >
                        <AlertTriangle class="h-4 w-4" />
                    </span>
                    <div
                        class="min-w-0 flex-1 lg:flex lg:items-baseline lg:gap-2"
                    >
                        <div
                            class="flex shrink-0 flex-wrap items-baseline gap-x-2 gap-y-0.5"
                        >
                            <span class="text-sm font-semibold"
                                >Rupture de stock</span
                            >
                            <span class="text-xs font-medium opacity-70">
                                {{ ruptures.length }} produit{{
                                    ruptures.length > 1 ? 's' : ''
                                }}
                                concerné{{ ruptures.length > 1 ? 's' : '' }}
                            </span>
                        </div>
                        <p
                            class="mt-0.5 truncate text-xs opacity-75 lg:mt-0"
                            :title="ruptures.map((p) => p.nom).join(', ')"
                        >
                            {{ ruptures.map((p) => p.nom).join(', ') }}
                        </p>
                    </div>
                    <span
                        class="inline-flex h-7 shrink-0 items-center rounded-md border border-destructive/20 bg-background/70 px-2.5 text-xs font-semibold shadow-sm transition-colors group-hover:bg-background"
                    >
                        {{
                            showOnlyRuptures
                                ? 'Afficher tout'
                                : 'Voir les produits'
                        }}
                    </span>
                </button>
                <button
                    v-if="faibles.length > 0"
                    type="button"
                    class="group flex w-full items-center gap-2.5 rounded-lg border px-3 py-2 text-left transition-all focus-visible:ring-2 focus-visible:ring-amber-500/30 focus-visible:outline-none"
                    :class="
                        showOnlyFaibles
                            ? 'border-amber-500/60 bg-amber-100 text-amber-800 shadow-sm dark:bg-amber-950/35 dark:text-amber-300'
                            : 'border-amber-400/35 bg-amber-50/80 text-amber-800 hover:border-amber-500/50 hover:bg-amber-100 dark:bg-amber-950/20 dark:text-amber-300 dark:hover:bg-amber-950/30'
                    "
                    @click="toggleFaibles"
                >
                    <span
                        class="flex h-8 w-8 shrink-0 items-center justify-center rounded-md bg-amber-500/10"
                    >
                        <AlertTriangle class="h-4 w-4" />
                    </span>
                    <div
                        class="min-w-0 flex-1 lg:flex lg:items-baseline lg:gap-2"
                    >
                        <div
                            class="flex shrink-0 flex-wrap items-baseline gap-x-2 gap-y-0.5"
                        >
                            <span class="text-sm font-semibold"
                                >Stock faible</span
                            >
                            <span class="text-xs font-medium opacity-70">
                                {{ faibles.length }} produit{{
                                    faibles.length > 1 ? 's' : ''
                                }}
                                concerné{{ faibles.length > 1 ? 's' : '' }}
                            </span>
                        </div>
                        <p
                            class="mt-0.5 truncate text-xs opacity-75 lg:mt-0"
                            :title="faibles.map((p) => p.nom).join(', ')"
                        >
                            {{ faibles.map((p) => p.nom).join(', ') }}
                        </p>
                    </div>
                    <span
                        class="inline-flex h-7 shrink-0 items-center rounded-md border border-amber-600/20 bg-background/70 px-2.5 text-xs font-semibold shadow-sm transition-colors group-hover:bg-background"
                    >
                        {{
                            showOnlyFaibles
                                ? 'Afficher tout'
                                : 'Voir les produits'
                        }}
                    </span>
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
                <ListPageActions>
                    <template #export>
                        <Button
                            type="button"
                            variant="outline"
                            @click="exportExcel"
                        >
                            <Download class="mr-2 h-4 w-4" />
                            Exporter Excel
                        </Button>
                    </template>
                    <template
                        v-if="
                            can('imports-produits.create') ||
                            can('imports-produits.read')
                        "
                        #import
                    >
                        <DropdownMenu>
                            <DropdownMenuTrigger as-child>
                                <Button type="button" variant="outline">
                                    <Upload class="mr-2 h-4 w-4" />
                                    Importer
                                    <ChevronDown class="ml-2 h-3.5 w-3.5" />
                                </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end" class="w-56">
                                <DropdownMenuItem
                                    v-if="can('imports-produits.create')"
                                    as-child
                                >
                                    <Link
                                        href="/backoffice/produits/imports/nouveau"
                                        class="flex w-full items-center gap-2"
                                    >
                                        <Upload class="h-4 w-4" />
                                        Importer des produits
                                    </Link>
                                </DropdownMenuItem>
                                <DropdownMenuItem
                                    v-if="can('imports-produits.create')"
                                    as-child
                                >
                                    <a
                                        href="/backoffice/produits/imports/modele"
                                        class="flex w-full items-center gap-2"
                                    >
                                        <Download class="h-4 w-4" />
                                        Télécharger le modèle
                                    </a>
                                </DropdownMenuItem>
                                <DropdownMenuSeparator
                                    v-if="
                                        can('imports-produits.create') &&
                                        can('imports-produits.read')
                                    "
                                />
                                <DropdownMenuItem
                                    v-if="can('imports-produits.read')"
                                    as-child
                                >
                                    <Link
                                        href="/backoffice/produits/imports"
                                        class="flex w-full items-center gap-2"
                                    >
                                        <History class="h-4 w-4" />
                                        Historique des imports
                                    </Link>
                                </DropdownMenuItem>
                            </DropdownMenuContent>
                        </DropdownMenu>
                    </template>
                    <template #filters>
                        <DataFilters
                            trigger-only
                            url="/backoffice/produits"
                            :values="{
                                search: filters.search ?? '',
                                produit_type_id: filters.produit_type_id ?? '',
                                statut: filters.statut ?? '',
                                site_ids: filters.site_ids ?? [],
                            }"
                            :fields="filterFields"
                            :result-count="filteredProduits.length"
                            @reset="clearFilters"
                        />
                    </template>
                    <template v-if="can('produits.create')" #primary>
                        <Link href="/backoffice/produits/create">
                            <Button>
                                <Plus class="mr-2 h-4 w-4" />
                                Nouveau produit
                            </Button>
                        </Link>
                    </template>
                </ListPageActions>
            </div>

            <div v-if="hasActiveFilters" class="flex items-center gap-2">
                <button
                    type="button"
                    class="shrink-0 text-xs text-muted-foreground underline-offset-2 hover:text-foreground hover:underline"
                    @click="clearFilters"
                >
                    Réinitialiser
                </button>
            </div>

            <!-- Table -->
            <div class="overflow-hidden rounded-xl border bg-card">
                <DataTable
                    :value="tableProduits"
                    :paginator="tableProduits.length > 20"
                    :rows="20"
                    data-key="table_row_key"
                    striped-rows
                    removable-sort
                    class="text-sm"
                    table-class="w-full"
                    :pt="{
                        root: { class: 'w-full' },
                        tbody: { class: 'divide-y' },
                        bodyRow: bodyRowPt,
                    }"
                    @row-click="onRowClick"
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

                    <!-- Référence (sku) -->
                    <Column
                        field="sku"
                        header="Référence"
                        sortable
                        style="width: 160px"
                    >
                        <template #body="{ data }">
                            <span
                                class="font-mono text-xs font-semibold whitespace-nowrap text-muted-foreground"
                            >
                                {{ data.sku || '—' }}
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
                                :href="`/backoffice/produits/${data.id}`"
                                class="flex items-center gap-1.5 underline-offset-2 hover:underline"
                            >
                                <span class="font-medium">{{ data.nom }}</span>
                                <AlertTriangle
                                    v-if="data.is_out_of_stock"
                                    class="h-3.5 w-3.5 shrink-0 text-red-500"
                                />
                                <AlertTriangle
                                    v-else-if="data.is_low_stock"
                                    class="h-3.5 w-3.5 shrink-0 text-amber-500"
                                />
                            </Link>
                        </template>
                    </Column>

                    <!-- Type -->
                    <Column
                        field="produit_type_id"
                        header="Type"
                        sortable
                        style="width: 130px"
                    >
                        <template #body="{ data }">
                            <span
                                v-if="data.type_nom"
                                class="inline-flex items-center rounded-full bg-zinc-100 px-2 py-0.5 text-xs font-medium text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400"
                            >
                                {{ data.type_nom }}
                            </span>
                            <span v-else class="text-xs text-muted-foreground"
                                >—</span
                            >
                        </template>
                    </Column>

                    <!-- Agence -->
                    <Column header="Agence" style="width: 150px">
                        <template #body="{ data }">
                            <span class="text-sm text-muted-foreground">
                                {{ data.stock_site_label ?? currentSiteLabel }}
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
                                                data.is_out_of_stock
                                                    ? 'text-red-600'
                                                    : data.is_low_stock
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
                                            v-if="data.is_out_of_stock"
                                            class="h-3.5 w-3.5 text-red-500"
                                        />
                                        <AlertTriangle
                                            v-else-if="data.is_low_stock"
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
                                                :href="`/backoffice/produits/${data.id}`"
                                                class="flex w-full items-center gap-2"
                                            >
                                                <Eye class="h-4 w-4" />
                                                Détail
                                            </Link>
                                        </DropdownMenuItem>
                                        <DropdownMenuItem
                                            class="cursor-pointer"
                                            @click="openHistoriqueModal(data)"
                                        >
                                            <History class="h-4 w-4" />
                                            Historique
                                        </DropdownMenuItem>
                                        <!-- « Voir le stock » remplace l'ancien « Ajuster le stock » direct
                                        (modale) sur cette liste — redondant avec la page Stock, qui identifie
                                        clairement site et variante. L'ajustement lui-même reste disponible
                                        UNIQUEMENT depuis Produits/Stock/Index.vue (site+variante identifiés)
                                        et, pour les produits à variantes, depuis la fiche détail ci-dessous. -->
                                        <DropdownMenuItem
                                            v-if="data.has_stock"
                                            class="cursor-pointer"
                                            as-child
                                        >
                                            <Link
                                                :href="`/backoffice/produits/stock?search=${encodeURIComponent(data.nom)}`"
                                                class="flex items-center gap-2"
                                            >
                                                <Package class="h-4 w-4" />
                                                Voir le stock
                                            </Link>
                                        </DropdownMenuItem>
                                        <!-- Produit à variantes : l'ajustement se fait depuis sa fiche,
                                        où le choix de la variante est possible (cf. Show.vue). -->
                                        <DropdownMenuItem
                                            v-if="
                                                can_ajuster_stock &&
                                                data.has_stock &&
                                                data.has_variantes
                                            "
                                            class="cursor-pointer"
                                            as-child
                                        >
                                            <Link
                                                :href="`/backoffice/produits/${data.id}`"
                                                class="flex items-center gap-2"
                                            >
                                                <Sliders class="h-4 w-4" />
                                                Ajuster le stock (par variante)
                                            </Link>
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
                                                :href="`/backoffice/produits/${data.id}/edit`"
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
                                href="/backoffice/produits/create"
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
                    class="flex items-start gap-3 rounded-xl border border-destructive/25 bg-destructive/5 p-3 text-destructive"
                >
                    <span
                        class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-destructive/10"
                    >
                        <AlertTriangle class="h-4 w-4" />
                    </span>
                    <div class="min-w-0 flex-1">
                        <p class="text-xs font-semibold">
                            Rupture de stock · {{ ruptures.length }}
                        </p>
                        <p class="mt-0.5 truncate text-xs opacity-75">
                            {{ ruptures.map((p) => p.nom).join(', ') }}
                        </p>
                    </div>
                </div>
                <div
                    v-if="faibles.length > 0"
                    class="flex items-start gap-3 rounded-xl border border-amber-400/35 bg-amber-50/80 p-3 text-amber-800 dark:bg-amber-950/20 dark:text-amber-300"
                >
                    <span
                        class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-amber-500/10"
                    >
                        <AlertTriangle class="h-4 w-4" />
                    </span>
                    <div class="min-w-0 flex-1">
                        <p class="text-xs font-semibold">
                            Stock faible · {{ faibles.length }}
                        </p>
                        <p class="mt-0.5 truncate text-xs opacity-75">
                            {{ faibles.map((p) => p.nom).join(', ') }}
                        </p>
                    </div>
                </div>
            </div>
            <ProduitsMobile
                :produits="props.produits"
                :on-delete="confirmDelete"
                :on-archive="confirmArchive"
                filter-url="/backoffice/produits"
                :filter-values="{
                    search: filters.search ?? '',
                    produit_type_id: filters.produit_type_id ?? '',
                    statut: filters.statut ?? '',
                    site_ids: filters.site_ids ?? [],
                }"
                :filter-fields="filterFields"
                :result-count="filteredProduits.length"
            />
        </div>

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
