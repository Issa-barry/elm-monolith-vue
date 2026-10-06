<script setup lang="ts">
import DataFilters, {
    type FilterField,
} from '@/components/filters/DataFilters.vue';
import ListPageActions from '@/components/ListPageActions.vue';
import StatusDot from '@/components/StatusDot.vue';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import type { StockRow } from '@/types/stock';
import { Head, Link, router } from '@inertiajs/vue3';
import {
    ArrowDown,
    ArrowLeft,
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
import MobileStockList from './MobileStockList.vue';

interface Site {
    id: string;
    nom: string;
    code: string;
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
    date: string;
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
const mobileStockList = ref<InstanceType<typeof MobileStockList> | null>(null);

function returnToStockDetails(): void {
    mobileStockList.value?.returnToDetails();
}

const STOCK_URL = '/backoffice/produits/stock';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/backoffice/dashboard' },
    { title: 'Produits', href: '/backoffice/produits' },
    { title: 'Stock', href: STOCK_URL },
];

const filterFields = computed<FilterField[]>(() => [
    {
        key: 'stock_statut',
        type: 'select',
        label: 'État du stock',
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

const hasActiveFilters = computed(() =>
    Boolean(
        props.filters.search ||
            props.filters.categorie_id ||
            props.filters.stock_statut,
    ),
);

function clearFilters(): void {
    // all=1 : réinitialisation explicite, la vue par défaut ne doit pas se réappliquer.
    router.get(
        STOCK_URL,
        { all: '1' },
        { preserveScroll: true, replace: true },
    );
}

function formatNombre(value: number | null): string {
    if (value === null) return '—';
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
        // Le modal ajuste le stock PHYSIQUE (jamais le disponible) : c'est row.qte_physique qui
        // sert de base à son aperçu "stock après ajustement".
        qte_stock: row.qte_physique,
        stocks_par_site: [
            {
                site_id: row.site_id,
                site_code: row.site_code,
                site_nom: row.site_nom,
                qte_stock: row.qte_physique,
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

function mouvementSigneLabel(m: StockRow['dernier_mouvement']): string {
    if (!m) return '';
    return (m.type === 'entree' ? '+' : '-') + formatNombre(m.quantite);
}
</script>

<template>
    <Head title="Stock" />

    <AppLayout :breadcrumbs="breadcrumbs" :hide-mobile-header="true">
        <header
            data-testid="stock-mobile-header"
            class="sticky top-0 z-10 grid grid-cols-[2.75rem_1fr_2.75rem] items-center gap-2 border-b bg-background px-4 py-2 sm:hidden"
        >
            <Link
                href="/backoffice/produits"
                aria-label="Retour aux produits"
                class="flex h-11 w-11 items-center justify-center rounded-lg text-muted-foreground hover:bg-muted hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
            >
                <ArrowLeft class="h-5 w-5" />
            </Link>
            <div class="min-w-0 text-center">
                <h1 class="text-base font-semibold">Stock</h1>
                <p class="text-xs text-muted-foreground">
                    {{ formatNombre(stocks.total) }} résultat{{
                        stocks.total !== 1 ? 's' : ''
                    }}
                </p>
            </div>
        </header>

        <div
            class="flex min-w-0 flex-1 flex-col gap-4 bg-muted/20 p-4 pb-[max(1rem,env(safe-area-inset-bottom))] sm:bg-transparent md:gap-5 md:p-6"
        >
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="hidden sm:block">
                    <h1 class="text-2xl font-semibold tracking-tight">Stock</h1>
                    <p class="mt-1 text-sm text-muted-foreground">
                        {{ formatNombre(stocks.total) }} référence{{
                            stocks.total !== 1 ? 's' : ''
                        }}
                        répartie{{ stocks.total !== 1 ? 's' : '' }} par variante
                        et par agence
                    </p>
                </div>
                <ListPageActions class="stock-actions w-full sm:w-auto">
                    <template #filters>
                        <DataFilters
                            trigger-only
                            saved-filter-scope="stock"
                            :url="STOCK_URL"
                            :values="filters"
                            :sites="sites"
                            :fields="filterFields"
                            :result-count="stocks.total"
                        />
                    </template>
                </ListPageActions>
            </div>

            <div
                v-if="hasActiveFilters"
                class="hidden items-center gap-2 sm:flex"
            >
                <button
                    type="button"
                    class="shrink-0 text-xs text-muted-foreground underline-offset-2 hover:text-foreground hover:underline"
                    @click="clearFilters"
                >
                    Réinitialiser les filtres
                </button>
            </div>

            <!-- ── Desktop : tableau complet ─────────────────────────────────── -->
            <section
                class="hidden overflow-hidden rounded-xl border bg-card shadow-sm md:block"
            >
                <div class="overflow-x-auto" data-testid="stock-table-scroll">
                    <table
                        data-testid="stock-table"
                        class="text-sm whitespace-nowrap"
                        style="width: max-content; min-width: 100%"
                    >
                        <thead
                            class="border-b bg-muted/40 text-xs text-muted-foreground"
                        >
                            <tr>
                                <th
                                    class="min-w-[240px] px-4 py-3 text-left font-medium"
                                >
                                    Produit
                                </th>
                                <th
                                    class="min-w-[150px] px-4 py-3 text-left font-medium"
                                >
                                    Agence
                                </th>
                                <th
                                    class="min-w-[96px] px-4 py-3 text-right font-medium"
                                >
                                    Physique
                                </th>
                                <th
                                    class="min-w-[96px] px-4 py-3 text-right font-medium"
                                    title="Commandes confirmées et précommandes, pas encore sorties du stock"
                                >
                                    Réservé
                                </th>
                                <th
                                    class="min-w-[96px] px-4 py-3 text-right font-medium"
                                >
                                    Bloqué
                                </th>
                                <th
                                    class="min-w-[110px] px-4 py-3 text-right font-medium"
                                >
                                    Disponible
                                </th>
                                <th
                                    class="min-w-[96px] px-4 py-3 text-right font-medium"
                                >
                                    Entrant
                                </th>
                                <th
                                    class="min-w-[96px] px-4 py-3 text-right font-medium"
                                >
                                    Alerte à
                                </th>
                                <th
                                    class="min-w-[170px] px-4 py-3 text-left font-medium"
                                >
                                    État
                                </th>
                                <th
                                    class="min-w-[220px] px-4 py-3 text-left font-medium"
                                >
                                    Dernier mouvement
                                </th>
                                <th
                                    class="min-w-[130px] px-4 py-3 text-right font-medium"
                                >
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
                                <td
                                    class="max-w-[280px] px-4 py-3 whitespace-normal"
                                >
                                    <Link
                                        :href="
                                            '/backoffice/produits/' +
                                            row.produit_id
                                        "
                                        class="line-clamp-2 hover:text-primary hover:underline"
                                        :title="row.produit_nom"
                                    >
                                        {{ row.produit_nom }}
                                    </Link>
                                    <div
                                        class="mt-0.5 text-xs text-muted-foreground"
                                    >
                                        <span v-if="row.variante_libelle">{{
                                            row.variante_libelle
                                        }}</span>
                                        <span
                                            v-if="
                                                row.variante_libelle && row.sku
                                            "
                                        >
                                            ·
                                        </span>
                                        <span
                                            v-if="row.sku"
                                            data-testid="stock-row-sku"
                                            class="font-mono"
                                            >SKU {{ row.sku }}</span
                                        >
                                    </div>
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
                                <td class="px-4 py-3 text-right tabular-nums">
                                    {{ formatNombre(row.qte_physique) }}
                                </td>
                                <td
                                    class="px-4 py-3 text-right tabular-nums"
                                    :class="
                                        row.qte_engagee > 0
                                            ? 'text-amber-700 dark:text-amber-400'
                                            : 'text-muted-foreground'
                                    "
                                >
                                    {{ formatNombre(row.qte_engagee) }}
                                </td>
                                <td
                                    class="px-4 py-3 text-right text-muted-foreground tabular-nums"
                                >
                                    {{ formatNombre(row.qte_bloquee) }}
                                </td>
                                <td
                                    class="px-4 py-3 text-right text-base font-semibold tabular-nums"
                                >
                                    {{ formatNombre(row.qte_disponible) }}
                                </td>
                                <td
                                    class="px-4 py-3 text-right text-muted-foreground tabular-nums"
                                >
                                    {{ formatNombre(row.qte_entrante) }}
                                </td>
                                <td
                                    class="px-4 py-3 text-right text-muted-foreground tabular-nums"
                                >
                                    {{
                                        row.disponible_sur_site
                                            ? formatNombre(row.seuil_effectif)
                                            : '—'
                                    }}
                                </td>
                                <td class="px-4 py-3">
                                    <StatusDot
                                        v-if="row.disponible_sur_site"
                                        :status="row.statut"
                                        :label="row.statut_label"
                                    />
                                    <StatusDot
                                        v-else
                                        label="Non disponible"
                                        dot-class="bg-zinc-400 dark:bg-zinc-500"
                                    />
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
                                                mouvementSigneLabel(
                                                    row.dernier_mouvement,
                                                )
                                            }}
                                        </div>
                                        <div
                                            v-if="
                                                row.dernier_mouvement
                                                    .motif_label
                                            "
                                            class="max-w-[220px] truncate text-xs text-muted-foreground"
                                            :title="
                                                row.dernier_mouvement
                                                    .motif_label
                                            "
                                        >
                                            {{
                                                row.dernier_mouvement
                                                    .motif_label
                                            }}
                                        </div>
                                        <div
                                            class="text-xs whitespace-nowrap text-muted-foreground"
                                        >
                                            {{ row.dernier_mouvement.date }}
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
                                <td colspan="11" class="px-6 py-16 text-center">
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

            <!-- ── Mobile : cartes empilées, jamais de débordement horizontal ──── -->
            <MobileStockList
                ref="mobileStockList"
                :stocks="stocks.data"
                @historique="ouvrirHistorique"
                @ajuster="ouvrirAjustement"
            />

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
                            class="inline-flex h-11 min-w-11 items-center justify-center rounded-md border px-2 text-sm hover:bg-muted sm:h-8 sm:min-w-8"
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
                            class="inline-flex h-11 min-w-11 items-center justify-center rounded-md border px-2 text-sm opacity-40 sm:h-8 sm:min-w-8"
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
            @after-hide="returnToStockDetails"
            :can-augmenter="can_augmenter_stock"
            :can-diminuer="can_diminuer_stock"
            :variante-stocks="[
                {
                    variante_id: selectedStock.variante_id,
                    site_id: selectedStock.site_id,
                    qte_stock: selectedStock.qte_physique,
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
            @after-hide="returnToStockDetails"
        />
    </AppLayout>
</template>

<style scoped>
@media (max-width: 639px) {
    /* Vues et filtres sur une ligne, puis la vue active : une seule instance
       de DataFilters conserve la synchronisation avec l'URL et le serveur. */
    .stock-actions :deep(> .contents > div:first-of-type) {
        display: contents;
    }

    .stock-actions :deep(button) {
        min-height: 44px;
    }

    .stock-actions :deep(> .contents > div:first-of-type > span) {
        order: 1;
        width: 100%;
        justify-content: space-between;
    }

    .stock-actions :deep(> .contents > div:first-of-type > span > span) {
        max-width: calc(100% - 44px);
    }
}
</style>
