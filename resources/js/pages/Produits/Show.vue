<script setup lang="ts">
import { Button } from '@/components/ui/button';
import StatusDot from '@/components/StatusDot.vue';
import { usePermissions } from '@/composables/usePermissions';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import {
    AlertTriangle,
    ArrowLeft,
    Package,
    Pencil,
    Tag,
    Layers,
    TrendingDown,
    ShoppingCart,
    Factory,
    Warehouse,
} from 'lucide-vue-next';

interface Produit {
    id: number;
    nom: string;
    code_interne: string | null;
    code_fournisseur: string | null;
    image_url: string | null;
    type: string | null;
    type_label: string | null;
    statut: string;
    statut_label: string;
    prix_usine: number | null;
    prix_vente: number | null;
    prix_achat: number | null;
    cout: number | null;
    qte_stock: number | null;
    seuil_alerte_stock: number | null;
    description: string | null;
    is_critique: boolean;
    in_stock: boolean;
    is_low_stock: boolean;
    has_stock: boolean;
    created_at: string | null;
    updated_at: string | null;
}

const props = defineProps<{ produit: Produit }>();

const { can } = usePermissions();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: '/dashboard' },
    { title: 'Produits', href: '/produits' },
    { title: props.produit.nom, href: '#' },
];

function formatPrice(val: number | null): string {
    if (val === null || val === undefined) return '—';
    return new Intl.NumberFormat('fr-FR').format(val) + ' GNF';
}

function formatDate(iso: string | null): string {
    if (!iso) return '—';
    return new Intl.DateTimeFormat('fr-FR', { dateStyle: 'long', timeStyle: 'short' }).format(new Date(iso));
}

function stockColorClass(produit: Produit): string {
    if (!produit.has_stock) return 'text-muted-foreground';
    if (produit.qte_stock !== null && produit.qte_stock <= 0) return 'text-destructive';
    if (produit.is_low_stock) return 'text-amber-600';
    return 'text-emerald-600';
}
</script>

<template>
    <Head :title="produit.nom" />

    <AppLayout :breadcrumbs="breadcrumbs" :hide-mobile-header="true">

        <!-- ─── Header mobile ─── -->
        <div class="sticky top-0 z-20 border-b border-border/60 bg-background/95 backdrop-blur-sm sm:hidden">
            <div class="relative flex items-center justify-center px-4 py-3">
                <Link
                    href="/produits"
                    class="absolute left-4 inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-muted text-muted-foreground transition-transform active:scale-95"
                >
                    <ArrowLeft class="h-4 w-4" />
                </Link>
                <div class="text-center">
                    <h1 class="text-[17px] font-semibold leading-tight">Détail produit</h1>
                    <p class="truncate text-[11px] text-muted-foreground">{{ produit.nom }}</p>
                </div>
                <Link
                    v-if="can('produits.update')"
                    :href="`/produits/${produit.id}/edit`"
                    class="absolute right-4 inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-primary text-primary-foreground transition-transform active:scale-95"
                >
                    <Pencil class="h-4 w-4" />
                </Link>
            </div>
        </div>

        <div class="mx-auto max-w-4xl p-4 sm:p-6 space-y-6">

            <!-- ─── Header desktop ─── -->
            <div class="hidden sm:flex items-start justify-between">
                <div class="flex items-center gap-3">
                    <Link href="/produits">
                        <Button variant="ghost" size="icon" class="h-9 w-9">
                            <ArrowLeft class="h-4 w-4" />
                        </Button>
                    </Link>
                    <div>
                        <h1 class="text-2xl font-semibold tracking-tight">{{ produit.nom }}</h1>
                        <p class="mt-0.5 text-sm text-muted-foreground font-mono">{{ produit.code_interne || '—' }}</p>
                    </div>
                </div>
                <Link v-if="can('produits.update')" :href="`/produits/${produit.id}/edit`">
                    <Button>
                        <Pencil class="mr-2 h-4 w-4" />
                        Modifier
                    </Button>
                </Link>
            </div>

            <!-- ─── Image + infos principales ─── -->
            <div class="flex gap-5 rounded-xl border bg-card p-5">
                <!-- Image -->
                <div class="h-24 w-24 sm:h-32 sm:w-32 shrink-0 overflow-hidden rounded-xl border bg-muted">
                    <img v-if="produit.image_url" :src="produit.image_url" :alt="produit.nom" class="h-full w-full object-cover" />
                    <div v-else class="flex h-full w-full items-center justify-center">
                        <Package class="h-10 w-10 text-muted-foreground/30" />
                    </div>
                </div>

                <!-- Infos -->
                <div class="flex flex-1 flex-col justify-between gap-3 min-w-0">
                    <!-- Nom + critique (mobile seulement) -->
                    <div class="sm:hidden">
                        <div class="flex items-center gap-1.5">
                            <span class="text-lg font-semibold leading-tight">{{ produit.nom }}</span>
                            <AlertTriangle v-if="produit.is_critique" class="h-4 w-4 shrink-0 text-amber-500" />
                        </div>
                        <span class="font-mono text-xs text-muted-foreground">{{ produit.code_interne || '—' }}</span>
                    </div>

                    <!-- Badges -->
                    <div class="flex flex-wrap items-center gap-2">
                        <StatusDot
                            :label="produit.statut_label"
                            :dot-class="
                                produit.statut === 'actif'   ? 'bg-emerald-500' :
                                produit.statut === 'inactif' ? 'bg-zinc-400 dark:bg-zinc-500' :
                                'bg-orange-400'
                            "
                        />
                        <span class="inline-flex items-center gap-1 rounded-full border px-2.5 py-0.5 text-xs font-medium text-muted-foreground">
                            <Layers class="h-3 w-3" />
                            {{ produit.type_label || '—' }}
                        </span>
                        <span v-if="produit.is_critique" class="inline-flex items-center gap-1 rounded-full bg-amber-100 px-2.5 py-0.5 text-xs font-medium text-amber-700 dark:bg-amber-950/30 dark:text-amber-400">
                            <AlertTriangle class="h-3 w-3" />
                            Critique
                        </span>
                    </div>

                    <!-- Codes -->
                    <div class="grid grid-cols-2 gap-x-6 gap-y-1 text-sm">
                        <div>
                            <span class="text-xs text-muted-foreground">Code interne</span>
                            <p class="font-mono font-semibold">{{ produit.code_interne || '—' }}</p>
                        </div>
                        <div>
                            <span class="text-xs text-muted-foreground">Code fournisseur</span>
                            <p class="font-mono font-semibold">{{ produit.code_fournisseur || '—' }}</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ─── Stock ─── -->
            <div v-if="produit.has_stock" class="rounded-xl border bg-card p-5">
                <h2 class="mb-4 text-sm font-semibold uppercase tracking-wide text-muted-foreground flex items-center gap-2">
                    <Warehouse class="h-4 w-4" />
                    Stock
                </h2>
                <div class="grid grid-cols-2 sm:grid-cols-3 gap-4">
                    <div class="rounded-lg bg-muted/50 p-4 text-center">
                        <p class="text-xs text-muted-foreground mb-1">Quantité actuelle</p>
                        <p class="text-3xl font-bold tabular-nums" :class="stockColorClass(produit)">
                            {{ produit.qte_stock ?? 0 }}
                        </p>
                        <div v-if="produit.qte_stock !== null && produit.qte_stock <= 0" class="mt-1 flex items-center justify-center gap-1 text-xs text-destructive">
                            <AlertTriangle class="h-3 w-3" /> Rupture
                        </div>
                        <div v-else-if="produit.is_low_stock" class="mt-1 flex items-center justify-center gap-1 text-xs text-amber-600">
                            <AlertTriangle class="h-3 w-3" /> Stock faible
                        </div>
                    </div>
                    <div class="rounded-lg bg-muted/50 p-4 text-center">
                        <p class="text-xs text-muted-foreground mb-1">Seuil d'alerte</p>
                        <p class="text-3xl font-bold tabular-nums text-foreground">{{ produit.seuil_alerte_stock ?? '—' }}</p>
                    </div>
                </div>
            </div>

            <!-- ─── Prix ─── -->
            <div class="rounded-xl border bg-card p-5">
                <h2 class="mb-4 text-sm font-semibold uppercase tracking-wide text-muted-foreground flex items-center gap-2">
                    <Tag class="h-4 w-4" />
                    Tarification
                </h2>
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
                    <div v-if="produit.prix_vente !== null" class="rounded-lg bg-muted/50 p-4">
                        <div class="flex items-center gap-1.5 mb-1">
                            <ShoppingCart class="h-3.5 w-3.5 text-muted-foreground" />
                            <span class="text-xs text-muted-foreground">Prix de vente</span>
                        </div>
                        <p class="text-base font-semibold">{{ formatPrice(produit.prix_vente) }}</p>
                    </div>
                    <div v-if="produit.prix_achat !== null" class="rounded-lg bg-muted/50 p-4">
                        <div class="flex items-center gap-1.5 mb-1">
                            <TrendingDown class="h-3.5 w-3.5 text-muted-foreground" />
                            <span class="text-xs text-muted-foreground">Prix d'achat</span>
                        </div>
                        <p class="text-base font-semibold">{{ formatPrice(produit.prix_achat) }}</p>
                    </div>
                    <div v-if="produit.prix_usine !== null" class="rounded-lg bg-muted/50 p-4">
                        <div class="flex items-center gap-1.5 mb-1">
                            <Factory class="h-3.5 w-3.5 text-muted-foreground" />
                            <span class="text-xs text-muted-foreground">Prix usine</span>
                        </div>
                        <p class="text-base font-semibold">{{ formatPrice(produit.prix_usine) }}</p>
                    </div>
                    <div v-if="produit.cout !== null" class="rounded-lg bg-muted/50 p-4">
                        <div class="flex items-center gap-1.5 mb-1">
                            <span class="text-xs text-muted-foreground">Coût</span>
                        </div>
                        <p class="text-base font-semibold">{{ formatPrice(produit.cout) }}</p>
                    </div>
                    <div v-if="produit.prix_vente === null && produit.prix_achat === null && produit.prix_usine === null && produit.cout === null"
                        class="col-span-2 sm:col-span-4 text-sm text-muted-foreground text-center py-4">
                        Aucun tarif renseigné
                    </div>
                </div>
            </div>

            <!-- ─── Description ─── -->
            <div v-if="produit.description" class="rounded-xl border bg-card p-5">
                <h2 class="mb-3 text-sm font-semibold uppercase tracking-wide text-muted-foreground">Description</h2>
                <p class="text-sm leading-relaxed whitespace-pre-wrap text-foreground/80">{{ produit.description }}</p>
            </div>

            <!-- ─── Méta ─── -->
            <div class="rounded-xl border bg-card p-5">
                <h2 class="mb-4 text-sm font-semibold uppercase tracking-wide text-muted-foreground">Informations</h2>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
                    <div>
                        <span class="text-xs text-muted-foreground">Créé le</span>
                        <p class="font-medium">{{ formatDate(produit.created_at) }}</p>
                    </div>
                    <div>
                        <span class="text-xs text-muted-foreground">Mis à jour le</span>
                        <p class="font-medium">{{ formatDate(produit.updated_at) }}</p>
                    </div>
                </div>
            </div>

        </div>

    </AppLayout>
</template>
