<script setup lang="ts">
import DataFilters, {
    type FilterField,
} from '@/components/filters/DataFilters.vue';
import StatusDot from '@/components/StatusDot.vue';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import {
    ArrowDown,
    ArrowUp,
    ChevronLeft,
    ChevronRight,
    History,
    PackageOpen,
    SlidersHorizontal,
} from 'lucide-vue-next';
import { useToast } from 'primevue/usetoast';
import { computed, ref } from 'vue';
import AjusterStockModal from '../partials/AjusterStockModal.vue';
import HistoriqueModal from '../partials/HistoriqueModal.vue';

interface Site {
    id: string;
    nom: string;
    code: string;
}

interface StockRow {
    produit_id: string;
    produit_nom: string;
    categorie_id: string | null;
    categorie_nom: string | null;
    variante_id: string;
    variante_libelle: string;
    is_default: boolean;
    sku: string;
    site_id: string;
    site_nom: string;
    site_code: string | null;
    qte_stock: number;
    seuil_effectif: number;
    statut: 'disponible' | 'stock_faible' | 'rupture';
    statut_label: string;
    dernier_mouvement: {
        type: 'entree' | 'sortie';
        quantite: number;
        created_at: string;
    } | null;
    can_ajuster: boolean;
}

interface Paginator {
    data: StockRow[];
    links: { url: string | null; label: string; active: boolean }[];
    current_page: number;
    last_page: number;
    from: number | null;
    to: number | null;
    total: number;
}

interface Option {
    id?: string;
    value?: string;
    nom?: string;
    label?: string;
}

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
}

interface MotifOption {
    value: string;
    label: string;
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

const props = defineProps<{
    stocks: Paginator;
    sites: Site[];
    categories: Option[];
    stock_statuts: Option[];
    filters: Record<string, unknown>;
    can_augmenter_stock: boolean;
    can_diminuer_stock: boolean;
}>();

const toast = useToast();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/backoffice/dashboard' },
    { title: 'Produits', href: '/backoffice/produits' },
    { title: 'Stock', href: '/backoffice/produits/stock' },
];

const filterFields = computed<FilterField[]>(() => [
    {
        key: 'stock_statut',
        type: 'select',
        label: 'État du stock',
        inline: true,
        placeholder: 'Tous les états',
        options: props.stock_statuts.map((statut) => ({
            value: statut.value ?? '',
            label: statut.label ?? '',
        })),
    },
    {
        key: 'search',
        type: 'text',
        label: 'Produit ou SKU',
        inline: true,
        placeholder: 'Nom ou référence…',
    },
    {
        key: 'categorie_id',
        type: 'select',
        label: 'Catégorie',
        placeholder: 'Toutes les catégories',
        options: props.categories.map((categorie) => ({
            value: categorie.id ?? '',
            label: categorie.nom ?? '',
        })),
    },
]);

function formatNombre(value: number): string {
    return new Intl.NumberFormat('fr-FR').format(value);
}

function paginationLabel(label: string): string {
    return label.replace('&laquo;', '').replace('&raquo;', '').trim();
}

const selectedStock = ref<StockRow | null>(null);
const showStockModal = ref(false);

const produitAjustement = computed(() => {
    const row = selectedStock.value;
    if (!row) return null;

    return {
        id: row.produit_id,
        nom: row.produit_nom,
        sku: row.sku,
        qte_stock: row.qte_stock,
        stocks_par_site: [
            {
                site_id: row.site_id,
                site_code: row.site_code,
                site_nom: row.site_nom,
                qte_stock: row.qte_stock,
            },
        ],
        variantes: [
            {
                id: row.variante_id,
                libelle: row.variante_libelle,
                sku: row.sku,
                is_default: row.is_default,
                is_active: true,
            },
        ],
    };
});

const siteAjustement = computed<Site[]>(() => {
    const row = selectedStock.value;
    if (!row || !row.can_ajuster) return [];
    return props.sites.filter((site) => site.id === row.site_id);
});

function ouvrirAjustement(row: StockRow): void {
    selectedStock.value = row;
    showStockModal.value = true;
}

const historiqueTitre = ref('Historique du stock');
const ajustements = ref<StockMouvement[]>([]);
const modifications = ref<AuditEntry[]>([]);
const motifsDisponibles = ref<MotifOption[]>([]);
const showHistoriqueModal = ref(false);
const historiqueLoading = ref(false);
const historiqueRow = ref<StockRow | null>(null);

async function chargerHistorique(motif: string | null): Promise<void> {
    const row = historiqueRow.value;
    if (!row) return;

    historiqueLoading.value = true;

    const params = new URLSearchParams({
        variante_id: row.variante_id,
        site_id: row.site_id,
    });
    if (motif) params.set('motif', motif);

    try {
        const response = await fetch(
            '/backoffice/produits/' +
                row.produit_id +
                '/historique?' +
                params.toString(),
            {
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            },
        );
        if (!response.ok) throw new Error('Historique indisponible');
        const data = await response.json();
        ajustements.value = data.ajustements ?? [];
        modifications.value = data.modifications ?? [];
        motifsDisponibles.value = data.motifs_disponibles ?? [];
    } catch {
        showHistoriqueModal.value = false;
        toast.add({
            severity: 'error',
            summary: 'Chargement impossible',
            detail: 'L’historique du stock n’a pas pu être chargé.',
            life: 4000,
        });
    } finally {
        historiqueLoading.value = false;
    }
}

async function ouvrirHistorique(row: StockRow): Promise<void> {
    historiqueTitre.value =
        row.produit_nom + ' · ' + row.variante_libelle + ' · ' + row.site_nom;
    historiqueRow.value = row;
    ajustements.value = [];
    modifications.value = [];
    motifsDisponibles.value = [];
    showHistoriqueModal.value = true;
    await chargerHistorique(null);
}

function onFilterMotif(motif: string | null): void {
    chargerHistorique(motif);
}
</script>

<template>
    <Head title="Stock" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-5 p-4 md:p-6">
            <header class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h1 class="text-2xl font-semibold tracking-tight">Stock</h1>
                    <p class="mt-1 text-sm text-muted-foreground">
                        {{ formatNombre(stocks.total) }} référence{{
                            stocks.total !== 1 ? 's' : ''
                        }}
                        répartie{{ stocks.total !== 1 ? 's' : '' }} par variante
                        et par agence
                    </p>
                </div>
            </header>

            <DataFilters
                url="/backoffice/produits/stock"
                :values="filters"
                :sites="sites"
                :fields="filterFields"
                :result-count="stocks.total"
            />

            <section
                class="overflow-hidden rounded-xl border bg-card shadow-sm"
            >
                <div class="overflow-x-auto" data-testid="stock-table-scroll">
                    <table
                        data-testid="stock-table"
                        class="w-full min-w-[980px] text-sm"
                    >
                        <thead
                            class="border-b bg-muted/40 text-xs text-muted-foreground"
                        >
                            <tr>
                                <th class="px-4 py-3 text-left font-medium">
                                    Produit
                                </th>
                                <th class="px-4 py-3 text-left font-medium">
                                    Variante
                                </th>
                                <th class="px-4 py-3 text-left font-medium">
                                    Agence
                                </th>
                                <th class="px-4 py-3 text-right font-medium">
                                    Disponible
                                </th>
                                <th class="px-4 py-3 text-left font-medium">
                                    État
                                </th>
                                <th class="px-4 py-3 text-right font-medium">
                                    Stock minimum
                                </th>
                                <th class="px-4 py-3 text-left font-medium">
                                    Dernier mouvement
                                </th>
                                <th class="px-4 py-3 text-right font-medium">
                                    Actions
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr
                                v-for="row in stocks.data"
                                :key="row.variante_id + '-' + row.site_id"
                                class="transition-colors hover:bg-muted/25"
                            >
                                <td class="px-4 py-3">
                                    <Link
                                        :href="
                                            '/backoffice/produits/' +
                                            row.produit_id
                                        "
                                        class="hover:text-primary hover:underline"
                                    >
                                        {{ row.produit_nom }}
                                    </Link>
                                    <div
                                        v-if="row.sku"
                                        class="mt-0.5 font-mono text-xs text-muted-foreground"
                                    >
                                        SKU {{ row.sku }}
                                    </div>
                                </td>
                                <td class="px-4 py-3 text-muted-foreground">
                                    {{ row.variante_libelle }}
                                </td>
                                <td class="px-4 py-3">
                                    <div class="font-medium">
                                        {{ row.site_nom }}
                                    </div>
                                    <div
                                        v-if="row.site_code"
                                        class="font-mono text-xs text-muted-foreground"
                                    >
                                        {{ row.site_code }}
                                    </div>
                                </td>
                                <td
                                    class="px-4 py-3 text-right text-base font-semibold tabular-nums"
                                >
                                    {{ formatNombre(row.qte_stock) }}
                                </td>
                                <td class="px-4 py-3">
                                    <StatusDot
                                        :status="row.statut"
                                        :label="row.statut_label"
                                    />
                                </td>
                                <td
                                    class="px-4 py-3 text-right text-muted-foreground tabular-nums"
                                >
                                    {{ formatNombre(row.seuil_effectif) }}
                                </td>
                                <td class="px-4 py-3">
                                    <div
                                        v-if="row.dernier_mouvement"
                                        class="space-y-0.5"
                                    >
                                        <div
                                            class="flex items-center gap-1 font-medium"
                                            :class="
                                                row.dernier_mouvement.type ===
                                                'entree'
                                                    ? 'text-emerald-700 dark:text-emerald-400'
                                                    : 'text-red-700 dark:text-red-400'
                                            "
                                        >
                                            <ArrowUp
                                                v-if="
                                                    row.dernier_mouvement
                                                        .type === 'entree'
                                                "
                                                class="h-3.5 w-3.5"
                                            />
                                            <ArrowDown
                                                v-else
                                                class="h-3.5 w-3.5"
                                            />
                                            {{
                                                row.dernier_mouvement.type ===
                                                'entree'
                                                    ? '+'
                                                    : '-'
                                            }}{{
                                                formatNombre(
                                                    row.dernier_mouvement
                                                        .quantite,
                                                )
                                            }}
                                        </div>
                                        <div
                                            class="text-xs whitespace-nowrap text-muted-foreground"
                                        >
                                            {{
                                                row.dernier_mouvement.created_at
                                            }}
                                        </div>
                                    </div>
                                    <span v-else class="text-muted-foreground"
                                        >Aucun</span
                                    >
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex justify-end gap-1">
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            :aria-label="
                                                'Historique de ' +
                                                row.produit_nom
                                            "
                                            data-testid="stock-history-button"
                                            @click="ouvrirHistorique(row)"
                                        >
                                            <History class="h-4 w-4" />
                                            <span class="sr-only"
                                                >Historique</span
                                            >
                                        </Button>
                                        <Button
                                            v-if="row.can_ajuster"
                                            variant="outline"
                                            size="sm"
                                            data-testid="stock-adjust-button"
                                            @click="ouvrirAjustement(row)"
                                        >
                                            <SlidersHorizontal
                                                class="mr-1.5 h-4 w-4"
                                            />
                                            Ajuster
                                        </Button>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="stocks.data.length === 0">
                                <td colspan="8" class="px-6 py-16 text-center">
                                    <PackageOpen
                                        class="mx-auto h-10 w-10 text-muted-foreground/40"
                                    />
                                    <p class="mt-3 font-medium">
                                        Aucun stock à afficher
                                    </p>
                                    <p
                                        class="mt-1 text-sm text-muted-foreground"
                                    >
                                        Aucun produit gérant le stock ne
                                        correspond aux filtres actuels.
                                    </p>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <div
                v-if="stocks.last_page > 1"
                class="flex flex-wrap items-center justify-between gap-3"
            >
                <p class="text-xs text-muted-foreground">
                    Résultats {{ stocks.from }} à {{ stocks.to }} sur
                    {{ stocks.total }}
                </p>
                <nav
                    class="flex items-center gap-1"
                    aria-label="Pagination du stock"
                >
                    <template v-for="link in stocks.links" :key="link.label">
                        <Link
                            v-if="link.url"
                            :href="link.url"
                            preserve-scroll
                            class="inline-flex h-8 min-w-8 items-center justify-center rounded-md border px-2 text-sm hover:bg-muted"
                            :class="{
                                'border-primary bg-primary text-primary-foreground':
                                    link.active,
                            }"
                        >
                            <ChevronLeft
                                v-if="link.label.includes('Previous')"
                                class="h-4 w-4"
                            />
                            <ChevronRight
                                v-else-if="link.label.includes('Next')"
                                class="h-4 w-4"
                            />
                            <span v-else>{{
                                paginationLabel(link.label)
                            }}</span>
                        </Link>
                        <span
                            v-else
                            class="inline-flex h-8 min-w-8 items-center justify-center rounded-md border px-2 text-sm opacity-40"
                        >
                            <ChevronLeft
                                v-if="link.label.includes('Previous')"
                                class="h-4 w-4"
                            />
                            <ChevronRight
                                v-else-if="link.label.includes('Next')"
                                class="h-4 w-4"
                            />
                            <span v-else>{{
                                paginationLabel(link.label)
                            }}</span>
                        </span>
                    </template>
                </nav>
            </div>
        </div>

        <AjusterStockModal
            v-if="produitAjustement && selectedStock"
            v-model:visible="showStockModal"
            :produit="produitAjustement"
            :sites-autorises="siteAjustement"
            :can-augmenter="can_augmenter_stock"
            :can-diminuer="can_diminuer_stock"
            :variante-stocks="[
                {
                    variante_id: selectedStock.variante_id,
                    site_id: selectedStock.site_id,
                    qte_stock: selectedStock.qte_stock,
                },
            ]"
        />

        <HistoriqueModal
            v-model:visible="showHistoriqueModal"
            :ajustements="ajustements"
            :modifications="modifications"
            :motif-options="motifsDisponibles"
            :loading="historiqueLoading"
            :title="historiqueTitre"
            @filter-motif="onFilterMotif"
        />
    </AppLayout>
</template>
