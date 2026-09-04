<script setup lang="ts">
import StatusDot from '@/components/StatusDot.vue';
import { Button } from '@/components/ui/button';
import { usePermissions } from '@/composables/usePermissions';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import {
    ArrowLeft,
    History,
    Image,
    Layers,
    Package,
    Pencil,
    Sliders,
    Tag,
} from 'lucide-vue-next';
import { computed, ref } from 'vue';
import AjusterStockModal from './partials/AjusterStockModal.vue';
import GalerieMedias from './partials/GalerieMedias.vue';
import HistoriqueModal from './partials/HistoriqueModal.vue';
import VarianteEditModal from './partials/VarianteEditModal.vue';
import VariantesGroupees from './partials/VariantesGroupees.vue';

interface SiteStock {
    site_id: string;
    site_code: string | null;
    site_nom: string | null;
    qte_stock: number;
    seuil_effectif: number;
    disponible_sur_site: boolean;
    alerte_active: boolean;
    statut: 'disponible' | 'stock_faible' | 'rupture' | 'stock_negatif';
    statut_label: string;
    updated_at: string | null;
}

interface VarianteStockDetail {
    variante_id: string;
    variante_libelle: string;
    site_id: string;
    site_code: string | null;
    site_nom: string | null;
    qte_stock: number;
    seuil_effectif: number;
    disponible_sur_site: boolean;
    alerte_active: boolean;
    statut: 'disponible' | 'stock_faible' | 'rupture' | 'stock_negatif';
    statut_label: string;
}

interface VarianteOption {
    option: string;
    valeur: string;
}

interface Variante {
    id: string;
    libelle: string;
    sku: string | null;
    code_barres: string | null;
    prix_usine: number | null;
    prix_usine_tricycle: number | null;
    prix_vente: number | null;
    prix_achat: number | null;
    cout: number | null;
    is_default: boolean;
    is_active: boolean;
    options: VarianteOption[];
    media_id: string | null;
    image_url: string | null;
}

interface Media {
    id: string;
    url: string;
    thumb_url: string | null;
    is_primary: boolean;
    position: number;
}

interface Produit {
    id: string;
    nom: string;
    categorie: {
        id: string;
        nom: string;
    } | null;
    fournisseur: {
        id: string;
        nom_complet: string;
        phone: string | null;
    } | null;
    sku: string | null;
    code_barres: string | null;
    image_url: string | null;
    produit_type_id: string | null;
    type_nom: string | null;
    prix_usine_requis: boolean;
    achetable: boolean;
    vendable: boolean;
    statut: string;
    statut_label: string;
    prix_usine: number | null;
    prix_usine_tricycle: number | null;
    prix_externe: number | null;
    prix_revendeur: number | null;
    prix_distributeur: number | null;
    prix_vente: number | null;
    prix_achat: number | null;
    cout: number | null;
    qte_stock: number | null;
    nombre_sites_alerte_active: number;
    nombre_sites_stock: number;
    description: string | null;
    in_stock: boolean;
    is_low_stock: boolean;
    is_out_of_stock: boolean;
    nombre_alertes_stock: number;
    has_stock: boolean;
    created_at: string | null;
    updated_at: string | null;
    stocks_par_site: SiteStock[];
    variante_stocks_detail: VarianteStockDetail[];
    variantes: Variante[];
    medias: Media[];
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
    created_at: string | null;
    createur_nom: string | null;
    is_initial?: boolean;
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

interface Site {
    id: string;
    nom: string;
    code: string;
}

interface VarianteStockEntry {
    variante_id: string;
    site_id: string;
    qte_stock: number;
}

const props = defineProps<{
    produit: Produit;
    mouvements: StockMouvement[];
    motifs_disponibles: MotifOption[];
    historiques: AuditEntry[];
    can_ajuster_stock: boolean;
    can_augmenter_stock: boolean;
    can_diminuer_stock: boolean;
    sites_autorises: Site[];
    variante_stocks: VarianteStockEntry[];
    limites: { max_photos_produit: number };
}>();

const { can } = usePermissions();

const showStockModal = ref(false);
const showHistoriqueModal = ref(false);
const showVarianteModal = ref(false);
const varianteEnEdition = ref<Variante | null>(null);
const prixUsineRequis = computed(() => props.produit.prix_usine_requis);
const prixAchatApplicable = computed(() => props.produit.achetable);
const prixVenteApplicable = computed(() => props.produit.vendable);
const valeurStockVendable = computed(() => {
    if (!props.produit.vendable || props.produit.prix_vente === null) {
        return null;
    }

    return (props.produit.qte_stock ?? 0) * props.produit.prix_vente;
});

function editerVariante(variante: Variante) {
    varianteEnEdition.value = variante;
    showVarianteModal.value = true;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/backoffice/dashboard' },
    { title: 'Produits', href: '/backoffice/produits' },
    { title: props.produit.nom, href: '#' },
];

function formatPrice(val: number | null): string {
    if (val === null || val === undefined) return '—';
    const montant = new Intl.NumberFormat('fr-FR', {
        useGrouping: true,
        maximumFractionDigits: 0,
    })
        .format(val)
        .replace(/\u202f/g, '\u00a0');

    return `${montant}\u00a0GNF`;
}

function formatQte(val: number | null | undefined): string {
    if (val === null || val === undefined) return '—';
    return new Intl.NumberFormat('fr-FR').format(val);
}

function formatDate(iso: string | null): string {
    if (!iso) return '—';
    return new Intl.DateTimeFormat('fr-FR', {
        dateStyle: 'long',
        timeStyle: 'short',
    }).format(new Date(iso));
}

function formatDateShort(iso: string | null): string {
    if (!iso) return '—';
    return new Intl.DateTimeFormat('fr-FR', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    }).format(new Date(iso));
}

function stockColorClass(produit: Produit): string {
    if (!produit.has_stock) return 'text-muted-foreground';
    if (produit.is_out_of_stock) return 'text-destructive';
    if (produit.is_low_stock) return 'text-amber-600';
    return 'text-emerald-600';
}

function siteStockColor(s: SiteStock): string {
    if (!s.disponible_sur_site) return 'text-muted-foreground';
    if (s.statut === 'rupture' || s.statut === 'stock_negatif')
        return 'text-destructive';
    if (s.statut === 'stock_faible') return 'text-amber-600';
    return 'text-emerald-600';
}

// Format mouvements so the date field matches what HistoriqueModal expects (string)
const ajustements = props.mouvements.map((m) => ({
    ...m,
    created_at: m.created_at
        ? new Intl.DateTimeFormat('fr-FR', {
              day: '2-digit',
              month: '2-digit',
              year: 'numeric',
              hour: '2-digit',
              minute: '2-digit',
          }).format(new Date(m.created_at))
        : '—',
}));
</script>

<template>
    <Head :title="produit.nom" />

    <AppLayout :breadcrumbs="breadcrumbs" :hide-mobile-header="true">
        <!-- ─── Header mobile ─── -->
        <div
            class="sticky top-0 z-20 border-b border-border/60 bg-background/95 backdrop-blur-sm sm:hidden"
        >
            <div class="relative flex items-center justify-center px-4 py-3">
                <Link
                    href="/backoffice/produits"
                    class="absolute left-4 inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-muted text-muted-foreground transition-transform active:scale-95"
                >
                    <ArrowLeft class="h-4 w-4" />
                </Link>
                <div class="text-center">
                    <h1 class="text-[17px] leading-tight font-semibold">
                        Détail produit
                    </h1>
                    <p class="truncate text-[11px] text-muted-foreground">
                        {{ produit.nom }}
                    </p>
                </div>
                <Link
                    v-if="can('produits.update')"
                    :href="`/backoffice/produits/${produit.id}/edit`"
                    class="absolute right-4 inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-primary text-primary-foreground transition-transform active:scale-95"
                >
                    <Pencil class="h-4 w-4" />
                </Link>
            </div>
        </div>

        <div
            class="mx-auto w-full max-w-7xl space-y-4 px-4 py-4 sm:space-y-5 sm:px-6 sm:py-6"
        >
            <!-- ─── En-tête inspiré du Checkout Form Apollo ─── -->
            <section
                class="relative overflow-hidden rounded-xl border border-border/60 bg-muted/25 px-4 py-5 sm:rounded-2xl sm:px-8 sm:py-8 lg:min-h-[220px] lg:pr-[25rem]"
            >
                <h1
                    class="max-w-3xl text-[1.75rem] leading-tight font-semibold tracking-tight sm:text-4xl"
                >
                    {{ produit.nom }}
                </h1>
                <p class="mt-2 text-sm text-muted-foreground">
                    Référence
                    <span class="ml-1 font-mono font-semibold text-emerald-600">
                        {{ produit.sku || '—' }}
                    </span>
                </p>

                <div
                    class="mt-5 grid grid-cols-2 gap-2 sm:mt-6 sm:flex sm:flex-wrap sm:items-center"
                >
                    <Button
                        variant="outline"
                        size="sm"
                        class="h-9 w-full rounded-lg bg-background px-3 sm:w-auto sm:px-3.5"
                        @click="showHistoriqueModal = true"
                    >
                        <History class="mr-1.5 h-4 w-4" />
                        Historique
                    </Button>
                    <Button
                        v-if="can_ajuster_stock && produit.has_stock"
                        variant="outline"
                        size="sm"
                        class="h-9 w-full rounded-lg bg-background px-3 sm:w-auto sm:px-3.5"
                        @click="showStockModal = true"
                    >
                        <Sliders class="mr-1.5 h-4 w-4" />
                        Ajuster le stock
                    </Button>
                    <Link
                        v-if="
                            can('produits.update') &&
                            produit.variantes.length > 1
                        "
                        :href="`/backoffice/produits/${produit.id}/variantes`"
                        class="col-span-2 sm:w-auto"
                    >
                        <Button
                            variant="outline"
                            size="sm"
                            class="h-9 w-full rounded-lg bg-background px-3.5 sm:w-auto"
                        >
                            <Layers class="mr-1.5 h-4 w-4" />
                            Gérer les variantes
                        </Button>
                    </Link>
                    <Link
                        v-if="can('produits.update')"
                        :href="`/backoffice/produits/${produit.id}/edit`"
                        class="hidden sm:block"
                    >
                        <Button size="sm" class="h-9 rounded-lg px-4">
                            <Pencil class="mr-1.5 h-4 w-4" />
                            Modifier
                        </Button>
                    </Link>
                </div>
            </section>

            <div
                class="grid items-start gap-4 sm:gap-6 lg:grid-cols-[minmax(0,1fr)_22rem] lg:gap-8"
            >
                <!-- ─── Panneau produit, à droite comme dans Apollo ─── -->
                <aside
                    class="relative z-10 order-first lg:order-last lg:-mt-64"
                >
                    <section
                        class="overflow-hidden rounded-xl border border-border/60 bg-card p-4 shadow-none sm:rounded-[1.5rem] sm:p-5 sm:shadow-xl sm:shadow-black/5"
                    >
                        <div
                            class="relative aspect-[4/3] overflow-hidden rounded-lg border border-border/60 bg-[#d6d4d4] sm:rounded-2xl"
                        >
                            <img
                                v-if="produit.image_url"
                                :src="produit.image_url"
                                :alt="produit.nom"
                                class="relative z-10 h-full w-full object-contain p-2"
                            />
                            <div
                                v-else
                                class="flex h-full w-full flex-col items-center justify-center text-muted-foreground"
                            >
                                <Package class="h-12 w-12 opacity-25" />
                                <span class="mt-2 text-xs"
                                    >Aucune photo principale</span
                                >
                            </div>
                        </div>

                        <div class="mt-5 divide-y divide-border/60">
                            <div
                                class="flex items-center justify-between gap-4 py-3"
                            >
                                <span class="text-sm text-muted-foreground"
                                    >Statut</span
                                >
                                <StatusDot
                                    :status="produit.statut"
                                    :label="produit.statut_label"
                                    size="sm"
                                />
                            </div>
                            <div
                                class="flex items-center justify-between gap-4 py-3"
                            >
                                <span class="text-sm text-muted-foreground"
                                    >Catégorie</span
                                >
                                <span class="text-right text-sm font-medium">
                                    {{ produit.categorie?.nom || '—' }}
                                </span>
                            </div>
                            <div
                                class="flex items-center justify-between gap-4 py-3"
                            >
                                <span class="text-sm text-muted-foreground"
                                    >Type</span
                                >
                                <span class="text-right text-sm font-medium">
                                    {{ produit.type_nom || '—' }}
                                </span>
                            </div>
                            <div
                                class="flex items-center justify-between gap-4 py-3"
                            >
                                <span class="text-sm text-muted-foreground"
                                    >Code-barres</span
                                >
                                <span
                                    class="text-right font-mono text-sm font-medium"
                                >
                                    {{ produit.code_barres || '—' }}
                                </span>
                            </div>
                            <div
                                class="flex items-start justify-between gap-4 py-3"
                            >
                                <span class="text-sm text-muted-foreground"
                                    >Fournisseur</span
                                >
                                <span class="text-right text-sm font-medium">
                                    {{
                                        produit.fournisseur?.nom_complet || '—'
                                    }}
                                    <small
                                        v-if="produit.fournisseur?.phone"
                                        class="mt-0.5 block font-normal text-muted-foreground"
                                    >
                                        {{ produit.fournisseur.phone }}
                                    </small>
                                </span>
                            </div>
                        </div>

                        <div
                            v-if="valeurStockVendable !== null"
                            class="mt-5 rounded-xl bg-primary px-5 py-4 text-center text-primary-foreground"
                        >
                            <p class="text-xs font-medium opacity-85">
                                Valeur du stock vendable
                            </p>
                            <p
                                class="mt-1.5 text-xl leading-none font-bold tracking-tight whitespace-nowrap tabular-nums sm:text-2xl"
                            >
                                {{ formatPrice(valeurStockVendable) }}
                            </p>
                        </div>
                    </section>
                </aside>

                <main class="order-last min-w-0 space-y-5 lg:order-first">
                    <!-- ─── Stock par agence ─── -->
                    <div
                        v-if="
                            produit.has_stock &&
                            produit.stocks_par_site.length > 0
                        "
                        class="overflow-hidden rounded-xl border border-border/70 bg-card p-4 shadow-none sm:rounded-2xl sm:p-6 sm:shadow-sm"
                    >
                        <div
                            class="mb-5 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between"
                        >
                            <div>
                                <h2 class="text-sm font-semibold">
                                    Stock par agence
                                </h2>
                                <p class="mt-1 text-xs text-muted-foreground">
                                    Disponibilité et seuil pour chaque agence
                                </p>
                            </div>
                            <div v-if="produit.has_stock" class="sm:text-right">
                                <p
                                    class="text-2xl font-semibold tracking-tight tabular-nums"
                                    :class="stockColorClass(produit)"
                                >
                                    {{ formatQte(produit.qte_stock ?? 0) }}
                                    <span
                                        class="text-sm font-medium text-muted-foreground"
                                        >unités</span
                                    >
                                </p>
                                <p class="mt-1 text-xs text-muted-foreground">
                                    {{ produit.nombre_sites_stock }}
                                    {{
                                        produit.nombre_sites_stock > 1
                                            ? 'agences'
                                            : 'agence'
                                    }}
                                    ·
                                    <span
                                        :class="{
                                            'font-medium text-amber-600':
                                                produit.nombre_alertes_stock >
                                                0,
                                        }"
                                    >
                                        {{ produit.nombre_alertes_stock }}
                                        {{
                                            produit.nombre_alertes_stock > 1
                                                ? 'alertes'
                                                : 'alerte'
                                        }}
                                    </span>
                                </p>
                            </div>
                        </div>
                        <div
                            class="overflow-x-auto rounded-xl border border-border/60"
                        >
                            <table class="w-full text-sm">
                                <thead class="bg-muted/30">
                                    <tr
                                        class="border-b text-xs text-muted-foreground"
                                    >
                                        <th
                                            class="px-4 py-3 text-left font-medium"
                                        >
                                            Agence
                                        </th>
                                        <th
                                            class="px-4 py-3 text-right font-medium"
                                        >
                                            Stock
                                        </th>
                                        <th
                                            class="px-4 py-3 text-left font-medium"
                                        >
                                            État
                                        </th>
                                        <th
                                            class="px-4 py-3 text-right font-medium"
                                        >
                                            Seuil
                                        </th>
                                        <th
                                            class="px-4 py-3 text-left font-medium"
                                        >
                                            Dernière mise à jour
                                        </th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-border/50">
                                    <tr
                                        v-for="s in produit.stocks_par_site"
                                        :key="s.site_id"
                                        class="transition-colors hover:bg-muted/20"
                                    >
                                        <td class="px-4 py-3">
                                            <div
                                                class="flex items-center gap-2"
                                            >
                                                <span
                                                    class="rounded bg-muted px-1.5 py-0.5 font-mono text-xs font-semibold text-muted-foreground"
                                                >
                                                    {{ s.site_code ?? '?' }}
                                                </span>
                                                <span
                                                    class="text-sm font-medium"
                                                    >{{
                                                        s.site_nom ?? '—'
                                                    }}</span
                                                >
                                            </div>
                                        </td>
                                        <td
                                            class="px-4 py-3 text-right font-semibold tabular-nums"
                                            :class="siteStockColor(s)"
                                        >
                                            {{ formatQte(s.qte_stock) }}
                                        </td>
                                        <td class="px-4 py-3">
                                            <StatusDot
                                                v-if="s.disponible_sur_site"
                                                :status="s.statut"
                                                :label="s.statut_label"
                                            />
                                            <StatusDot
                                                v-else
                                                label="Non disponible"
                                                dot-class="bg-zinc-400 dark:bg-zinc-500"
                                            />
                                        </td>
                                        <td
                                            class="px-4 py-3 text-right text-muted-foreground tabular-nums"
                                        >
                                            {{
                                                s.disponible_sur_site
                                                    ? formatQte(
                                                          s.seuil_effectif,
                                                      )
                                                    : '—'
                                            }}
                                        </td>
                                        <td
                                            class="px-4 py-3 text-xs whitespace-nowrap text-muted-foreground"
                                        >
                                            {{ formatDateShort(s.updated_at) }}
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- ─── Détail par variante × site ─── -->
                    <!-- Un stock élevé sur une variante/un site ne doit jamais masquer une alerte
                 locale ailleurs (cf. décision produit) : ce détail montre précisément où se
                 trouve le problème plutôt que le seul agrégat par agence ci-dessus. -->
                    <div
                        v-if="
                            produit.has_stock &&
                            produit.variantes.length > 1 &&
                            produit.variante_stocks_detail.length > 0
                        "
                        class="overflow-hidden rounded-xl border border-border/70 bg-card p-4 shadow-none sm:rounded-2xl sm:p-6 sm:shadow-sm"
                    >
                        <h2
                            class="mb-4 flex items-center gap-2 text-sm font-semibold"
                        >
                            <Layers class="h-4 w-4 text-muted-foreground" />
                            Détail par variante
                        </h2>
                        <div class="overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead>
                                    <tr
                                        class="border-b text-xs text-muted-foreground"
                                    >
                                        <th
                                            class="pr-4 pb-2 text-left font-medium"
                                        >
                                            Variante
                                        </th>
                                        <th
                                            class="pr-4 pb-2 text-left font-medium"
                                        >
                                            Site
                                        </th>
                                        <th
                                            class="pr-4 pb-2 text-right font-medium"
                                        >
                                            Stock
                                        </th>
                                        <th class="pb-2 text-left font-medium">
                                            État
                                        </th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-border/50">
                                    <tr
                                        v-for="d in produit.variante_stocks_detail"
                                        :key="`${d.variante_id}-${d.site_id}`"
                                    >
                                        <td class="py-2.5 pr-4 font-medium">
                                            {{
                                                d.variante_libelle ||
                                                'Par défaut'
                                            }}
                                        </td>
                                        <td
                                            class="py-2.5 pr-4 text-muted-foreground"
                                        >
                                            {{
                                                d.site_code ?? d.site_nom ?? '—'
                                            }}
                                        </td>
                                        <td
                                            class="py-2.5 pr-4 text-right font-semibold tabular-nums"
                                        >
                                            {{ formatQte(d.qte_stock) }}
                                        </td>
                                        <td class="py-2.5">
                                            <StatusDot
                                                v-if="d.disponible_sur_site"
                                                :status="d.statut"
                                                :label="d.statut_label"
                                            />
                                            <StatusDot
                                                v-else
                                                label="Non disponible"
                                                dot-class="bg-zinc-400 dark:bg-zinc-500"
                                            />
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- ─── Prix ─── -->
                    <div
                        class="overflow-hidden rounded-xl border border-border/50 bg-muted/30 p-4 sm:rounded-2xl sm:p-6"
                    >
                        <h2
                            class="mb-4 flex items-center gap-2 text-sm font-semibold"
                        >
                            <Tag class="h-4 w-4 text-muted-foreground" />
                            Tarification
                        </h2>
                        <dl
                            class="grid gap-x-10 text-sm md:grid-cols-2 [&_dd]:text-[15px] [&_dd]:tracking-tight [&_dd]:whitespace-nowrap [&>div]:border-b [&>div]:border-border/60"
                        >
                            <div
                                v-if="produit.prix_vente !== null"
                                class="flex items-center justify-between gap-6 py-3"
                            >
                                <dt class="text-muted-foreground">
                                    Prix de vente
                                </dt>
                                <dd class="font-semibold tabular-nums">
                                    {{ formatPrice(produit.prix_vente) }}
                                </dd>
                            </div>
                            <div
                                v-if="produit.prix_achat !== null"
                                class="flex items-center justify-between gap-6 py-3"
                            >
                                <dt class="text-muted-foreground">
                                    Prix d’achat
                                </dt>
                                <dd class="font-semibold tabular-nums">
                                    {{ formatPrice(produit.prix_achat) }}
                                </dd>
                            </div>
                            <div
                                v-if="produit.prix_usine !== null"
                                class="flex items-center justify-between gap-6 py-3"
                            >
                                <dt class="text-muted-foreground">
                                    Prix usine — Tous véhicules
                                </dt>
                                <dd class="font-semibold tabular-nums">
                                    {{ formatPrice(produit.prix_usine) }}
                                </dd>
                            </div>
                            <div
                                v-if="produit.prix_usine_tricycle !== null"
                                class="flex items-center justify-between gap-6 py-3"
                            >
                                <dt class="text-muted-foreground">
                                    Prix usine — Tricycle
                                </dt>
                                <dd class="font-semibold tabular-nums">
                                    {{
                                        formatPrice(produit.prix_usine_tricycle)
                                    }}
                                </dd>
                            </div>
                            <div
                                v-if="produit.prix_externe !== null"
                                class="flex items-center justify-between gap-6 py-3"
                            >
                                <dt class="text-muted-foreground">
                                    Prix externe
                                </dt>
                                <dd class="font-semibold tabular-nums">
                                    {{ formatPrice(produit.prix_externe) }}
                                </dd>
                            </div>
                            <div
                                v-if="produit.prix_distributeur !== null"
                                class="flex items-center justify-between gap-6 py-3"
                            >
                                <dt class="text-muted-foreground">
                                    Prix distributeur
                                </dt>
                                <dd class="font-semibold tabular-nums">
                                    {{ formatPrice(produit.prix_distributeur) }}
                                </dd>
                            </div>
                            <div
                                v-if="produit.prix_revendeur !== null"
                                class="flex items-center justify-between gap-6 py-3"
                            >
                                <dt class="text-muted-foreground">
                                    Prix revendeur
                                </dt>
                                <dd class="font-semibold tabular-nums">
                                    {{ formatPrice(produit.prix_revendeur) }}
                                </dd>
                            </div>
                            <div
                                v-if="produit.cout !== null"
                                class="flex items-center justify-between gap-6 py-3"
                            >
                                <dt class="text-muted-foreground">Coût</dt>
                                <dd class="font-semibold tabular-nums">
                                    {{ formatPrice(produit.cout) }}
                                </dd>
                            </div>
                            <div
                                v-if="
                                    produit.prix_vente === null &&
                                    produit.prix_achat === null &&
                                    produit.prix_usine === null &&
                                    produit.prix_externe === null &&
                                    produit.prix_revendeur === null &&
                                    produit.prix_distributeur === null &&
                                    produit.cout === null
                                "
                                class="py-6 text-center text-sm text-muted-foreground md:col-span-2"
                            >
                                Aucun tarif renseigné
                            </div>
                        </dl>
                    </div>

                    <!-- ─── Photos ─── -->
                    <div
                        class="overflow-hidden rounded-xl border border-border/70 bg-card p-4 shadow-none sm:rounded-2xl sm:p-6 sm:shadow-sm"
                    >
                        <h2
                            class="mb-4 flex items-center gap-2 text-sm font-semibold"
                        >
                            <Image class="h-4 w-4 text-muted-foreground" />
                            Photos
                        </h2>
                        <GalerieMedias
                            :produit-id="produit.id"
                            :medias="produit.medias"
                            :max-photos="limites.max_photos_produit"
                        />
                    </div>

                    <!-- ─── Variantes ─── -->
                    <div
                        v-if="produit.variantes.length > 1"
                        class="overflow-hidden rounded-xl border border-border/70 bg-card p-4 shadow-none sm:rounded-2xl sm:p-6 sm:shadow-sm"
                    >
                        <h2
                            class="mb-4 flex items-center gap-2 text-sm font-semibold"
                        >
                            <Layers class="h-4 w-4 text-muted-foreground" />
                            Variantes
                            <span
                                class="font-normal text-muted-foreground/70 normal-case"
                                >({{ produit.variantes.length }})</span
                            >
                        </h2>
                        <VariantesGroupees
                            :variantes="produit.variantes"
                            :editable="can('produits.update')"
                            :medias="produit.medias"
                            :produit-id="produit.id"
                            @edit-variante="
                                (v) =>
                                    editerVariante(
                                        produit.variantes.find(
                                            (pv) => pv.id === v.id,
                                        )!,
                                    )
                            "
                        />
                    </div>

                    <!-- ─── Informations complémentaires ─── -->
                    <div
                        class="overflow-hidden rounded-xl border border-border/70 bg-card p-4 shadow-none sm:rounded-2xl sm:p-6 sm:shadow-sm"
                    >
                        <h2 class="mb-5 text-sm font-semibold">
                            Informations complémentaires
                        </h2>
                        <div
                            class="grid gap-6"
                            :class="{
                                'lg:grid-cols-[minmax(0,1.4fr)_minmax(18rem,0.6fr)]':
                                    produit.description,
                            }"
                        >
                            <section
                                v-if="produit.description"
                                class="lg:border-r lg:border-border/60 lg:pr-8"
                            >
                                <h3
                                    class="mb-2 text-xs font-medium text-muted-foreground"
                                >
                                    Description
                                </h3>
                                <div
                                    class="prose prose-sm max-w-none text-sm leading-relaxed text-foreground/80"
                                    v-html="produit.description"
                                />
                            </section>

                            <div class="grid gap-4 text-sm sm:grid-cols-2">
                                <div>
                                    <span class="text-xs text-muted-foreground">
                                        Créé le
                                    </span>
                                    <p class="mt-1 font-medium">
                                        {{ formatDate(produit.created_at) }}
                                    </p>
                                </div>
                                <div>
                                    <span class="text-xs text-muted-foreground">
                                        Mis à jour le
                                    </span>
                                    <p class="mt-1 font-medium">
                                        {{ formatDate(produit.updated_at) }}
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                </main>
            </div>
        </div>

        <HistoriqueModal
            v-model:visible="showHistoriqueModal"
            :ajustements="ajustements"
            :modifications="historiques"
            :motif-options="motifs_disponibles"
            :title="`Historique — ${produit.nom}`"
        />
        <AjusterStockModal
            v-model:visible="showStockModal"
            :produit="produit"
            :sites-autorises="sites_autorises"
            :can-augmenter="can_augmenter_stock"
            :can-diminuer="can_diminuer_stock"
            :variante-stocks="variante_stocks"
        />
        <VarianteEditModal
            v-model:visible="showVarianteModal"
            :produit-id="produit.id"
            :variante="varianteEnEdition"
            :prix-usine-requis="prixUsineRequis"
            :prix-achat-applicable="prixAchatApplicable"
            :prix-vente-applicable="prixVenteApplicable"
            :medias="produit.medias"
        />
    </AppLayout>
</template>
