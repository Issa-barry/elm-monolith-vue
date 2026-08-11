<script setup lang="ts">
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { usePermissions } from '@/composables/usePermissions';
import { Link } from '@inertiajs/vue3';
import {
    AlertTriangle,
    Archive,
    ArrowLeft,
    Eye,
    MoreVertical,
    Package,
    Pencil,
    Plus,
    Search,
    Trash2,
} from 'lucide-vue-next';
import { computed, ref } from 'vue';

// Reprend exactement la forme de l'interface Produit de Index.vue (même objets passés tels
// quels, cf. :produits="props.produits") — les deux interfaces doivent rester structurellement
// identiques sur les champs communs, sinon vue-tsc traite les callbacks onDelete/onArchive
// comme des types "Produit" incompatibles malgré le même nom.
interface Produit {
    id: string;
    nom: string;
    sku: string | null;
    code_barres: string | null;
    image_url: string | null;
    statut: string | null;
    statut_label: string | null;
    produit_type_id: string | null;
    type_nom: string | null;
    prix_usine: number | null;
    prix_vente: number | null;
    prix_achat: number | null;
    cout: number | null;
    qte_stock: number | null;
    alerte_stock_active: boolean;
    seuil_alerte_stock: number | null;
    seuil_alerte_effectif: number;
    description: string | null;
    in_stock: boolean;
    is_low_stock: boolean;
    is_out_of_stock: boolean;
    has_stock: boolean;
    is_used: boolean;
    has_variantes: boolean;
    last_mouvement_type: 'entree' | 'sortie' | null;
    last_mouvement_quantite: number | null;
    stocks_par_site: unknown[];
}

const props = defineProps<{
    produits: Produit[];
    onDelete: (produit: Produit) => void;
    onArchive: (produit: Produit) => void;
}>();

const { can } = usePermissions();

const search = ref('');

const filteredProduits = computed(() => {
    const query = search.value.trim().toLowerCase();
    if (!query) return props.produits;
    return props.produits.filter((p) =>
        [p.nom, p.sku ?? ''].join(' ').toLowerCase().includes(query),
    );
});
</script>

<template>
    <div class="flex min-h-full flex-col bg-background">
        <!-- Header sticky style app native -->
        <div
            class="sticky top-0 z-20 border-b border-border/60 bg-background/95 backdrop-blur-sm"
        >
            <div
                class="relative flex items-center justify-center px-4 pt-4 pb-3"
            >
                <Link
                    href="/backoffice/dashboard"
                    class="absolute left-4 inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-muted text-muted-foreground transition-transform active:scale-95"
                >
                    <ArrowLeft class="h-4 w-4" />
                </Link>

                <div class="text-center">
                    <h1 class="text-[17px] leading-tight font-semibold">
                        Produits
                    </h1>
                    <p class="text-[11px] text-muted-foreground">
                        {{ filteredProduits.length }} dans le catalogue
                    </p>
                </div>

                <Link
                    v-if="can('produits.create')"
                    href="/backoffice/produits/create"
                    class="absolute right-4 shrink-0"
                >
                    <button
                        class="inline-flex items-center gap-1.5 rounded-full bg-primary px-3.5 py-2 text-xs font-semibold text-primary-foreground shadow-sm transition-transform active:scale-95"
                    >
                        <Plus class="h-3.5 w-3.5" />
                        Nouveau
                    </button>
                </Link>
            </div>

            <div class="px-4 pb-3">
                <div class="relative flex items-center">
                    <Search
                        class="pointer-events-none absolute left-3 h-4 w-4 text-muted-foreground"
                    />
                    <input
                        v-model="search"
                        type="search"
                        placeholder="Rechercher un produit..."
                        class="w-full rounded-xl border-0 bg-muted py-2.5 pr-4 pl-9 text-sm placeholder:text-muted-foreground/60 focus:ring-2 focus:ring-primary/30 focus:outline-none"
                    />
                </div>
            </div>
        </div>

        <!-- Liste -->
        <div v-if="filteredProduits.length" class="divide-y divide-border/50">
            <div
                v-for="data in filteredProduits"
                :key="data.id"
                class="flex items-center gap-3.5 px-4 py-3.5 transition-colors active:bg-muted/40"
            >
                <!-- Image -->
                <div
                    class="h-12 w-12 shrink-0 overflow-hidden rounded-xl border bg-muted shadow-sm"
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
                        <Package class="h-5 w-5 text-muted-foreground/30" />
                    </div>
                </div>

                <!-- Infos -->
                <Link
                    :href="`/backoffice/produits/${data.id}`"
                    class="min-w-0 flex-1"
                >
                    <div class="flex items-center gap-1.5">
                        <p
                            class="truncate text-[13px] leading-tight font-semibold"
                        >
                            {{ data.nom }}
                        </p>
                        <AlertTriangle
                            v-if="data.is_out_of_stock"
                            class="h-3.5 w-3.5 shrink-0 text-red-500"
                        />
                        <AlertTriangle
                            v-else-if="data.is_low_stock"
                            class="h-3.5 w-3.5 shrink-0 text-amber-500"
                        />
                    </div>
                    <div class="mt-0.5 flex items-center gap-1.5">
                        <p class="font-mono text-[11px] text-muted-foreground">
                            {{ data.sku || '—' }}
                        </p>
                        <span
                            v-if="data.type_nom"
                            class="rounded bg-muted px-1 py-0.5 text-[10px] leading-none font-medium text-muted-foreground"
                        >
                            {{ data.type_nom }}
                        </span>
                    </div>
                </Link>

                <!-- Actions -->
                <DropdownMenu>
                    <DropdownMenuTrigger as-child>
                        <button
                            class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-muted-foreground transition-colors active:bg-muted"
                        >
                            <MoreVertical class="h-4 w-4" />
                        </button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end" class="w-44">
                        <DropdownMenuItem as-child>
                            <Link
                                :href="`/backoffice/produits/${data.id}`"
                                class="flex w-full items-center gap-2"
                            >
                                <Eye class="h-4 w-4" />
                                Voir le détail
                            </Link>
                        </DropdownMenuItem>
                        <DropdownMenuSeparator v-if="can('produits.update')" />
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
                            v-if="can('produits.delete') && !data.is_used"
                            class="cursor-pointer text-destructive focus:text-destructive"
                            @click="onDelete(data)"
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
                            class="cursor-pointer text-amber-600 focus:text-amber-600"
                            @click="onArchive(data)"
                        >
                            <Archive class="h-4 w-4" />
                            Archiver
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            </div>
        </div>

        <!-- Empty state -->
        <div
            v-else
            class="flex flex-col items-center gap-4 px-6 py-16 text-center"
        >
            <div
                class="flex h-16 w-16 items-center justify-center rounded-2xl bg-muted"
            >
                <Package class="h-8 w-8 text-muted-foreground/40" />
            </div>
            <div>
                <p class="font-medium text-foreground">Aucun produit</p>
                <p class="mt-1 text-sm text-muted-foreground">
                    {{
                        search
                            ? 'Aucun résultat pour cette recherche.'
                            : 'Le catalogue est vide pour le moment.'
                    }}
                </p>
            </div>
            <Link
                v-if="can('produits.create') && !search"
                href="/backoffice/produits/create"
            >
                <button
                    class="inline-flex items-center gap-2 rounded-full bg-primary px-5 py-2.5 text-sm font-semibold text-primary-foreground shadow-sm transition-transform active:scale-95"
                >
                    <Plus class="h-4 w-4" />
                    Créer le premier produit
                </button>
            </Link>
        </div>
    </div>
</template>
